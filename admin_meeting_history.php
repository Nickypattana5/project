<?php
// admin_meeting_history.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT pm.*, pg.project_name, pg.created_by
    FROM project_meetings pm
    JOIN project_groups pg ON pm.group_id = pg.id
    ORDER BY pm.created_at DESC
");
$stmt->execute();
$res = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="th">
<head><meta charset="utf-8"><title>ประวัติ Meeting (Admin)</title></head>
<body>
<h2>ประวัติ Meeting (Admin)</h2>
<table border="1" cellpadding="8" cellspacing="0">
<tr><th>ID</th><th>กลุ่ม</th><th>สัปดาห์</th><th>สถานะ</th><th>เริ่ม</th><th>จบ</th><th>ดู</th></tr>
<?php while($r = $res->fetch_assoc()): ?>
<tr>
  <td><?= $r['id'] ?></td>
  <td><?= htmlspecialchars($r['project_name']) ?> (G<?= $r['group_id'] ?>)</td>
  <td><?= $r['week_number'] ?></td>
  <td><?= $r['status'] ?></td>
  <td><?= $r['created_at'] ?></td>
  <td><?= $r['closed_at'] ?></td>
  <td><a href="advisor_meeting_chat.php?meeting_id=<?= $r['id'] ?>&readonly=1">ดูแบบอ่านอย่างเดียว</a></td>
</tr>
<?php endwhile; ?>
</table>

<p><a href="admin_dashboard.php">⬅ กลับ</a></p>
</body>
</html>
