<?php
session_start();
include 'db_connect.php';

// ตรวจสอบสิทธิ์เฉพาะอาจารย์
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'teacher') {
    header("Location: login.php");
    exit;
}

$teacher_id = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'];

// ตรวจสอบว่ามีการส่ง id วิชามาหรือไม่
if (!isset($_GET['id'])) {
    header("Location: manage_courses.php");
    exit;
}

$course_id = intval($_GET['id']);

// 🔥 เคลียร์แจ้งเตือน "ขอถอน" (drop_request) ทันทีที่เข้ามาหน้านี้
// ต้องระบุ group_id = course_id ด้วย เพื่อให้เคลียร์เฉพาะวิชานี้
$clear_notif = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE receiver_id = ? AND type = 'drop_request' AND group_id = ?");
$clear_notif->bind_param("ii", $teacher_id, $course_id);
$clear_notif->execute();

// นับจำนวนแจ้งเตือนคำเชิญ (สำหรับ Sidebar)
$count_advisor_invite = 0;
$q3 = $conn->prepare("SELECT COUNT(*) FROM advisor_invites WHERE teacher_id = ? AND status = 'pending'");
$q3->bind_param("i", $teacher_id); $q3->execute();
$count_advisor_invite = $q3->get_result()->fetch_row()[0];

// ตรวจสอบว่ารายวิชานี้เป็นของอาจารย์คนนี้จริงหรือไม่
$check = $conn->prepare("SELECT course_name, course_code FROM courses WHERE id = ? AND teacher_id = ?");
$check->bind_param("ii", $course_id, $teacher_id);
$check->execute();
$course = $check->get_result()->fetch_assoc();

if (!$course) {
    die("❌ คุณไม่มีสิทธิ์เข้าถึงรายวิชานี้ หรือรายวิชาไม่พบในระบบ");
}

// --- ส่วนจัดการการอนุมัติถอน ---
$msg = "";
$msg_type = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $enroll_id = intval($_POST['enroll_id']);
    $action = $_POST['action'];

    // ดึงข้อมูลนิสิตก่อนจะลบ
    $std_q = $conn->prepare("SELECT student_id FROM enrollments WHERE id = ?");
    $std_q->bind_param("i", $enroll_id);
    $std_q->execute();
    $std_data = $std_q->get_result()->fetch_assoc();
    $student_id = $std_data['student_id'];

    if ($action == 'confirm_drop' && $student_id) {
        // ลบ
        $del = $conn->prepare("DELETE FROM enrollments WHERE id = ? AND course_id = ? AND status = 'drop_pending'");
        $del->bind_param("ii", $enroll_id, $course_id);
        
        if ($del->execute()) {
            $msg = "✅ อนุมัติการถอนเรียบร้อย";
            $msg_type = "success";
            
            $notif_msg = "คำขอถอนรายวิชา " . $course['course_code'] . " ของคุณได้รับการอนุมัติแล้ว";
            $n = $conn->prepare("INSERT INTO notifications (receiver_id, sender_id, type, message, is_read, created_at) VALUES (?, ?, 'drop_result', ?, 0, NOW())");
            $n->bind_param("iis", $student_id, $teacher_id, $notif_msg);
            $n->execute();
        }
    }
    elseif ($action == 'reject_drop' && $student_id) {
        // ปฏิเสธ
        $upd = $conn->prepare("UPDATE enrollments SET status = 'approved' WHERE id = ? AND course_id = ?");
        $upd->bind_param("ii", $enroll_id, $course_id);
        
        if ($upd->execute()) {
            $msg = "✅ ปฏิเสธการถอน (นิสิตยังอยู่ในรายวิชา)";
            $msg_type = "warning";
            
            $notif_msg = "คำขอถอนรายวิชา " . $course['course_code'] . " ถูกปฏิเสธ";
            $n = $conn->prepare("INSERT INTO notifications (receiver_id, sender_id, type, message, is_read, created_at) VALUES (?, ?, 'drop_result', ?, 0, NOW())");
            $n->bind_param("iis", $student_id, $teacher_id, $notif_msg);
            $n->execute();
        }
    }
}

// ดึงรายชื่อนิสิต
$sql = "
SELECT u.fullname, u.student_id, u.email, e.enrolled_at, e.status, e.id AS enroll_id
FROM enrollments e
JOIN users u ON e.student_id = u.id
WHERE e.course_id = ?
  AND e.status IN ('approved', 'drop_pending')
