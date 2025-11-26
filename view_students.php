<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'teacher') {
    header("Location: login.php");
    exit;
}

$teacher_id = $_SESSION['user_id'];
if (!isset($_GET['id'])) { header("Location: manage_courses.php"); exit; }
$course_id = intval($_GET['id']);

// ตรวจสอบความเป็นเจ้าของวิชา
$check = $conn->prepare("SELECT course_name, course_code FROM courses WHERE id = ? AND teacher_id = ?");
$check->bind_param("ii", $course_id, $teacher_id);
$check->execute();
$course = $check->get_result()->fetch_assoc();
if (!$course) die("❌ ไม่พบรายวิชา หรือคุณไม่มีสิทธิ์");

// --- ส่วนจัดการการอนุมัติถอน ---
$msg = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $enroll_id = intval($_POST['enroll_id']);
    $action = $_POST['action'];

    // 🔥 1. ดึงข้อมูลนิสิตก่อนจะลบ (เพื่อเอา ID ไปส่งแจ้งเตือน)
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
            
            // 🔥 2. ส่งแจ้งเตือนกลับหานิสิต
            $notif_msg = "คำขอถอนรายวิชา " . $course['course_code'] . " ของคุณได้รับการอนุมัติแล้ว";
            $n = $conn->prepare("INSERT INTO notifications (receiver_id, sender_id, type, message, is_read, created_at) VALUES (?, ?, 'drop_result', ?, 0, NOW())");
            $n->bind_param("iis", $student_id, $teacher_id, $notif_msg);
            $n->execute();
        }
    }
    elseif ($action == 'reject_drop' && $student_id) {
        // ปฏิเสธ (กลับเป็น approved)
        $upd = $conn->prepare("UPDATE enrollments SET status = 'approved' WHERE id = ? AND course_id = ?");
        $upd->bind_param("ii", $enroll_id, $course_id);
        
        if ($upd->execute()) {
            $msg = "✅ ปฏิเสธการถอน (นิสิตยังอยู่ในรายวิชา)";
            
            // 🔥 2. ส่งแจ้งเตือนกลับหานิสิต
            $notif_msg = "คำขอถอนรายวิชา " . $course['course_code'] . " ถูกปฏิเสธ";
            $n = $conn->prepare("INSERT INTO notifications (receiver_id, sender_id, type, message, is_read, created_at) VALUES (?, ?, 'drop_result', ?, 0, NOW())");
            $n->bind_param("iis", $student_id, $teacher_id, $notif_msg);
            $n->execute();
        }
    }
}

// ดึงรายชื่อ (เหมือนเดิม)
$sql = "SELECT u.fullname, u.student_id, u.email, e.enrolled_at, e.status, e.id AS enroll_id FROM enrollments e JOIN users u ON e.student_id = u.id WHERE e.course_id = ? AND e.status IN ('approved', 'drop_pending') ORDER BY e.status DESC, u.student_id ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายชื่อนิสิต</title>
    <style>
        body { font-family: sans-serif; background: #f4f4f4; padding: 20px; }
        .container { max-width: 1000px; margin: auto; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border-bottom: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #007bff; color: white; }
        .status-drop { color: red; font-weight: bold; background: #ffeeee; padding: 5px; border-radius: 5px; }
        .btn { padding: 5px 10px; border: none; border-radius: 4px; cursor: pointer; color: white; font-size: 13px; }
        .btn-ok { background: #dc3545; } 
        .btn-no { background: #6c757d; }
    </style>
</head>
<body>
<div class="container">
    <h2>👨‍🎓 รายชื่อนิสิต: <?= htmlspecialchars($course['course_code']) ?> <?= htmlspecialchars($course['course_name']) ?></h2>
    <?php if ($msg): ?><p style="color:green; text-align:center; font-weight:bold;"><?= $msg ?></p><?php endif; ?>
    <a href="manage_courses.php" style="text-decoration:none; color:#007bff;">⬅ กลับหน้าจัดการรายวิชา</a>
    <table>
        <tr><th>รหัสนิสิต</th><th>ชื่อ-นามสกุล</th><th>สถานะ</th><th>การจัดการ</th></tr>
        <?php if ($result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['student_id']) ?></td>
                    <td><?= htmlspecialchars($row['fullname']) ?></td>
                    <td>
                        <?php if ($row['status'] == 'approved'): ?><span style="color:green">✅ ปกติ</span>
                        <?php elseif ($row['status'] == 'drop_pending'): ?><span class="status-drop">🚨 ขอถอนรายวิชา</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['status'] == 'drop_pending'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="enroll_id" value="<?= $row['enroll_id'] ?>">
                                <button type="submit" name="action" value="confirm_drop" class="btn btn-ok" onclick="return confirm('ยืนยันให้ออกจากการเรียน?')">อนุญาต</button>
                                <button type="submit" name="action" value="reject_drop" class="btn btn-no">ไม่อนุมัติ</button>
                            </form>
                        <?php else: ?> - <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="4" style="text-align:center;">ยังไม่มีนิสิต</td></tr>
        <?php endif; ?>
    </table>
</div>
</body>
</html>