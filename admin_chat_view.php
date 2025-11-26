<?php
session_start();
include 'db_connect.php';

if ($_SESSION['role'] !== 'admin') {
    die("❌ เฉพาะผู้ดูแลระบบเท่านั้น");
}

$group_id = intval($_GET['group_id'] ?? 0);

// โหลดข้อมูลกลุ่ม
$stmt = $conn->prepare("SELECT project_name, status FROM project_groups WHERE id = ?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
if (!$group) die("❌ ไม่พบกลุ่ม");

$status = $group['status'];

// กฎใหม่: ถ้า approved → แชท admin ถูกล็อค (เหมือนฝั่งนิสิต)
$chat_locked = ($status === 'approved');

// โหลดข้อความ channel admin
$chat = $conn->prepare("
    SELECT c.*, u.fullname, u.role
    FROM project_chat c
    JOIN users u ON c.sender_id = u.id
    WHERE c.group_id = ? AND c.channel='admin'
    ORDER BY c.created_at ASC
");
$chat->bind_param("i", $group_id);
$chat->execute();
$result = $chat->get_result();

// ส่งข้อความ (ถ้าไม่ถูกล็อค)
if ($_SERVER['REQUEST_METHOD'] == "POST" && !$chat_locked) {

    $msg = trim($_POST['message'] ?? '');
    $file_path = null;

    if (!empty($_FILES['file']['name'])) {
        $dir = "uploads/admin_chat/";
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $filename = time() . "_" . $_FILES['file']['name'];
        $target = $dir . $filename;

        if (move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
            $file_path = $target;
        }
    }

    if ($msg !== '' || $file_path !== null) {
        $ins = $conn->prepare("
            INSERT INTO project_chat (group_id, sender_id, message, file_path, channel)
            VALUES (?, ?, ?, ?, 'admin')
        ");
        $sender = $_SESSION['user_id'];
        $ins->bind_param("iiss", $group_id, $sender, $msg, $file_path);
        $ins->execute();
    }

    header("Location: admin_chat_view.php?group_id=$group_id");
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>แชทแอดมิน - <?= htmlspecialchars($group['project_name']) ?></title>
<style>
body { font-family:sans-serif; background:#f4f4f4; padding:20px;}
.container { max-width:900px; background:white; margin:auto; padding:20px; border-radius:10px;}
.chat-box { background:#f9f9f9; height:450px; overflow-y:auto; padding:10px; border:1px solid #ddd; border-radius:8px;}
.msg { padding:10px; margin-bottom:10px; border-radius:8px; }
.msg.admin { background:#e9f7fe; }
.msg.student { background:#f1f1f1; }
.notice { background:#fff3cd; padding:10px; border-radius:6px; margin-top:10px; }
.back { display:inline-block; margin-top:15px; padding:8px 12px; background:#555; color:white; border-radius:6px; text-decoration:none; }
</style>
</head>
<body>

<div class="container">

<h2>📥 แชทกับกลุ่ม: <?= htmlspecialchars($group['project_name']) ?></h2>

<?php if ($chat_locked): ?>
    <div class="notice">🔒 กลุ่มนี้ได้รับอนุมัติแล้ว — แชทแอดมินถูกปิด</div>
<?php endif; ?>

<div class="chat-box" id="chat">
<?php while($c = $result->fetch_assoc()): ?>
    <div class="msg <?= $c['role'] ?>">
        <strong><?= htmlspecialchars($c['fullname']) ?></strong><br>
        <small><?= $c['created_at'] ?></small>
        <div><?= nl2br(htmlspecialchars($c['message'])) ?></div>
        <?php if ($c['file_path']): ?>
            <a href="<?= $c['file_path'] ?>" target="_blank">📎 ไฟล์</a>
        <?php endif; ?>
    </div>
<?php endwhile; ?>
</div>

<?php if (!$chat_locked): ?>
<form method="POST" enctype="multipart/form-data" style="margin-top:10px;">
    <textarea name="message" style="width:100%; height:70px;"></textarea>
    <input type="file" name="file">
    <button type="submit" style="margin-top:5px;">ส่งข้อความ</button>
</form>
<?php endif; ?>

<a class="back" href="admin_chat_list.php">⬅ กลับ</a>

</div>

<script>
let c = document.getElementById('chat');
c.scrollTop = c.scrollHeight;
</script>

</body>
</html>
