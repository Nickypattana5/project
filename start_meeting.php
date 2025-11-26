<?php
session_start();
include 'db_connect.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') { header("Location: login.php"); exit; }
$advisor_id = (int)$_SESSION['user_id'];
$group_id = intval($_GET['group_id'] ?? 0);

// verify advisor
$chk = $conn->prepare("SELECT advisor_id FROM project_groups WHERE id = ?");
$chk->bind_param("i", $group_id); $chk->execute();
$row = $chk->get_result()->fetch_assoc();
if (!$row || $row['advisor_id'] != $advisor_id) die("❌ คุณไม่ใช่อาจารย์ที่ปรึกษากลุ่มนี้");

// create meeting
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $week = intval($_POST['week'] ?? 1);
    $note = trim($_POST['note'] ?? null);
    $ins = $conn->prepare("INSERT INTO project_meetings (group_id, week, started_by, note) VALUES (?, ?, ?, ?)");
    $ins->bind_param("iiis", $group_id, $week, $advisor_id, $note);
    $ins->execute();
    $mid = $ins->insert_id;
    // redirect to meeting channel
    header("Location: group_chat.php?id={$group_id}&channel=meeting&meeting_id={$mid}");
    exit;
}
?>
<form method="POST">
  สัปดาห์: <input type="number" name="week" value="1" min="1" required>
  หมายเหตุ: <input type="text" name="note">
  <button type="submit">เริ่มการพบปะ</button>
</form>
