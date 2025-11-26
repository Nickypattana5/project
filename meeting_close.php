<?php
// meeting_close.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'student';
$meeting_id = intval($_GET['meeting_id'] ?? 0);
$group_id = intval($_GET['group_id'] ?? 0);

if ($meeting_id <= 0 || $group_id <= 0) die("Invalid");

$chk = $conn->prepare("SELECT pm.group_id, pg.advisor_id FROM project_meetings pm JOIN project_groups pg ON pm.group_id = pg.id WHERE pm.id = ? LIMIT 1");
$chk->bind_param("i",$meeting_id); $chk->execute();
$r = $chk->get_result()->fetch_assoc();
if (!$r) die("ไม่พบ meeting");
if ($role !== 'teacher' || $r['advisor_id'] != $user_id) die("เฉพาะอาจารย์ที่ปรึกษาสามารถปิดการพบปะได้");

// ปิด
$u = $conn->prepare("UPDATE project_meetings SET status = 'closed', closed_at = NOW() WHERE id = ?");
$u->bind_param("i",$meeting_id); $u->execute();

header("Location: meeting_list.php?group_id=".$r['group_id']);
exit;
