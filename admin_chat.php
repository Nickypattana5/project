<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// ดึงรายการกลุ่มทั้งหมด
$groups = $conn->query("
    SELECT g.id, g.project_name, u.fullname AS advisor_name
    FROM project_groups g
    LEFT JOIN users u ON g.advisor_id = u.id
    ORDER BY g.id DESC
");
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>📁 แชททั้งหมดของนิสิต</title>
<style>
body { font-family:sans-serif; background:#f4f4f4; padding:20px }
.container { background:white; padding:20px; border-radius:10px; max-width:900px; margin:auto }
.btn { padding:8px 12px; background:#007bff; color:white; text-decoration:none; border-radius:6px }
table { width:100%; border-collapse:collapse; margin-top:12px }
td, th { padding:10px; border-bottom:1px solid #ddd }
</style>
</head>

<body>
<div class="container">
<h2>📁 รายการแชทนิสิตทั้งหมด</h2>

<table>
<tr>
    <th>ชื่อโครงงาน</th>
    <th>อาจารย์ที่ปรึกษา</th>
    <th>ตัวเลือก</th>
</tr>

<?php while($g = $groups->fetch_assoc()): ?>
<tr>
    <td><?= htmlspecialchars($g['project_name']) ?></td>
    <td><?= htmlspecialchars($g['advisor_name']) ?></td>
    <td>
        <a class="btn" href="group_chat.php?id=<?= $g['id'] ?>&channel=group">
            📄 เปิดแชทกลุ่ม
        </a>
        <a class="btn" href="group_chat.php?id=<?= $g['id'] ?>&channel=admin">
            🛠 เปิดแชทแอดมิน
        </a>
    </td>
</tr>
<?php endwhile; ?>

</table>

</div>
</body>
</html>
