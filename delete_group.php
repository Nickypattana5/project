<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

// ตรวจสอบสิทธิ์นิสิต
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header("Location: login.php");
    exit;
}

$student_id = intval($_SESSION['user_id']);
$group_id = intval($_GET['group_id'] ?? 0);

if ($group_id <= 0) {
    die("❌ ไม่พบข้อมูลกลุ่ม");
}

// 1. ตรวจสอบว่าเป็นหัวหน้ากลุ่มหรือไม่
$check = $conn->prepare("SELECT id, project_name FROM project_groups WHERE id = ?");
$check->bind_param("i", $group_id);
$check->execute();
$group = $check->get_result()->fetch_assoc();

if (!$group) {
    die("❌ ไม่พบกลุ่มนี้");
}

$chk_leader = $conn->prepare("SELECT is_leader FROM project_members WHERE group_id = ? AND student_id = ?");
$chk_leader->bind_param("ii", $group_id, $student_id);
$chk_leader->execute();
$is_leader = $chk_leader->get_result()->fetch_assoc()['is_leader'] ?? 0;

if ($is_leader != 1) {
    echo "<script>alert('❌ คุณไม่ใช่หัวหน้ากลุ่ม ไม่สามารถลบได้'); window.location='my_groups.php';</script>";
    exit;
}

// 2. ลบไฟล์จริงในโฟลเดอร์ (Optional but recommended)
// ลบไฟล์ Proposal
$f_q = $conn->prepare("SELECT filepath FROM project_files WHERE group_id = ?");
$f_q->bind_param("i", $group_id);
$f_q->execute();
$res_f = $f_q->get_result();
while ($f = $res_f->fetch_assoc()) {
    if (file_exists($f['filepath'])) { unlink($f['filepath']); }
}

// ลบไฟล์ในแชทกลุ่ม
$c_q = $conn->prepare("SELECT file_path FROM project_chat WHERE group_id = ? AND file_path IS NOT NULL");
$c_q->bind_param("i", $group_id);
$c_q->execute();
$res_c = $c_q->get_result();
while ($c = $res_c->fetch_assoc()) {
    if (file_exists($c['file_path'])) { unlink($c['file_path']); }
}

// 3. ลบข้อมูลใน Database (เรียงลำดับเพื่อป้องกัน Error Foreign Key)
// หมายเหตุ: ถ้า Database ตั้ง ON DELETE CASCADE ไว้แล้ว คำสั่งพวกนี้อาจไม่จำเป็น แต่เขียนไว้กันเหนียวดีที่สุดครับ

$conn->query("DELETE FROM project_files WHERE group_id = $group_id");
$conn->query("DELETE FROM project_chat WHERE group_id = $group_id");
$conn->query("DELETE FROM project_meetings WHERE group_id = $group_id"); // และ meeting_chat ถ้ามี
$conn->query("DELETE FROM advisor_invites WHERE group_id = $group_id");
$conn->query("DELETE FROM project_approval_requests WHERE group_id = $group_id");
$conn->query("DELETE FROM notifications WHERE group_id = $group_id");
$conn->query("DELETE FROM project_members WHERE group_id = $group_id");

// สุดท้าย ลบกลุ่ม
$del = $conn->prepare("DELETE FROM project_groups WHERE id = ?");
$del->bind_param("i", $group_id);

if ($del->execute()) {
    header("Location: my_groups.php?msg=group_deleted");
    exit;
} else {
    echo "❌ ลบไม่สำเร็จ: " . $conn->error;
}
?>