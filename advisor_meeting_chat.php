<?php
// advisor_meeting_chat.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'student';
$meeting_id = intval($_GET['meeting_id'] ?? 0);
$readonly = isset($_GET['readonly']) ? true : false;

if ($meeting_id <= 0) die("Invalid meeting");

// โหลด meeting + group info
$stmt = $conn->prepare("SELECT pm.*, pg.project_name, pg.advisor_id FROM project_meetings pm JOIN project_groups pg ON pm.group_id = pg.id WHERE pm.id = ? LIMIT 1");
$stmt->bind_param("i",$meeting_id); $stmt->execute();
$meeting = $stmt->get_result()->fetch_assoc();
if (!$meeting) die("ไม่พบการพบปะนี้");

$group_id = $meeting['group_id'];

// ตรวจสิทธิ์: สมาชิกนิสิต หรือ อาจารย์ที่ปรึกษา หรือ admin
$allowed = false;
if ($role === 'admin') $allowed = true;
if ($role === 'teacher' && $meeting['advisor_id'] == $user_id) $allowed = true;
$chk = $conn->prepare("SELECT id FROM project_members WHERE group_id = ? AND student_id = ? LIMIT 1");
$chk->bind_param("ii",$group_id,$user_id); $chk->execute();
if ($chk->get_result()->num_rows > 0) $allowed = true;
if (!$allowed) die("❌ คุณไม่มีสิทธิ์เข้าห้องนี้");

// โหลด chat
$ch = $conn->prepare("SELECT mc.*, u.fullname FROM meeting_chat mc JOIN users u ON mc.sender_id = u.id WHERE mc.meeting_id = ? ORDER BY mc.created_at ASC");
$ch->bind_param("i",$meeting_id); $ch->execute();
$chat_result = $ch->get_result();

// กำหนดสิทธิ์ส่งข้อความ/ไฟล์
$can_send_text = false;
$can_send_file = false;

if ($meeting['status'] === 'open' && !$readonly) {
    // ถ้า meeting เปิด ทุกคนที่เข้าร่วมสามารถพิมพ์ (student & advisor)
    $can_send_text = true;
    $can_send_file = true;
} else {
    // meeting ปิด -> อาจารย์ยังสามารถพิมพ์ดูแลได้, นิสิตไม่พิมแต่ยังอัปโหลดไฟล์ได้
    if ($role === 'teacher' && $meeting['advisor_id'] == $user_id && !$readonly) {
        $can_send_text = true;
        $can_send_file = true;
    } else {
        $can_send_text = false;
        // นักศึกษาสามารถอัปโหลดไฟล์หลังปิด (ตามข้อกำหนด)
        $can_send_file = true;
    }
    // admin readonly view ไม่ส่ง
    if ($role === 'admin' || $readonly) {
        $can_send_text = false;
        $can_send_file = false;
    }
}

// ส่งข้อความ/ไฟล์ (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!($can_send_text || $can_send_file)) {
        $_SESSION['flash_msg'] = "คุณไม่มีสิทธิ์ส่งข้อความหรือไฟล์ในขณะนี้";
        header("Location: advisor_meeting_chat.php?meeting_id=".$meeting_id);
        exit;
    }

    $text = trim($_POST['message'] ?? '');
    $file_path = null;
    if (!empty($_FILES['chat_file']['name'])) {
        $dir = __DIR__ . "/uploads/meetings/";
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $fname = time()."_".basename($_FILES['chat_file']['name']);
        $target = $dir.$fname;
        if (move_uploaded_file($_FILES['chat_file']['tmp_name'], $target)) {
            $file_path = "uploads/meetings/".$fname;
        }
    }

    // ถ้า meeting ปิดแล้ว ห้ามส่งข้อความ (ยกเว้น advisor)
    if ($meeting['status'] !== 'open' && $text !== '' && !($role === 'teacher' && $meeting['advisor_id'] == $user_id)) {
        $_SESSION['flash_msg'] = "ห้ามส่งข้อความใน meeting ที่ปิดแล้ว";
        header("Location: advisor_meeting_chat.php?meeting_id=".$meeting_id);
        exit;
    }

    if ($text !== '' || $file_path !== null) {
        $ins = $conn->prepare("INSERT INTO meeting_chat (meeting_id, sender_id, message, file_path) VALUES (?, ?, ?, ?)");
        $ins->bind_param("iiss", $meeting_id, $user_id, $text, $file_path);
        $ins->execute();
    }

    header("Location: advisor_meeting_chat.php?meeting_id=".$meeting_id);
    exit;
}

$flash = $_SESSION['flash_msg'] ?? null;
unset($_SESSION['flash_msg']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>Meeting: Week <?= htmlspecialchars($meeting['week_number']) ?></title>
<style>
body{font-family:sans-serif;background:#f4f4f4;padding:20px}
.container{max-width:900px;margin:auto;background:#fff;padding:20px;border-radius:8px}
.chat{height:400px;overflow:auto;background:#fafafa;padding:12px;border-radius:8px;border:1px solid #eee}
.msg{padding:8px;margin:8px 0;border-radius:6px}
.msg strong{display:block}
.notice{background:#fff3cd;padding:8px;border-radius:6px}
.btn{padding:6px 10px;border-radius:6px;text-decoration:none;background:#007bff;color:#fff}
</style>
</head>
<body>
<div class="container">
  <h2>การพบปะ: กลุ่ม <?= htmlspecialchars($meeting['group_id']) ?> — สัปดาห์ที่ <?= htmlspecialchars($meeting['week_number']) ?></h2>
  <p>สถานะ: <strong><?= htmlspecialchars($meeting['status']) ?></strong>
     <?= $meeting['closed_at'] ? " | จบ: ".$meeting['closed_at'] : "" ?></p>

  <?php if ($flash): ?><div class="notice"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

  <div class="chat">
    <?php while($r = $chat_result->fetch_assoc()): ?>
      <div class="msg">
        <strong><?= htmlspecialchars($r['fullname']) ?></strong>
        <small><?= $r['created_at'] ?></small>
        <div><?= nl2br(htmlspecialchars($r['message'])) ?></div>
        <?php if ($r['file_path']): ?>
          <div><a href="<?= htmlspecialchars($r['file_path']) ?>" target="_blank">📎 ดาวน์โหลดไฟล์</a></div>
        <?php endif; ?>
      </div>
    <?php endwhile; ?>
  </div>

  <?php if ($can_send_text || $can_send_file): ?>
    <form method="POST" enctype="multipart/form-data" style="margin-top:12px">
      <?php if ($can_send_text): ?>
        <textarea name="message" rows="3" style="width:100%;padding:8px" placeholder="พิมพ์ข้อความ..."></textarea>
      <?php else: ?>
        <div class="notice">🔒 ขณะนี้ไม่สามารถพิมพ์ข้อความได้ (meeting ปิดแล้ว)</div>
      <?php endif; ?>

      <div style="margin-top:8px">
        <?php if ($can_send_file): ?>
          <input type="file" name="chat_file">
        <?php endif; ?>
        <button class="btn" style="float:right">ส่ง</button>
      </div>
    </form>
  <?php else: ?>
    <div class="notice" style="margin-top:12px">คุณไม่มีสิทธิ์ส่งข้อความหรือไฟล์ใน meeting นี้</div>
  <?php endif; ?>

  <p style="margin-top:12px"><a href="meeting_list.php?group_id=<?= $meeting['group_id'] ?>">⬅ ย้อนกลับ</a></p>
</div>
</body>
</html>
