<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

// ตรวจสิทธิ์อาจารย์
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit;
}

$teacher_id = (int)$_SESSION['user_id'];
$fullname = $_SESSION['fullname'] ?? 'Teacher'; 
$msg = "";
$msg_type = ""; 

// 🔥 เคลียร์แจ้งเตือน "ขอลงทะเบียน" (enroll_request) ทันทีที่เข้ามาหน้านี้
$clear_notif = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE receiver_id = ? AND type = 'enroll_request'");
$clear_notif->bind_param("i", $teacher_id);
$clear_notif->execute();

// นับจำนวนแจ้งเตือนคำเชิญที่ปรึกษา (สำหรับ Sidebar)
$count_advisor_invite = 0;
$q3 = $conn->prepare("SELECT COUNT(*) FROM advisor_invites WHERE teacher_id = ? AND status = 'pending'");
$q3->bind_param("i", $teacher_id); $q3->execute();
$count_advisor_invite = $q3->get_result()->fetch_row()[0];

// ---------- จัดการ POST อนุมัติ / ปฏิเสธ ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enroll_id = (int)($_POST['enroll_id'] ?? 0);
    $action    = $_POST['action'] ?? '';

    if ($enroll_id && in_array($action, ['approved','rejected'])) {

        // 1. ดึงข้อมูลก่อน
        $info_q = $conn->prepare("
            SELECT e.student_id, c.course_name, c.course_code, c.id AS course_id
            FROM enrollments e 
            JOIN courses c ON e.course_id = c.id 
            WHERE e.id = ?
        ");
        $info_q->bind_param("i", $enroll_id);
        $info_q->execute();
        $info = $info_q->get_result()->fetch_assoc();

        // 2. อัปเดตสถานะ
        $upd = $conn->prepare("
            UPDATE enrollments e
            JOIN courses c ON e.course_id = c.id
            SET e.status = ?
            WHERE e.id = ?
              AND c.teacher_id = ?
        ");
        $upd->bind_param("sii", $action, $enroll_id, $teacher_id);
        
        if ($upd->execute() && $info) {
            $student_id = $info['student_id'];
            $course_id  = $info['course_id']; 
            $course_name = $info['course_code'] . " " . $info['course_name'];
            
            // 3. ส่ง Notification ตอบกลับ
            if ($action === 'approved') {
                $notif_msg = "✅ คำขอลงทะเบียนวิชา '$course_name' ได้รับการอนุมัติแล้ว";
                $msg = "✅ อนุมัตินิสิตเข้าเรียนเรียบร้อย";
                $msg_type = "success";
            } else {
                $notif_msg = "❌ คำขอลงทะเบียนวิชา '$course_name' ถูกปฏิเสธ";
                $msg = "❌ ปฏิเสธคำขอเรียบร้อย";
                $msg_type = "danger";
            }

            $notif = $conn->prepare("INSERT INTO notifications (receiver_id, sender_id, type, message, is_read, created_at) VALUES (?, ?, 'enrollment_result', ?, 0, NOW())");
            $notif->bind_param("iis", $student_id, $teacher_id, $notif_msg);
            $notif->execute();

            // 4. (กันเหนียว) เคลียร์แจ้งเตือนเฉพาะรายการนี้อีกรอบ
            $clear_specific = $conn->prepare("
                UPDATE notifications 
                SET is_read = 1 
                WHERE receiver_id = ? AND sender_id = ? AND type = 'enroll_request' AND group_id = ?
            ");
            $clear_specific->bind_param("iii", $teacher_id, $student_id, $course_id);
            $clear_specific->execute();

        } else {
            $msg = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
            $msg_type = "danger";
        }
    }
}

// ---------- ดึงรายการรออนุมัติ ----------
$sql = "
    SELECT 
        e.id AS enroll_id,
        e.status,
        e.enrolled_at,
        u.fullname AS student_name,
        u.student_id,
        c.course_name,
        c.course_code
    FROM enrollments e
    JOIN users u ON e.student_id = u.id
    JOIN courses c ON e.course_id = c.id
    WHERE c.teacher_id = ?
      AND e.status = 'pending'
    ORDER BY e.enrolled_at ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$rows = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ยืนยันการลงทะเบียน</title>
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
    .menu-badge { background: #fbbf24; color: #1e3a8a; font-size: 11px; padding: 2px 8px; border-radius: 12px; margin-left: auto; font-weight: bold; }
    .logout-btn { margin: 20px; padding: 12px; text-align: center; background: #dc2626; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.2s; }
    .logout-btn:hover { background: #b91c1c; }
    .main-content { margin-left: 260px; padding: 30px; }
    .page-header { margin-bottom: 25px; }
    .page-header h1 { margin: 0; font-size: 24px; color: #1e3a8a; }
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; font-size: 14px; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #e2e8f0; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 15px; text-align: left; border-bottom: 1px solid #f1f5f9; }
    th { background: #f8fafc; color: #64748b; font-weight: 600; font-size: 14px; }
    td { font-size: 14px; color: #334155; vertical-align: middle; }
    tr:hover { background: #f8fafc; }
    .student-info { display: flex; flex-direction: column; }
    .student-name { font-weight: 600; color: #1e293b; }
    .student-id { font-size: 12px; color: #64748b; }
    .course-tag { background: #eff6ff; color: #2563eb; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
    .action-area { display: flex; gap: 8px; }
    .btn-action { padding: 8px 12px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 5px; transition: 0.2s; }
    .btn-approve { background: #dcfce7; color: #166534; }
    .btn-approve:hover { background: #bbf7d0; }
    .btn-reject { background: #fee2e2; color: #991b1b; }
    .btn-reject:hover { background: #fecaca; }
    .empty-state { text-align: center; padding: 50px; color: #94a3b8; }
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
        <a href="dashboard.php"><i class="fas fa-home"></i> แดชบอร์ด</a>
        <a href="manage_courses.php"><i class="fas fa-book"></i> จัดการรายวิชา</a>
        <a href="teacher_groups.php"><i class="fas fa-user-graduate"></i> กลุ่มที่ปรึกษา</a>
        <a href="teacher_enrollments.php" class="active"><i class="fas fa-tasks"></i> อนุมัติลงทะเบียน</a>
        <a href="advisor_invitations.php"><i class="fas fa-envelope-open-text"></i> คำเชิญที่ปรึกษา <?php if ($count_advisor_invite > 0): ?><span class="menu-badge"><?= $count_advisor_invite ?></span><?php endif; ?></a>
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">
    <div class="page-header">
        <h1>📋 อนุมัติลงทะเบียน</h1>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?>">
            <i class="fas fa-info-circle"></i> <?= $msg ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <?php if ($rows->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>รายวิชา</th>
                        <th>นิสิตผู้ขอ</th>
                        <th>วันที่ส่งคำขอ</th>
                        <th width="25%">การดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($r = $rows->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <span class="course-tag"><?= htmlspecialchars($r['course_code']) ?></span>
                                <div style="margin-top:5px; font-size:13px;"><?= htmlspecialchars($r['course_name']) ?></div>
                            </td>
                            <td>
                                <div class="student-info">
                                    <span class="student-name"><?= htmlspecialchars($r['student_name']) ?></span>
                                    <span class="student-id">ID: <?= htmlspecialchars($r['student_id']) ?></span>
                                </div>
                            </td>
                            <td><?= date("d/m/Y H:i", strtotime($r['enrolled_at'])) ?></td>
                            <td>
                                <div class="action-area">
                                    <form method="POST" style="margin:0">
                                        <input type="hidden" name="enroll_id" value="<?= $r['enroll_id'] ?>">
                                        <button type="submit" name="action" value="approved" class="btn-action btn-approve" title="อนุมัติ"><i class="fas fa-check"></i> อนุมัติ</button>
                                    </form>
                                    <form method="POST" style="margin:0">
                                        <input type="hidden" name="enroll_id" value="<?= $r['enroll_id'] ?>">
                                        <button type="submit" name="action" value="rejected" class="btn-action btn-reject" title="ปฏิเสธ" onclick="return confirm('ยืนยันการปฏิเสธหรือไม่?')"><i class="fas fa-times"></i> ปฏิเสธ</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-check"></i>
                <h3>ไม่มีคำขอลงทะเบียนใหม่</h3>
                <p>รายการคำขอทั้งหมดได้รับการจัดการเรียบร้อยแล้ว</p>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>