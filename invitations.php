<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header("Location: login.php");
    exit;
}

$student_id = $_SESSION['user_id'];
$message = "";

// ถ้ามีการตอบรับหรือปฏิเสธ
if (isset($_GET['action'], $_GET['group_id'])) {
    $group_id = intval($_GET['group_id']);
    $action = $_GET['action'];

    if ($action == 'accept') {
        $stmt = $conn->prepare("UPDATE project_members SET is_confirmed = 1 WHERE group_id = ? AND student_id = ?");
        $stmt->bind_param("ii", $group_id, $student_id);
        $stmt->execute();
        $message = "✅ ยืนยันเข้าร่วมกลุ่มแล้ว!";
    } elseif ($action == 'decline') {
        $stmt = $conn->prepare("DELETE FROM project_members WHERE group_id = ? AND student_id = ?");
        $stmt->bind_param("ii", $group_id, $student_id);
        $stmt->execute();
        $message = "❌ ปฏิเสธคำเชิญเรียบร้อยแล้ว!";
    }
}

// ดึงคำเชิญทั้งหมดที่ยังไม่ยืนยัน
$stmt = $conn->prepare("
SELECT g.project_name, g.id AS group_id, u.fullname AS inviter
FROM project_members m
JOIN project_groups g ON m.group_id = g.id
JOIN users u ON m.invited_by = u.id
WHERE m.student_id = ? AND m.is_confirmed = 0
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>คำเชิญเข้ากลุ่ม</title>
<style>
body { font-family:sans-serif; background:#f4f4f4; padding:20px; }
table { width:80%; margin:auto; border-collapse:collapse; background:white; box-shadow:0 0 10px rgba(0,0,0,0.1); }
th, td { border:1px solid #ccc; padding:10px; text-align:center; }
th { background:#007bff; color:white; }
.msg { text-align:center; color:green; font-weight:bold; }
a.btn { padding:5px 10px; border-radius:5px; text-decoration:none; color:white; }
a.accept { background:#28a745; }
a.decline { background:#dc3545; }
</style>
</head>
<body>

<h2 style="text-align:center;">📩 คำเชิญเข้าร่วมกลุ่มโครงงาน</h2>
<p class="msg"><?= $message ?></p>

<table>
<tr><th>ชื่อโครงงาน</th><th>ผู้เชิญ</th><th>การดำเนินการ</th></tr>
<?php if ($result->num_rows > 0): ?>
    <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($row['project_name']) ?></td>
            <td><?= htmlspecialchars($row['inviter']) ?></td>
            <td>
                <a href="?action=accept&group_id=<?= $row['group_id'] ?>" class="btn accept">✔ ยืนยัน</a>
                <a href="?action=decline&group_id=<?= $row['group_id'] ?>" class="btn decline">❌ ปฏิเสธ</a>
            </td>
        </tr>
    <?php endwhile; ?>
<?php else: ?>
    <tr><td colspan="3">ไม่มีคำเชิญในขณะนี้</td></tr>
<?php endif; ?>
</table>

<p style="text-align:center;"><a href="dashboard.php">⬅ กลับหน้าหลัก</a></p>

</body>
</html>
