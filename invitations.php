<?php
session_start();
include 'db_connect.php';

// ตรวจสอบสิทธิ์นิสิต
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header("Location: login.php");
    exit;
}

$student_id = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'];
$role = $_SESSION['role'];
$msg = "";
$msg_type = "";

// 🔥 เคลียร์แจ้งเตือน (invite_group) ทันทีที่เข้ามา
$clear_notif = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE receiver_id = ? AND type = 'invite_group'");
$clear_notif->bind_param("i", $student_id);
$clear_notif->execute();

// ตรวจสิทธิ์ Project Access (สำหรับ Sidebar)
$has_project_access = false;
$q = $conn->prepare("SELECT 1 FROM enrollments WHERE student_id = ? AND status = 'approved' LIMIT 1");
$q->bind_param("i", $student_id);
$q->execute();
if ($q->get_result()->num_rows > 0) $has_project_access = true;

// นับจำนวนแจ้งเตือนสำหรับ Sidebar (คำนวณใหม่ทุกครั้ง)
$count_invite = 0;
$c_inv_q = $conn->prepare("SELECT COUNT(*) FROM project_members WHERE student_id = ? AND is_confirmed = 0");
$c_inv_q->bind_param("i", $student_id); 
$c_inv_q->execute();
$count_invite = $c_inv_q->get_result()->fetch_row()[0];

// ถ้ามีการตอบรับหรือปฏิเสธ
if (isset($_GET['action'], $_GET['group_id'])) {
    $group_id = intval($_GET['group_id']);
    $action = $_GET['action'];

    // เช็คก่อนว่ามีสิทธิ์ตอบรับไหม (ต้องมีชื่อใน project_members และ is_confirmed=0)
    $chk = $conn->prepare("SELECT id FROM project_members WHERE group_id = ? AND student_id = ? AND is_confirmed = 0");
    $chk->bind_param("ii", $group_id, $student_id);
    $chk->execute();
    
    if ($chk->get_result()->num_rows > 0) {
        if ($action == 'accept') {
            // ตรวจว่าตอนนี้มีกลุ่มอื่นอยู่แล้วหรือยัง (กันเหนียว)
            $chk_dup = $conn->prepare("SELECT id FROM project_members WHERE student_id = ? AND is_confirmed = 1");
            $chk_dup->bind_param("i", $student_id);
            $chk_dup->execute();
            
            if ($chk_dup->get_result()->num_rows > 0) {
                $msg = "⚠️ คุณมีกลุ่มอยู่แล้ว ไม่สามารถเข้าร่วมกลุ่มอื่นได้";
                $msg_type = "warning";
            } else {
                $stmt = $conn->prepare("UPDATE project_members SET is_confirmed = 1, joined_at = NOW() WHERE group_id = ? AND student_id = ?");
                $stmt->bind_param("ii", $group_id, $student_id);
                if ($stmt->execute()) {
                    $msg = "✅ ยืนยันเข้าร่วมกลุ่มเรียบร้อยแล้ว!";
                    $msg_type = "success";
                    $count_invite--; // ลดจำนวนลงเพื่อให้ sidebar อัปเดตทันที
                }
            }
        } elseif ($action == 'decline') {
            $stmt = $conn->prepare("DELETE FROM project_members WHERE group_id = ? AND student_id = ?");
            $stmt->bind_param("ii", $group_id, $student_id);
            if ($stmt->execute()) {
                $msg = "❌ ปฏิเสธคำเชิญเรียบร้อยแล้ว";
                $msg_type = "warning";
                $count_invite--;
            }
        }
    } else {
        $msg = "⚠️ ไม่พบคำเชิญ หรือคุณได้ดำเนินการไปแล้ว";
        $msg_type = "danger";
    }
}

