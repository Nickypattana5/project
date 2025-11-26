<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

// ป้องกันผู้ไม่ได้ล็อกอิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$group_id = intval($_GET['group_id'] ?? 0);

// ดึงข้อมูลกลุ่มเพื่อดูรายวิชา
$stmt = $conn->prepare("
    SELECT g.project_name, g.course_id, c.course_name 
    FROM project_groups g
    LEFT JOIN courses c ON g.course_id = c.id
    WHERE g.id = ?
");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();

if (!$group) {
    die("❌ ไม่พบข้อมูลกลุ่มนี้");
}

$course_id = intval($group['course_id']);

if ($course_id === 0) {
    die("❌ กลุ่มนี้ยังไม่ได้เชื่อมกับรายวิชา (course_id)");
}

$message = "";

// -------------------------------------------------------
//   🛑 ส่วนที่ 1: ตรวจสอบเมื่อกดปุ่มเชิญ (POST)
// -------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['invite_id'])) {

    $invite_id = intval($_POST['invite_id']);

    // 1. ตรวจว่าคนนี้ลงวิชาเดียวกันไหม
    $check_course = $conn->prepare("SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND course_id = ? AND status = 'approved'");
    $check_course->bind_param("ii", $invite_id, $course_id);
    $check_course->execute();
    $check_course->bind_result($enrolled);
    $check_course->fetch();
    $check_course->close();

    if ($enrolled == 0) {
        $message = "⚠️ ผู้ใช้นี้ไม่ได้ลงทะเบียนรายวิชาเดียวกัน (หรือยังไม่ได้รับการอนุมัติ)";
    } else {
        // 2. เช็คว่าคนนี้ "มีกลุ่มอยู่แล้ว" หรือไม่?
        $check_busy = $conn->prepare("
            SELECT COUNT(*) 
            FROM project_members pm
            JOIN project_groups pg ON pm.group_id = pg.id
            WHERE pm.student_id = ? 
              AND pg.course_id = ?
        ");
        $check_busy->bind_param("ii", $invite_id, $course_id);
        $check_busy->execute();
        $check_busy->bind_result($has_group);
        $check_busy->fetch();
        $check_busy->close();

        if ($has_group > 0) {
            $message = "❌ ไม่สามารถเชิญได้: นิสิตคนนี้มีกลุ่มอยู่แล้ว";
        } else {
            // 3. เช็คว่าเคยเชิญไปแล้วหรือยัง (เช็คใน project_members โดยตรงเลยแม่นยำกว่า)
            $check_invite = $conn->prepare("SELECT COUNT(*) FROM project_members WHERE group_id = ? AND student_id = ?");
            $check_invite->bind_param("ii", $group_id, $invite_id);
            $check_invite->execute();
            $check_invite->bind_result($already_in_group);
            $check_invite->fetch();
            $check_invite->close();

            if ($already_in_group > 0) {
                $message = "⚠️ ได้ส่งคำเชิญไปแล้ว หรือเป็นสมาชิกอยู่แล้ว";
            } else {
                // ✅ ผ่านทุกด่าน -> เริ่มบันทึกข้อมูล (ต้องทำ 2 อย่าง)
                
                // A. เพิ่มชื่อลงใน project_members (สถานะรอตอบรับ: is_confirmed = 0)
                // 🔥 นี่คือส่วนที่ขาดไปครับ!
                $ins_member = $conn->prepare("INSERT INTO project_members (group_id, student_id, is_leader, is_confirmed, invited_by, joined_at) VALUES (?, ?, 0, 0, ?, NOW())");
                $ins_member->bind_param("iii", $group_id, $invite_id, $user_id);
                
                if ($ins_member->execute()) {
                    // B. บันทึก Notification
                    $msg = "เชิญคุณเข้าร่วมกลุ่มโครงงาน '{$group['project_name']}' ในรายวิชา {$group['course_name']}";
                    $notify = $conn->prepare("INSERT INTO notifications (receiver_id, sender_id, type, group_id, message, is_read) VALUES (?, ?, 'invite_group', ?, ?, 0)");
                    $notify->bind_param("iiis", $invite_id, $user_id, $group_id, $msg);
                    $notify->execute();

                    $message = "✅ ส่งคำเชิญสำเร็จ!";
                } else {
                    $message = "❌ เกิดข้อผิดพลาด: " . $conn->error;
                }
            }
        }
    }
}

// -------------------------------------------------------
//   🛑 ส่วนที่ 2: ดึงรายชื่อเพื่อน (SQL Query)
// -------------------------------------------------------
$sql = "
SELECT DISTINCT u.id, u.fullname, u.email
FROM enrollments e
JOIN users u ON e.student_id = u.id
WHERE e.course_id = ?
  AND e.status = 'approved'
  AND u.role = 'student'
  AND u.id != ?

  -- กรองคนที่มีกลุ่มแล้วในวิชานี้ออก
  AND u.id NOT IN (
        SELECT pm.student_id
        FROM project_members pm
        JOIN project_groups pg ON pm.group_id = pg.id
        WHERE pg.course_id = ?
  )

ORDER BY u.fullname ASC
";

$stmt2 = $conn->prepare($sql);
$stmt2->bind_param("iii", $course_id, $user_id, $course_id);
$stmt2->execute();
$students = $stmt2->get_result();
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เชิญเพื่อนเข้ากลุ่ม</title>
<style>
body { font-family: sans-serif; background: #f4f6f9; padding: 20px; }
.container { background: white; max-width: 700px; margin: auto; border-radius: 12px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
h2 { text-align: center; margin-bottom: 10px; color: #1e3a8a; }
p.subtitle { text-align:center; color:#64748b; margin-bottom: 25px; }

table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th, td { border-bottom: 1px solid #eee; padding: 15px; text-align: left; }
th { background: #f8fafc; color: #475569; font-weight: 600; }
td { color: #334155; }

button { background: #2563eb; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; transition: 0.2s; font-weight: 500; }
button:hover { background: #1d4ed8; }

.message { text-align: center; color: #059669; background: #d1fae5; padding: 10px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #a7f3d0; }
.error-msg { text-align: center; color: #dc2626; background: #fee2e2; padding: 10px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #fecaca; }
.back-link { display: block; text-align: center; margin-top: 25px; color: #64748b; text-decoration: none; }
.back-link:hover { color: #1e3a8a; }
</style>
</head>
<body>

<div class="container">
    <h2>👥 เชิญเพื่อนเข้ากลุ่ม</h2>
    <p class="subtitle">
        กลุ่ม: <strong><?= htmlspecialchars($group['project_name']) ?></strong><br>
        วิชา: <?= htmlspecialchars($group['course_name']) ?>
    </p>

    <?php if ($message): ?>
        <div class="<?= strpos($message, '✅') !== false ? 'message' : 'error-msg' ?>"><?= $message ?></div>
    <?php endif; ?>

    <?php if ($students->num_rows > 0): ?>
    <table>
        <tr>
            <th>ชื่อ-นามสกุล</th>
            <th>อีเมล</th>
            <th style="text-align: center;">การกระทำ</th>
        </tr>
        <?php while ($row = $students->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($row['fullname']) ?></td>
            <td><?= htmlspecialchars($row['email']) ?></td>
            <td style="text-align: center;">
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="invite_id" value="<?= $row['id'] ?>">
                    <button type="submit">➕ ส่งคำเชิญ</button>
                </form>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>
    <?php else: ?>
        <div style="text-align:center; padding: 40px; color:#94a3b8; border: 2px dashed #e2e8f0; border-radius: 8px;">
            <span style="font-size: 30px; display:block; margin-bottom:10px;">🚫</span>
            ไม่พบเพื่อนที่สามารถเชิญได้<br>
            <small>เพื่อนอาจจะมีกลุ่มแล้ว หรือยังไม่ได้ลงทะเบียนวิชานี้</small>
        </div>
    <?php endif; ?>

    <a href="group_chat.php?id=<?= $group_id ?>" class="back-link">⬅ กลับหน้ากลุ่ม</a>
</div>

</body>
</html>