<?php
session_start();
include 'db_connect.php';

// ตรวจสิทธิ์อาจารย์
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'teacher') {
    header("Location: login.php");
    exit;
}

$teacher_id = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'];
$msg = "";
$msg_type = ""; // success, danger

// รับ/ปฏิเสธคำเชิญ
if (isset($_GET['action'], $_GET['invite_id'])) {
    $invite_id = intval($_GET['invite_id']);
    $action = $_GET['action'];

    // ตรวจสอบคำเชิญ
    $q = $conn->prepare("SELECT group_id, status FROM advisor_invites WHERE id = ? AND teacher_id = ?");
    $q->bind_param("ii", $invite_id, $teacher_id);
    $q->execute();
    $invite = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$invite) {
        $msg = "❌ ไม่พบคำเชิญนี้";
        $msg_type = "danger";
    } elseif ($invite['status'] !== 'pending') {
        $msg = "⚠️ คำเชิญนี้ได้รับการตอบรับไปแล้ว";
        $msg_type = "warning";
    } else {
        if ($action === 'accept') {
            // ตั้ง advisor_id
            $upd = $conn->prepare("UPDATE project_groups SET advisor_id = ? WHERE id = ?");
            $upd->bind_param("ii", $teacher_id, $invite['group_id']);
            if ($upd->execute()) {
                // อัปเดต status invite
                $u2 = $conn->prepare("UPDATE advisor_invites SET status = 'accepted' WHERE id = ?");
                $u2->bind_param("i", $invite_id);
                $u2->execute();
                
                $msg = "✅ คุณตอบรับเป็นที่ปรึกษาเรียบร้อยแล้ว";
                $msg_type = "success";
            } else {
                $msg = "❌ เกิดข้อผิดพลาด: " . $conn->error;
                $msg_type = "danger";
            }
        } elseif ($action === 'decline') {
            $d = $conn->prepare("UPDATE advisor_invites SET status = 'declined' WHERE id = ?");
            $d->bind_param("i", $invite_id);
            $d->execute();
            $msg = "❌ ปฏิเสธคำเชิญเรียบร้อยแล้ว";
            $msg_type = "warning"; // ใช้สีเหลือง/ส้ม ให้ดูซอฟต์กว่า error
        }
    }
}

// ดึงคำเชิญที่ยัง Pending
$stmt = $conn->prepare("
    SELECT ai.id AS invite_id, g.id AS group_id, g.project_name, 
           u.fullname AS sender_name, ai.created_at,
           c.course_code, c.course_name
    FROM advisor_invites ai
    JOIN project_groups g ON ai.group_id = g.id
    JOIN users u ON ai.sender_id = u.id
    LEFT JOIN courses c ON g.course_id = c.id
    WHERE ai.teacher_id = ? AND ai.status = 'pending'
    ORDER BY ai.created_at DESC
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$invites = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>คำเชิญเป็นที่ปรึกษา</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Global Theme */
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
    .logout-btn { margin: 20px; padding: 12px; text-align: center; background: #dc2626; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.2s; }
    .logout-btn:hover { background: #b91c1c; }

    /* Main Content */
    .main-content { margin-left: 260px; padding: 30px; }
    
    .page-header { margin-bottom: 25px; }
    .page-header h1 { margin: 0; font-size: 24px; color: #1e3a8a; }

    /* Alert */
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; font-size: 14px; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }

    /* Grid Layout */
    .grid-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; }

    /* Invite Card */
    .invite-card { 
        background: white; border-radius: 12px; padding: 25px; 
        box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;
        transition: transform 0.2s; display: flex; flex-direction: column;
    }
    .invite-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }

    .card-header { display: flex; align-items: start; gap: 15px; margin-bottom: 15px; }
    .icon-box { 
        width: 50px; height: 50px; background: #eff6ff; color: #2563eb; 
        border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 24px; 
    }
    .card-info h3 { margin: 0 0 5px 0; font-size: 18px; color: #1e293b; line-height: 1.4; }
    .card-info p { margin: 0; font-size: 13px; color: #64748b; }

    .card-details { 
        margin-bottom: 20px; padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #f1f5f9; 
        font-size: 13px; color: #475569;
    }
    .detail-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
    .detail-row:last-child { margin-bottom: 0; }

    .btn-group { display: flex; gap: 10px; margin-top: auto; }
    .btn { flex: 1; padding: 10px; text-align: center; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600; transition: 0.2s; border: none; cursor: pointer; }
    
    .btn-accept { background: #10b981; color: white; }
    .btn-accept:hover { background: #059669; }
    
    .btn-decline { background: white; color: #ef4444; border: 1px solid #fca5a5; }
    .btn-decline:hover { background: #fef2f2; }

    .empty-state { text-align: center; padding: 60px; color: #94a3b8; }
    .empty-state i { font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e1; }
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>🎓 ระบบนิสิต</h2>
        <p><?= htmlspecialchars($fullname) ?> <br> (Teacher)</p>
    </div>
    <div class="nav-links">
        <a href="manage_courses.php"><i class="fas fa-book"></i> จัดการรายวิชา</a>
        <a href="teacher_groups.php"><i class="fas fa-user-graduate"></i> กลุ่มที่ปรึกษา</a>
        <a href="teacher_enrollments.php"><i class="fas fa-tasks"></i> อนุมัติลงทะเบียน</a>
        <a href="advisor_invitations.php" class="active"><i class="fas fa-envelope-open-text"></i> คำเชิญที่ปรึกษา</a>
        <a href="dashboard.php"><i class="fas fa-home"></i> กลับแดชบอร์ด</a>
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">
    
    <div class="page-header">
        <h1>📩 คำเชิญให้เป็นที่ปรึกษา</h1>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?>">
            <i class="fas fa-info-circle"></i> <?= $msg ?>
        </div>
    <?php endif; ?>

    <div class="grid-container">
        <?php if ($invites->num_rows > 0): ?>
            <?php while($r = $invites->fetch_assoc()): ?>
                <div class="invite-card">
                    <div class="card-header">
                        <div class="icon-box">
                            <i class="fas fa-file-signature"></i>
                        </div>
                        <div class="card-info">
                            <h3><?= htmlspecialchars($r['project_name']) ?></h3>
                            <p><?= htmlspecialchars($r['course_code']) ?> <?= htmlspecialchars($r['course_name']) ?></p>
                        </div>
                    </div>

                    <div class="card-details">
                        <div class="detail-row">
                            <span>👤 ผู้เชิญ:</span>
                            <strong><?= htmlspecialchars($r['sender_name']) ?></strong>
                        </div>
                        <div class="detail-row">
                            <span>📅 วันที่ส่ง:</span>
                            <span><?= date("d/m/Y H:i", strtotime($r['created_at'])) ?></span>
                        </div>
                    </div>

                    <div class="btn-group">
                        <a href="?action=accept&invite_id=<?= $r['invite_id'] ?>" class="btn btn-accept" onclick="return confirm('ยืนยันการรับเป็นที่ปรึกษา?')">
                            <i class="fas fa-check"></i> ตอบรับ
                        </a>
                        <a href="?action=decline&invite_id=<?= $r['invite_id'] ?>" class="btn btn-decline" onclick="return confirm('ต้องการปฏิเสธคำเชิญนี้หรือไม่?')">
                            <i class="fas fa-times"></i> ปฏิเสธ
                        </a>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state" style="grid-column: 1 / -1;">
                <i class="fas fa-inbox"></i>
                <h3>ยังไม่มีคำเชิญใหม่</h3>
                <p>เมื่อมีนิสิตส่งคำเชิญมา รายการจะปรากฏที่นี่ครับ</p>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>