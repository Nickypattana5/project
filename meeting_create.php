<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit;
}

$teacher_id = (int)$_SESSION['user_id'];
$group_id = intval($_GET['group_id'] ?? 0);

// ตรวจกลุ่มและสิทธิ์
$stmt = $conn->prepare("SELECT * FROM project_groups WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
if (!$group) die("❌ ไม่พบกลุ่ม");
if ($group['advisor_id'] != $teacher_id) die("❌ คุณไม่ใช่อาจารย์ที่ปรึกษาของกลุ่มนี้");

// หาค่า week_number อัตโนมัติ (next)
$q = $conn->prepare("SELECT MAX(week_number) AS mx FROM project_meetings WHERE group_id = ?");
$q->bind_param("i", $group_id);
$q->execute();
$mx = $q->get_result()->fetch_assoc();
$next_week = ($mx['mx'] ?? 0) + 1;

$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $week_number = intval($_POST['week_number'] ?? $next_week);
    $started_by = $teacher_id;
    $stmt = $conn->prepare("INSERT INTO project_meetings (group_id, week_number, started_by, started_at, created_at) VALUES (?, ?, ?, NOW(), NOW())");
    $stmt->bind_param("iii", $group_id, $week_number, $started_by);
    if ($stmt->execute()) {
        header("Location: meeting_chat.php?meeting_id=".$stmt->insert_id);
        exit;
    } else {
        $message = "❌ เกิดข้อผิดพลาด: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>สร้างห้องพบปะใหม่</title>
<style>
body{font-family:sans-serif;background:#f4f4f4;padding:20px}
.container{max-width:600px;margin:auto;background:#fff;padding:20px;border-radius:10px}
input,button{padding:8px}
.btn-green{background:#28a745;color:#fff;border:none;padding:8px 12px;border-radius:6px}
</style>
</head>
<body>
<div class="container">
<h2>➕ สร้างห้องพบปะใหม่ – <?= htmlspecialchars($group['project_name']) ?></h2>

<?php if ($message): ?><p style="color:red"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<form method="POST">
    <label>สัปดาห์ที่</label><br>
    <input type="number" name="week_number" value="<?= htmlspecialchars($next_week) ?>" required><br><br>

    <button class="btn-green" type="submit">สร้างและเข้าแชท</button>
</form>

<p style="margin-top:15px;"><a href="meeting_list.php?group_id=<?= $group_id ?>">⬅ ย้อนกลับ</a></p>
</div>
</body>
</html>