ORDER BY e.status DESC, e.enrolled_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายชื่อนิสิต - <?= htmlspecialchars($course['course_name']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Global & Sidebar Theme */
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
        
        /* Page Header */
        .page-header { margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; }
        .page-header h1 { margin: 0; font-size: 24px; color: #1e3a8a; }
        .btn-back { text-decoration: none; color: #64748b; font-weight: 600; display: flex; align-items: center; gap: 5px; }
        .btn-back:hover { color: #333; }

        /* Alert */
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; font-size: 14px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; }

        /* Card & Table */
        .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #e2e8f0; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        th { background: #f8fafc; color: #64748b; font-weight: 600; font-size: 14px; }
        td { font-size: 14px; color: #334155; vertical-align: middle; }
        tr:hover { background: #f8fafc; }

        /* Status Badges */
        .status-badge { padding: 5px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; display: inline-block; }
        .status-approved { color: #16a34a; background: #dcfce7; }
        .status-drop { color: #b91c1c; background: #fee2e2; border: 1px solid #fca5a5; animation: pulse 2s infinite; }

        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }

        /* Action Buttons */
        .btn-action { padding: 6px 12px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; margin-right: 5px; }
        .btn-ok { background: #ef4444; color: white; }
        .btn-ok:hover { background: #dc2626; }
        .btn-no { background: #e5e7eb; color: #374151; }
        .btn-no:hover { background: #d1d5db; }

        .empty-state { text-align: center; padding: 50px; color: #94a3b8; font-size: 14px; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>🎓 ระบบนิสิต</h2>
        <p><?= htmlspecialchars($fullname) ?> <br> (Teacher)</p>
    </div>
    <div class="nav-links">
        <a href="dashboard.php"><i class="fas fa-home"></i> กลับแดชบอร์ด</a>
        <a href="manage_courses.php" class="active"><i class="fas fa-book"></i> จัดการรายวิชา</a>
        <a href="teacher_groups.php"><i class="fas fa-user-graduate"></i> กลุ่มที่ปรึกษา</a>
        <a href="teacher_enrollments.php"><i class="fas fa-tasks"></i> อนุมัติลงทะเบียน</a>
        <a href="advisor_invitations.php"><i class="fas fa-envelope-open-text"></i> คำเชิญที่ปรึกษา <?php if ($count_advisor_invite > 0): ?><span class="menu-badge"><?= $count_advisor_invite ?></span><?php endif; ?></a>

    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">
    
    <div class="page-header">
        <h1>👨‍🎓 รายชื่อนิสิตในรายวิชา</h1>
        <a href="manage_courses.php" class="btn-back"><i class="fas fa-arrow-left"></i> ย้อนกลับ</a>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?>">
            <i class="fas fa-info-circle"></i> <?= $msg ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #f1f5f9;">
            <strong style="font-size: 18px; color: #1e3a8a;"><?= htmlspecialchars($course['course_name']) ?></strong>
            <span style="background: #eff6ff; color: #2563eb; padding: 3px 8px; border-radius: 4px; font-size: 13px; font-weight: bold; margin-left: 10px;">
                <?= htmlspecialchars($course['course_code']) ?>
            </span>
        </div>

        <table>
            <thead>
                <tr>
                    <th>รหัสนิสิต</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>อีเมล</th>
                    <th>สถานะ</th>
                    <th>การจัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['student_id']) ?></td>
                            <td><?= htmlspecialchars($row['fullname']) ?></td>
                            <td><?= htmlspecialchars($row['email']) ?></td>
                            <td>
                                <?php if ($row['status'] == 'approved'): ?>
                                    <span class="status-badge status-approved"><i class="fas fa-check"></i> ปกติ</span>
                                <?php elseif ($row['status'] == 'drop_pending'): ?>
                                    <span class="status-badge status-drop"><i class="fas fa-exclamation-triangle"></i> ขอถอนรายวิชา</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['status'] == 'drop_pending'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="enroll_id" value="<?= $row['enroll_id'] ?>">
                                        <button type="submit" name="action" value="confirm_drop" class="btn-action btn-ok" onclick="return confirm('ยืนยันให้ออกจากการเรียน?')">อนุญาต</button>
                                        <button type="submit" name="action" value="reject_drop" class="btn-action btn-no">ไม่อนุมัติ</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#ccc;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="empty-state">ยังไม่มีนิสิตลงทะเบียนในรายวิชานี้</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>