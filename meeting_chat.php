<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

// ตรวจล็อกอิน
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'student';
$meeting_id = intval($_GET['meeting_id'] ?? 0);

// 1) โหลดข้อมูล meeting
$stmt = $conn->prepare("
    SELECT m.*, g.project_name, g.advisor_id
    FROM project_meetings m
    JOIN project_groups g ON m.group_id = g.id
    WHERE m.id = ?
");
$stmt->bind_param("i", $meeting_id);
$stmt->execute();
$meeting = $stmt->get_result()->fetch_assoc();
if (!$meeting) die("❌ ไม่พบห้องพบปะนี้");

$group_id = $meeting['group_id'];

// 2) ตรวจสิทธิ์ (ต้องอยู่กลุ่มนี้)
$allow = false;
if ($role === 'teacher' && $meeting['advisor_id'] == $user_id) {
    $allow = true;
} else {
    $chk = $conn->prepare("SELECT id FROM project_members WHERE group_id = ? AND student_id = ?");
    $chk->bind_param("ii", $group_id, $user_id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) $allow = true;
}
if (!$allow) die("❌ ไม่มีสิทธิ์เข้าห้องนี้");

// 3) โหลดข้อความแชทเฉพาะ meeting นี้
$chats = $conn->prepare("
    SELECT c.*, u.fullname, u.role
    FROM project_chat c
    JOIN users u ON c.sender_id = u.id
    WHERE c.group_id = ?
      AND c.channel = 'meeting'
      AND c.meeting_id = ?
    ORDER BY c.created_at ASC
");
$chats->bind_param("ii", $group_id, $meeting_id);
$chats->execute();
$chat_result = $chats->get_result();

// 4) ตรวจว่า meeting ถูกปิดหรือยัง
$is_closed = (int)$meeting['is_closed'] === 1;

// 5) POST ส่งข้อความเฉพาะตอนยังไม่ปิด
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($is_closed && $role !== 'teacher') {
        die("❌ ห้องถูกปิดแล้ว คุณไม่สามารถส่งข้อความได้");
    }

    $text = trim($_POST['message'] ?? '');
    $file_path = null;

    if (!empty($_FILES['chat_file']['name'])) {
        if (!is_dir("uploads/chat_meeting/")) mkdir("uploads/chat_meeting/", 0777, true);
        $filename = time() . "_" . basename($_FILES['chat_file']['name']);
        $target = "uploads/chat_meeting/" . $filename;
        if (move_uploaded_file($_FILES['chat_file']['tmp_name'], $target)) {
            $file_path = $target;
        }
    }

    if ($text !== '' || $file_path !== null) {
        $stmt = $conn->prepare("
            INSERT INTO project_chat (group_id, sender_id, message, file_path, channel, meeting_id)
            VALUES (?, ?, ?, ?, 'meeting', ?)
        ");
        $stmt->bind_param("iissi", $group_id, $user_id, $text, $file_path, $meeting_id);
        $stmt->execute();
    }

    header("Location: meeting_chat.php?meeting_id=".$meeting_id);
    exit;
}

// 6) ปิดห้อง meeting (เฉพาะอาจารย์)
if (isset($_GET['end']) && $role === 'teacher') {
    $end = $conn->prepare("UPDATE project_meetings SET is_closed = 1, ended_at = NOW() WHERE id = ?");
    $end->bind_param("i", $meeting_id);
    $end->execute();
    header("Location: meeting_chat.php?meeting_id=".$meeting_id);
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>พบปะอาจารย์: สัปดาห์ที่ <?= htmlspecialchars($meeting['week_number']) ?></title>
<style>
body {font-family:sans-serif;background:#f4f4f4;padding:20px}
.container {max-width:900px;margin:auto;background:#fff;padding:20px;border-radius:10px}
.chat-box {background:#fafafa;border:1px solid #ddd;height:420px;overflow-y:auto;padding:12px;border-radius:8px}
.message {padding:10px;margin-bottom:8px;border-radius:8px}
.message.teacher {background:#e9f7ef}
.message.student {background:#f1f1f1}
.notice {padding:10px;background:#fff3cd;border-radius:6px;color:#856404}
.btn {padding:6px 12px;border-radius:6px;text-decoration:none}
.btn-blue {background:#007bff;color:#fff}
.btn-red {background:#dc3545;color:#fff}
.btn-green {background:#28a745;color:#fff}
</style>
</head>
<body>

<div class="container">
<h2>📅 พบปะสัปดาห์ที่ <?= htmlspecialchars($meeting['week_number']) ?> – <?= htmlspecialchars($meeting['project_name']) ?></h2>

<p><strong>สถานะ:</strong>
<?php if ($is_closed): ?>
    <span style="color:red">ปิดแล้ว</span>
<?php else: ?>
    <span style="color:green">เปิดอยู่</span>
<?php endif; ?>
</p>

<?php if ($role === 'teacher' && !$is_closed): ?>
    <p><a href="meeting_chat.php?meeting_id=<?= $meeting_id ?>&end=1" class="btn btn-red" onclick="return confirm('ต้องการจบการพบปะหรือไม่?')">🔒 จบการสนทนา</a></p>
<?php endif; ?>

<div class="chat-box" id="chatBox">
    <?php while ($c = $chat_result->fetch_assoc()): ?>
        <div class="message <?= htmlspecialchars($c['role']) ?>">
            <strong><?= htmlspecialchars($c['fullname']) ?></strong>
            <small style="color:#666;display:block"><?= htmlspecialchars($c['created_at']) ?></small>
            <div><?= nl2br(htmlspecialchars($c['message'])) ?></div>
            <?php if ($c['file_path']): ?>
                <div><a href="<?= htmlspecialchars($c['file_path']) ?>" target="_blank">📎 ดาวน์โหลดไฟล์</a></div>
            <?php endif; ?>
        </div>
    <?php endwhile; ?>
</div>

<?php if (!$is_closed || $role === 'teacher'): ?>
<form method="POST" enctype="multipart/form-data" style="margin-top:12px">
    <?php if ($is_closed): ?>
        <div class="notice">ห้องนี้ถูกปิดแล้ว แต่ **อาจารย์ยังส่งไฟล์เพิ่มเติมได้**</div>
    <?php endif; ?>

    <textarea name="message" style="width:100%;height:70px;padding:8px" placeholder="พิมพ์ข้อความ..."></textarea>
    <input type="file" name="chat_file">
    <button type="submit" class="btn btn-blue" style="float:right">ส่งข้อความ</button>
</form>
<?php else: ?>
    <div class="notice">🔒 ห้องถูกปิดแล้ว — ไม่สามารถส่งข้อความได้</div>
<?php endif; ?>

<p style="margin-top:20px;"><a href="meeting_list.php?group_id=<?= $group_id ?>" class="btn btn-blue">⬅ กลับไปหน้ารายการพบปะ</a></p>
</div>

<script>
let box = document.getElementById("chatBox");
if (box) box.scrollTop = box.scrollHeight;
</script>

</body>
</html>
