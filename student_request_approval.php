<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$student_id = (int)$_SESSION['user_id'];
$group_id = intval($_GET['group_id'] ?? 0);

// ตรวจว่ากลุ่มมีอยู่และผู้ร้องเป็นสมาชิก
$chk = $conn->prepare("SELECT id FROM project_members WHERE group_id = ? AND student_id = ? LIMIT 1");
$chk->bind_param("ii", $group_id, $student_id);
$chk->execute();
if ($chk->get_result()->num_rows == 0) {
    die("❌ คุณไม่ได้เป็นสมาชิกกลุ่มนี้");
}

// ตรวจว่ามีคำขอซ้ำหรือยัง
$dup = $conn->prepare("SELECT id FROM project_approval_requests WHERE group_id = ? AND status = 'pending' LIMIT 1");
$dup->bind_param("i", $group_id);
$dup->execute();
if ($dup->get_result()->num_rows > 0) {
    header("Location: group_chat.php?id={$group_id}&channel=admin");
    exit;
}

// insert request
$ins = $conn->prepare("INSERT INTO project_approval_requests (group_id, requested_by, status, created_at) VALUES (?, ?, 'pending', NOW())");
$ins->bind_param("ii", $group_id, $student_id);
$ok = $ins->execute();

if (!$ok) {
    die("❌ เกิดข้อผิดพลาด: " . $conn->error);
}

// update project_groups: lock group chat, unlock admin chat, set pending
$u = $conn->prepare("UPDATE project_groups SET group_chat_locked = 1, admin_chat_unlocked = 1, status = 'pending' WHERE id = ?");
$u->bind_param("i", $group_id);
$u->execute();

// create a notification row (optional) - broadcast to admins: here we choose receiver_id = 1 (assumed admin account)
// better: create a list of admins and insert for each. We'll insert to admin id = 1 as example
$admin_id = 1;
$msg = "มีคำขออนุมัติโครงงานสำหรับกลุ่ม ID {$group_id}";
$n = $conn->prepare("INSERT INTO notifications (receiver_id, sender_id, type, group_id, message) VALUES (?, ?, 'invite_admin', ?, ?)");
$n->bind_param("iiis", $admin_id, $student_id, $group_id, $msg);
$n->execute();

header("Location: group_chat.php?id={$group_id}&channel=admin");
exit;
