<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

// 1. ตรวจสอบสิทธิ์นิสิตเท่านั้น
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    die("❌ คุณไม่มีสิทธิ์ลบกลุ่มนี้");
}

$student_id = intval($_SESSION['user_id']);
$group_id = intval($_GET['group_id'] ?? 0);

if ($group_id <= 0) {
    die("❌ ไม่พบข้อมูลกลุ่มที่คุณต้องการลบ");
}

// 2. ตรวจสอบว่าเป็นหัวหน้ากลุ่มหรือไม่
$checkLeader = $conn->prepare("
    SELECT is_leader 
    FROM project_members 
    WHERE group_id = ? AND student_id = ?
");
$checkLeader->bind_param("ii", $group_id, $student_id);
$checkLeader->execute();
$res = $checkLeader->get_result()->fetch_assoc();

if (!$res || $res['is_leader'] != 1) {
    die("❌ เฉพาะหัวหน้ากลุ่มเท่านั้นที่ลบกลุ่มได้");
}

// 3. 🔥 เริ่มลบข้อมูลที่เชื่อมโยงกับกลุ่ม

// ลบแชทของกลุ่ม
$conn->query("DELETE FROM project_chat WHERE group_id = $group_id");

// ลบการเชิญอาจารย์ที่ปรึกษา
$conn->query("DELETE FROM advisor_invites WHERE group_id = $group_id");

// ลบสมาชิกในกลุ่ม
$conn->query("DELETE FROM project_members WHERE group_id = $group_id");

// ลบคำขออนุมัติกลุ่ม (ถ้ามี)
$conn->query("DELETE FROM project_approval_requests WHERE group_id = $group_id");

// ลบ meeting ของกลุ่ม
$conn->query("DELETE FROM project_meetings WHERE group_id = $group_id");

// ลบไฟล์แนบ (Proposal)
$conn->query("DELETE FROM project_files WHERE group_id = $group_id");

// ลบการแจ้งเตือนที่เกี่ยวกับกลุ่ม
$conn->query("DELETE FROM notifications WHERE group_id = $group_id");

// 4. ลบข้อมูลกลุ่มสุดท้าย
$delGroup = $conn->prepare("DELETE FROM project_groups WHERE id = ?");
$delGroup->bind_param("i", $group_id);

if ($delGroup->execute()) {
    // กลับหน้า my_groups พร้อมข้อความสำเร็จ
    header("Location: my_groups.php?msg=group_deleted");
    exit;
} else {
    echo "❌ เกิดข้อผิดพลาด: " . $conn->error;
}
?>