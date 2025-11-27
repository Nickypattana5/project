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

// 1. ตรวจว่ากลุ่มมีอยู่จริง และผู้ร้องขอเป็นสมาชิก
$chk = $conn->prepare("
    SELECT pm.id, pg.advisor_id 
    FROM project_members pm
    JOIN project_groups pg ON pm.group_id = pg.id
    WHERE pm.group_id = ? AND pm.student_id = ? 
    LIMIT 1
");
$chk->bind_param("ii", $group_id, $student_id);
$chk->execute();
$res = $chk->get_result()->fetch_assoc();

if (!$res) {
    die("❌ คุณไม่ได้เป็นสมาชิกกลุ่มนี้ หรือไม่พบกลุ่ม");
}

// 2. ตรวจเงื่อนไข: ต้องมีที่ปรึกษาแล้วเท่านั้น
if (empty($res['advisor_id'])) {
    echo "<script>
        alert('⚠️ ไม่สามารถขออนุมัติได้!\\nกรุณาเชิญอาจารย์ที่ปรึกษาและให้อาจารย์ตอบรับเข้ากลุ่มก่อน');
        window.location.href = 'group_chat.php?id=$group_id&channel=admin';
    </script>";
    exit;
}

// 🔥 3. เช็คประวัติคำขอ (Logic Upsert: ถ้ามีอยู่แล้วให้แก้, ถ้าไม่มีให้สร้างใหม่)
$check_req = $conn->prepare("SELECT id FROM project_approval_requests WHERE group_id = ? LIMIT 1");
$check_req->bind_param("i", $group_id);
$check_req->execute();
$existing_req = $check_req->get_result()->fetch_assoc();

if ($existing_req) {
    // ✅ กรณี A: เคยส่งแล้ว (อาจจะโดน Reject มา) -> ให้ UPDATE ของเดิมกลับมาเป็น Pending
    $stmt = $conn->prepare("
        UPDATE project_approval_requests 
        SET status = 'pending', 
            requested_by = ?, 
            created_at = NOW(), 
            admin_id = NULL, 
            note = NULL 
        WHERE id = ?
    ");
    $stmt->bind_param("ii", $student_id, $existing_req['id']);
    $ok = $stmt->execute();
} else {
    // ✅ กรณี B: ไม่เคยส่งมาก่อน -> INSERT ใหม่
    $stmt = $conn->prepare("
        INSERT INTO project_approval_requests (group_id, requested_by, status, created_at) 
        VALUES (?, ?, 'pending', NOW())
    ");
    $stmt->bind_param("ii", $group_id, $student_id);
    $ok = $stmt->execute();
}

if (!$ok) {
    die("❌ เกิดข้อผิดพลาด: " . $conn->error);
}

// 4. อัปเดตสถานะกลุ่มเป็น pending
$u = $conn->prepare("UPDATE project_groups SET status = 'pending' WHERE id = ?");
$u->bind_param("i", $group_id);
$u->execute();

// 5. แจ้งเตือนแอดมิน (ID 1 หรือจะวนลูปหา Admin ทุกคนก็ได้)
$admin_id = 1;
$msg = "มีคำขออนุมัติโครงงาน (ส่งใหม่/แก้ไข) จากกลุ่ม ID {$group_id}";
$n = $conn->prepare("INSERT INTO notifications (receiver_id, sender_id, type, group_id, message, is_read, created_at) VALUES (?, ?, 'invite_admin', ?, ?, 0, NOW())");
$n->bind_param("iiis", $admin_id, $student_id, $group_id, $msg);
$n->execute();

header("Location: group_chat.php?id={$group_id}&channel=admin");
exit;
?>