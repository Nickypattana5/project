<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$notif_id = intval($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

// ดึงข้อมูลแจ้งเตือน
$stmt = $conn->prepare("SELECT * FROM notifications WHERE id = ? AND receiver_id = ? AND type = 'invite_group'");
$stmt->bind_param("ii", $notif_id, $user_id);
$stmt->execute();
$notif = $stmt->get_result()->fetch_assoc();

if (!$notif) {
    die("❌ ไม่พบคำเชิญหรือหมดอายุแล้ว");
}

$group_id = $notif['group_id'];

// ถ้ากดยืนยันเข้ากลุ่ม
if ($action == 'accept') {
    // ตรวจว่านิสิตอยู่ในกลุ่มอยู่แล้วหรือยัง
    $check = $conn->prepare("SELECT COUNT(*) AS c FROM project_members WHERE student_id = ?");
    $check->bind_param("i", $user_id);
    $check->execute();
    $hasGroup = $check->get_result()->fetch_assoc()['c'];

    if ($hasGroup > 0) {
        echo "<script>alert('⚠️ คุณมีกลุ่มอยู่แล้ว'); window.location='dashboard.php';</script>";
        exit;
    }

    // เพิ่มเข้ากลุ่ม
    $insert = $conn->prepare("INSERT INTO project_members (group_id, student_id, is_confirmed, invited_by, joined_at) VALUES (?, ?, 1, ?, NOW())");
    $insert->bind_param("iii", $group_id, $user_id, $notif['sender_id']);
    $insert->execute();

    // เปลี่ยนสถานะแจ้งเตือนเป็นอ่านแล้ว
    $conn->query("UPDATE notifications SET is_read = 1 WHERE id = $notif_id");

    echo "<script>alert('✅ เข้าร่วมกลุ่มเรียบร้อยแล้ว!'); window.location='my_groups.php';</script>";
    exit;
}

// ถ้ากดปฏิเสธ
elseif ($action == 'reject') {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE id = $notif_id");
    echo "<script>alert('❌ คุณปฏิเสธคำเชิญแล้ว'); window.location='dashboard.php';</script>";
    exit;
}

// ถ้า action ไม่ถูกต้อง
else {
    echo "<script>alert('❌ การดำเนินการไม่ถูกต้อง'); window.location='dashboard.php';</script>";
}
?>
