<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

// ตรวจสอบสิทธิ์ Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$admin_id   = $_SESSION['user_id'];
$fullname   = $_SESSION['fullname'];
$request_id = intval($_GET['request_id'] ?? 0);
$group_id   = intval($_GET['group_id'] ?? 0);

// 1. ดึงข้อมูลคำขอ + ข้อมูลกลุ่ม
$sql = "
    SELECT 
        r.id AS req_id,
        r.status AS req_status,
        r.note AS req_note,
        r.created_at AS req_created_at,
        g.id AS group_id,
        g.project_name,
        g.status AS group_status,
        c.course_code, c.course_name,
        u.fullname AS requester_name,
        a.fullname AS advisor_name
    FROM project_approval_requests r
    JOIN project_groups g ON r.group_id = g.id
    JOIN users u ON r.requested_by = u.id
    LEFT JOIN courses c ON g.course_id = c.id
    LEFT JOIN users a ON g.advisor_id = a.id
    WHERE r.id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $request_id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();

if (!$request) die("❌ ไม่พบข้อมูลคำขอ");

// 2. ดึงสมาชิกกลุ่ม
$mem_sql = "
    SELECT u.fullname, m.is_leader 
    FROM project_members m 
    JOIN users u ON m.student_id = u.id 
    WHERE m.group_id = ? AND m.is_confirmed = 1
";
$mem_stmt = $conn->prepare($mem_sql);
$mem_stmt->bind_param("i", $request['group_id']);
$mem_stmt->execute();
$members = $mem_stmt->get_result();

// 3. ดึงไฟล์แนบ (จากแชทกลุ่ม)
$file_sql = "
    SELECT c.file_path, c.created_at, u.fullname 
    FROM project_chat c 
    JOIN users u ON c.sender_id = u.id 
    WHERE c.group_id = ? AND c.file_path IS NOT NULL AND c.file_path != ''
    ORDER BY c.created_at DESC
";
$file_stmt = $conn->prepare($file_sql);
$file_stmt->bind_param("i", $request['group_id']);
$file_stmt->execute();
$files = $file_stmt->get_result();

// 4. Handle Action (Approve / Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $note   = trim($_POST['note'] ?? '');
    $is_processed = ($request['req_status'] != 'pending');

    if (!$is_processed) {
        if ($action === 'approve') {
            // อัปเดตคำขอ
            $u = $conn->prepare("UPDATE project_approval_requests SET status='approved', admin_id=?, note=?, updated_at=NOW() WHERE id=?");
            $u->bind_param("isi", $admin_id, $note, $request_id);
            $u->execute();

            // อัปเดตกลุ่ม
            $g = $conn->prepare("UPDATE project_groups SET status='approved' WHERE id=?");
            $g->bind_param("i", $request['group_id']);
            $g->execute();

            $msg = "✅ โครงงาน '{$request['project_name']}' ได้รับการอนุมัติแล้ว";
            $type = "approval_result";
        } elseif ($action === 'reject') {
            // อัปเดตคำขอ
            $u = $conn->prepare("UPDATE project_approval_requests SET status='rejected', admin_id=?, note=?, updated_at=NOW() WHERE id=?");
            $u->bind_param("isi", $admin_id, $note, $request_id);
            $u->execute();

            // อัปเดตกลุ่ม (Rejected)
            $g = $conn->prepare("UPDATE project_groups SET status='rejected' WHERE id=?");
            $g->bind_param("i", $request['group_id']);
            $g->execute();

            $msg = "❌ โครงงาน '{$request['project_name']}' ถูกปฏิเสธ: " . ($note ?: 'แก้ไขข้อมูล');
            $type = "approval_result";
        }

        // แจ้งเตือนนิสิตหัวหน้ากลุ่ม
        $n = $conn->prepare("INSERT INTO notifications (receiver_id, sender_id, type, group_id, message, is_read, created_at) VALUES ((SELECT requested_by FROM project_approval_requests WHERE id=?), ?, ?, ?, ?, 0, NOW())");
        $n->bind_param("iisis", $request_id, $admin_id, $type, $request['group_id'], $msg);
        $n->execute();

        // 🔥 เด้งกลับไปหน้า admin_approval_list.php
        header("Location: admin_approval_list.php?msg=$action" . "d");
        exit;
    }
}

