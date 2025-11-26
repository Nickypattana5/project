<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'student';
$group_id = intval($_GET['group_id'] ?? 0);

// ดึงข้อมูลกลุ่ม
$stmt = $conn->prepare("
    SELECT g.project_name, g.advisor_id, u.fullname AS advisor_name
    FROM project_groups g
    LEFT JOIN users u ON g.advisor_id = u.id
    WHERE g.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
if (!$group) die("❌ ไม่พบกลุ่ม");

// ตรวจสิทธิ์ เข้าดู: ควรเป็นสมาชิกหรืออาจารย์ที่ปรึกษา
$allow = false;
if ($role === 'teacher' && $group['advisor_id'] == $user_id) $allow = true;
else {
    $chk = $conn->prepare("SELECT id FROM project_members WHERE group_id = ? AND student_id = ? LIMIT 1");
    $chk->bind_param("ii", $group_id, $user_id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) $allow = true;
}
if (!$allow) die("❌ ไม่มีสิทธิ์เข้าหน้านี้");

// ดึงรายการ meeting
$sql = "
    SELECT *
    FROM project_meetings
    WHERE group_id = ?
    ORDER BY created_at DESC
";
$list = $conn->prepare($sql);
$list->bind_param("i", $group_id);
$list->execute();
$meetings = $list->get_result();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>📅 ประวัติการพบปะอาจารย์</title>
<style>
body {font-family:sans-serif; background:#f4f4f4; padding:20px;}
.container {max-width:900px; margin:auto; background:white; padding:20px; border-radius:10px;}
.btn { padding:8px 12px; border-radius:6px; text-decoration:none; }
.btn-blue { background:#007bff; color:white; }
.btn-green { background:#28a745; color:white; }
table { width:100%; border-collapse:collapse; margin-top:15px; }
th, td { padding:10px; border-bottom:1px solid #ddd; }
</style>
</head>
<body>

<div class="container">
    <h2>📅 ประวัติการพบปะอาจารย์ – <?= htmlspecialchars($group['project_name']) ?></h2>

    <p><strong>อาจารย์ที่ปรึกษา:</strong> <?= htmlspecialchars($group['advisor_name'] ?: '-') ?></p>

    <?php if ($role === 'teacher' && $group['advisor_id'] == $user_id): ?>
        <p>
            <a class="btn btn-green" href="meeting_create.php?group_id=<?= $group_id ?>">➕ สร้างห้องพบปะใหม่</a>
        </p>
    <?php endif; ?>

    <table>
        <tr>
            <th>สัปดาห์ที่</th>
            <th>สถานะ</th>
            <th>เริ่มเมื่อ</th>
            <th>จบเมื่อ</th>
            <th>ตัวเลือก</th>
        </tr>

        <?php while ($m = $meetings->fetch_assoc()): ?>
            <tr>
                <td>สัปดาห์ที่ <?= htmlspecialchars($m['week_number']) ?></td>
                <td><?= $m['is_closed'] ? "✔ ปิดแล้ว" : "🔵 เปิดอยู่" ?></td>
                <td><?= htmlspecialchars($m['started_at']) ?></td>
                <td><?= htmlspecialchars($m['ended_at'] ?: "-") ?></td>
                <td>
                    <a class="btn btn-blue" href="meeting_chat.php?meeting_id=<?= $m['id'] ?>">💬 เข้าห้องแชท</a>
                </td>
            </tr>
        <?php endwhile; ?>
    </table>

    <p style="margin-top:15px;"><a class="btn btn-blue" href="group_chat.php?id=<?= $group_id ?>">⬅ กลับไปหน้ากลุ่ม</a></p>
</div>

</body>
</html>
