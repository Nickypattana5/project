<?php
session_start();
include 'db_connect.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') { header("Location: login.php"); exit; }
$meeting_id = intval($_GET['meeting_id'] ?? 0);
$group_id = intval($_GET['group_id'] ?? 0);

// verify advisor owns the meeting (optional)
// update meeting
$u = $conn->prepare("UPDATE project_meetings SET is_active = 0, ended_at = NOW() WHERE id = ?");
$u->bind_param("i", $meeting_id); $u->execute();

header("Location: group_chat.php?id={$group_id}&channel=meeting&meeting_id={$meeting_id}");
exit;