function getCleanFileName($path) { return preg_replace('/^\d+_/', '', basename($path)); }
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ตรวจสอบคำขอ - <?= htmlspecialchars($request['project_name']) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Theme Dashboard */
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: #f4f6f9; color: #333; }
    .sidebar { width: 260px; height: 100vh; background: #1e3a8a; color: white; position: fixed; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; }
    .sidebar-header { padding: 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
    .sidebar-header h2 { margin: 0; font-size: 20px; font-weight: bold; }
    .sidebar-header p { margin: 5px 0 0; font-size: 13px; opacity: 0.8; }
    .nav-links { flex: 1; padding: 20px 0; overflow-y: auto; }
    .nav-links a { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; font-size: 15px; transition: 0.2s; border-left: 4px solid transparent; }
    .nav-links a:hover { background: rgba(255,255,255,0.1); color: white; border-left-color: #60a5fa; }
    .nav-links a.active { background: #2563eb; color: white; border-left-color: #fff; font-weight: bold; }
    .nav-links a i { width: 25px; text-align: center; margin-right: 10px; }
    .logout-btn { margin: 20px; padding: 12px; text-align: center; background: #dc2626; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.2s; }
    .logout-btn:hover { background: #b91c1c; }
    
    .main-content { margin-left: 260px; padding: 30px; }
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
    .page-header h1 { margin: 0; font-size: 24px; color: #1e3a8a; }
    .btn-back { text-decoration: none; color: #64748b; font-weight: 600; display: flex; align-items: center; gap: 5px; }
    
    .grid-container { display: grid; grid-template-columns: 2fr 1fr; gap: 25px; }
    .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #e2e8f0; margin-bottom: 25px; }
    .card-title { font-size: 16px; font-weight: bold; color: #1e3a8a; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }

    .info-row { margin-bottom: 15px; }
    .info-label { font-size: 13px; color: #64748b; font-weight: 600; display: block; margin-bottom: 4px; }
    .info-value { font-size: 15px; color: #334155; font-weight: 500; }
    .course-badge { background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 4px; font-size: 12px; }
    
    .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; text-transform: uppercase; }
    .bg-pending { background: #fffbeb; color: #b45309; border: 1px solid #fcd34d; }
    .bg-approved { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .bg-rejected { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

    .member-list { list-style: none; padding: 0; margin: 0; }
    .member-item { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
    .avatar { width: 35px; height: 35px; background: #e0f2fe; color: #0369a1; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px; }
    .leader-icon { color: #f59e0b; margin-left: 5px; font-size: 12px; }

    .file-list { list-style: none; padding: 0; }
    .file-item { display: flex; align-items: center; gap: 10px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 8px; transition: 0.2s; }
    .file-item:hover { background: #f8fafc; border-color: #cbd5e1; }
    .file-icon { font-size: 24px; color: #ef4444; }
    .file-info { flex: 1; overflow: hidden; }
    .file-name { display: block; font-size: 13px; font-weight: 600; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .file-date { font-size: 11px; color: #94a3b8; }
    .file-dl { color: #2563eb; text-decoration: none; padding: 5px; }

    .action-area { background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; }
    .txt-note { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; margin-bottom: 15px; resize: vertical; font-family: inherit; }
    .btn-group { display: flex; gap: 10px; }
    .btn { flex: 1; padding: 12px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 5px; transition: 0.2s; }
    .btn-approve { background: #10b981; color: white; }
    .btn-approve:hover { background: #059669; }
    .btn-reject { background: #ef4444; color: white; }
    .btn-reject:hover { background: #dc2626; }
    
    .processed-box { text-align: center; padding: 20px; background: #f1f5f9; border-radius: 8px; color: #64748b; }
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>🎓 ระบบนิสิต</h2>
        <p><?= htmlspecialchars($fullname) ?> <br> (Admin)</p>
    </div>
    <div class="nav-links">
        <a href="dashboard.php"><i class="fas fa-home"></i> กลับแดชบอร์ด</a>
        <a href="admin_approval_list.php" class="active"><i class="fas fa-clipboard-check"></i> อนุมัติโครงงาน</a>
        <a href="admin_chat_list.php"><i class="fas fa-comments"></i> แชททั้งหมด</a>
        <a href="list_teachers.php"><i class="fas fa-chalkboard-teacher"></i> รายชื่ออาจารย์ทั้งหมด</a>
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">
    
    <div class="page-header">
        <h1>📘 ตรวจสอบคำขออนุมัติ</h1>
        <a href="admin_approval_list.php" class="btn-back"><i class="fas fa-arrow-left"></i> ย้อนกลับ</a>
    </div>

    <div class="grid-container">
        
        <div class="left-col">
            
            <div class="card">
                <div class="card-title">
                    ข้อมูลโครงงาน
                    <span class="status-badge bg-<?= $request['req_status'] ?>"><?= ucfirst($request['req_status']) ?></span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">ชื่อโครงงาน</span>
                    <span class="info-value" style="font-size:18px;"><?= htmlspecialchars($request['project_name']) ?></span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">รายวิชา</span>
                    <span class="course-badge"><?= htmlspecialchars($request['course_code']) ?></span> <?= htmlspecialchars($request['course_name']) ?>
                </div>

                <div class="info-row">
                    <span class="info-label">อาจารย์ที่ปรึกษา</span>
                    <span class="info-value">
                        <i class="fas fa-user-tie" style="color:#64748b;"></i> 
                        <?= $request['advisor_name'] ? htmlspecialchars($request['advisor_name']) : '<span style="color:#999">- ยังไม่มี -</span>' ?>
                    </span>
                </div>
            </div>

            <div class="card">
                <div class="card-title">สมาชิกในกลุ่ม</div>
                <ul class="member-list">
                    <?php while($m = $members->fetch_assoc()): ?>
                        <li class="member-item">
                            <div class="avatar"><?= mb_substr($m['fullname'], 0, 1) ?></div>
                            <div>
                                <span style="font-weight:600; font-size:14px;"><?= htmlspecialchars($m['fullname']) ?></span>
                                <?php if($m['is_leader']): ?>
                                    <i class="fas fa-crown leader-icon" title="หัวหน้ากลุ่ม"></i>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endwhile; ?>
                </ul>
            </div>

        </div>

        <div class="right-col">
            
            <div class="card">
                <div class="card-title">📂 ไฟล์แนบ (จากแชท)</div>
                <?php if ($files->num_rows > 0): ?>
                    <ul class="file-list">
                        <?php while($f = $files->fetch_assoc()): ?>
                            <li class="file-item">
                                <i class="fas fa-file-alt file-icon"></i>
                                <div class="file-info">
                                    <span class="file-name"><?= htmlspecialchars(getCleanFileName($f['file_path'])) ?></span>
                                    <span class="file-date">โดย <?= htmlspecialchars($f['fullname']) ?></span>
                                </div>
                                <a href="<?= htmlspecialchars($f['file_path']) ?>" target="_blank" class="file-dl"><i class="fas fa-download"></i></a>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                <?php else: ?>
                    <div style="text-align:center; color:#999; font-size:13px; padding:20px;">ไม่มีไฟล์แนบ</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-title">การดำเนินการ</div>
                
                <?php if ($request['req_status'] == 'pending'): ?>
                    <form method="POST" class="action-area">
                        <label class="info-label">ความคิดเห็น / เหตุผล (ถ้ามี)</label>
                        <textarea name="note" class="txt-note" rows="3" placeholder="ระบุเหตุผล (นิสิตจะเห็นข้อความนี้)"></textarea>
                        
                        <div class="btn-group">
                            <button type="submit" name="action" value="approve" class="btn btn-approve" onclick="return confirm('ยืนยันการอนุมัติ?')">
                                <i class="fas fa-check"></i> อนุมัติ
                            </button>
                            <button type="submit" name="action" value="reject" class="btn btn-reject" onclick="return confirm('ยืนยันการปฏิเสธ?')">
                                <i class="fas fa-times"></i> ปฏิเสธ
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="processed-box">
                        <i class="fas fa-history" style="font-size:24px; margin-bottom:10px;"></i><br>
                        คำขอนี้ถูกดำเนินการแล้ว<br>
                        <small>สถานะ: <strong><?= ucfirst($request['req_status']) ?></strong></small>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div>
</div>

</body>
</html>