// ดึงคำเชิญทั้งหมดที่ยังไม่ยืนยัน
$stmt = $conn->prepare("
    SELECT g.project_name, g.id AS group_id, u.fullname AS inviter,
           c.course_code, c.course_name, g.created_at
    FROM project_members m
    JOIN project_groups g ON m.group_id = g.id
    JOIN users u ON m.invited_by = u.id
    LEFT JOIN courses c ON g.course_id = c.id
    WHERE m.student_id = ? AND m.is_confirmed = 0
    ORDER BY m.joined_at DESC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>คำเชิญเข้ากลุ่ม</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Theme Dashboard */
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: #f4f6f9; color: #333; }
    
    /* Sidebar */
    .sidebar { width: 260px; height: 100vh; background: #1e3a8a; color: white; position: fixed; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; }
    .sidebar-header { padding: 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
    .sidebar-header h2 { margin: 0; font-size: 20px; font-weight: bold; }
    .sidebar-header p { margin: 5px 0 0; font-size: 13px; opacity: 0.8; }
    .nav-links { flex: 1; padding: 20px 0; overflow-y: auto; }
    .nav-links a { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; font-size: 15px; transition: 0.2s; border-left: 4px solid transparent; }
    .nav-links a:hover { background: rgba(255,255,255,0.1); color: white; border-left-color: #60a5fa; }
    .nav-links a.active { background: #2563eb; color: white; border-left-color: #fff; font-weight: bold; }
    .nav-links a i { width: 25px; text-align: center; margin-right: 10px; }
    .menu-badge { background: #fbbf24; color: #1e3a8a; font-size: 11px; padding: 2px 8px; border-radius: 12px; margin-left: auto; font-weight: bold; }
    .logout-btn { margin: 20px; padding: 12px; text-align: center; background: #dc2626; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.2s; }
    .logout-btn:hover { background: #b91c1c; }

    /* Main Content */
    .main-content { margin-left: 260px; padding: 30px; }
    .header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
    .header-bar h1 { margin: 0; color: #1e3a8a; font-size: 24px; }

    /* Alert Messages */
    .alert { padding: 15px; margin-bottom: 20px; border-radius: 8px; font-weight: 500; display: flex; align-items: center; gap: 10px; font-size: 14px; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; }

    /* Grid */
    .grid-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; }
    
    /* Invite Card */
    .invite-card { 
        background: white; border-radius: 12px; padding: 25px; 
        box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; 
        display: flex; flex-direction: column; transition: transform 0.2s; 
    }
    .invite-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }

    .card-header { display: flex; align-items: start; gap: 15px; margin-bottom: 15px; }
    .icon-box { 
        width: 50px; height: 50px; background: #eff6ff; color: #2563eb; 
        border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 24px; 
    }
    .card-info h3 { margin: 0 0 5px 0; font-size: 18px; color: #1e293b; line-height: 1.4; }
    .card-info p { margin: 0; font-size: 13px; color: #64748b; }

    .card-details { margin-bottom: 20px; padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #f1f5f9; font-size: 13px; color: #475569; }
    .detail-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
    .detail-row:last-child { margin-bottom: 0; }

    /* Buttons */
    .btn-group { display: flex; gap: 10px; margin-top: auto; }
    .btn { flex: 1; padding: 10px; text-align: center; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600; transition: 0.2s; border: none; cursor: pointer; }
    .btn-accept { background: #10b981; color: white; }
    .btn-accept:hover { background: #059669; }
    .btn-decline { background: white; color: #ef4444; border: 1px solid #fca5a5; }
    .btn-decline:hover { background: #fef2f2; }

    .empty-state { text-align: center; padding: 60px; color: #94a3b8; grid-column: 1 / -1; }
    .empty-state i { font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e1; }
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>🎓 ระบบนิสิต</h2>
        <p><?= htmlspecialchars($fullname) ?> <br> (Student)</p>
    </div>
    <div class="nav-links">
        <a href="dashboard.php"><i class="fas fa-home"></i> แดชบอร์ด</a>
        <a href="enroll_course.php"><i class="fas fa-book-open"></i> ลงทะเบียนเรียน</a>
        <a href="my_courses.php"><i class="fas fa-list"></i> วิชาที่ลงทะเบียน</a>
        <?php if ($has_project_access): ?>
            <a href="my_groups.php"><i class="fas fa-users"></i> กลุ่มโครงงาน</a>
            <a href="invitations.php" class="active">
                <i class="fas fa-envelope"></i> คำเชิญเข้ากลุ่ม 
                <?php if ($count_invite > 0): ?>
                    <span class="menu-badge"><?= $count_invite ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">
    <div class="header-bar">
        <h1>📩 คำเชิญเข้าร่วมกลุ่มโครงงาน</h1>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?>">
            <i class="fas fa-info-circle"></i> <?= $msg ?>
        </div>
    <?php endif; ?>

    <div class="grid-container">
        <?php if ($result->num_rows > 0): ?>
            <?php while($r = $result->fetch_assoc()): ?>
                <div class="invite-card">
                    <div class="card-header">
                        <div class="icon-box"><i class="fas fa-users"></i></div>
                        <div class="card-info">
                            <h3><?= htmlspecialchars($r['project_name']) ?></h3>
                            <p><?= htmlspecialchars($r['course_code']) ?> <?= htmlspecialchars($r['course_name']) ?></p>
                        </div>
                    </div>

                    <div class="card-details">
                        <div class="detail-row">
                            <span>👤 ผู้เชิญ:</span>
                            <strong><?= htmlspecialchars($r['inviter']) ?></strong>
                        </div>
                        <div class="detail-row">
                            <span>📅 วันที่ส่ง:</span>
                            <span><?= date("d/m/Y H:i", strtotime($r['created_at'])) ?></span>
                        </div>
                    </div>

                    <div class="btn-group">
                        <a href="?action=accept&group_id=<?= $r['group_id'] ?>" class="btn btn-accept" onclick="return confirm('ยืนยันเข้าร่วมกลุ่ม?')">
                            <i class="fas fa-check"></i> ตอบรับ
                        </a>
                        <a href="?action=decline&group_id=<?= $r['group_id'] ?>" class="btn btn-decline" onclick="return confirm('ต้องการปฏิเสธคำเชิญนี้หรือไม่?')">
                            <i class="fas fa-times"></i> ปฏิเสธ
                        </a>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-envelope-open"></i>
                <h3>ไม่มีคำเชิญใหม่</h3>
                <p>เมื่อเพื่อนส่งคำเชิญมา รายการจะปรากฏที่นี่ครับ</p>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>