<?php
session_start();
include 'db_connect.php';

// ตรวจสอบสิทธิ์เฉพาะอาจารย์
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'teacher') {
    header("Location: login.php");
    exit;
}

$teacher_id = $_SESSION['user_id'];
$msg = "";

// ดึงข้อมูลรายวิชาที่เลือกมาแก้ไข
if (!isset($_GET['id'])) {
    header("Location: manage_courses.php");
    exit;
}

$course_id = intval($_GET['id']);
$stmt = $conn->prepare("SELECT * FROM courses WHERE id = ? AND teacher_id = ?");
$stmt->bind_param("ii", $course_id, $teacher_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("ไม่พบรายวิชานี้ หรือคุณไม่มีสิทธิ์แก้ไข");
}

$course = $result->fetch_assoc();

// เมื่อกดบันทึก
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $course_code = trim($_POST['course_code']);
    $course_name = trim($_POST['course_name']);

    $update = $conn->prepare("UPDATE courses SET course_code = ?, course_name = ? WHERE id = ? AND teacher_id = ?");
    $update->bind_param("ssii", $course_code, $course_name, $course_id, $teacher_id);
    if ($update->execute()) {
        $msg = "✅ แก้ไขรายวิชาเรียบร้อยแล้ว!";
    } else {
        $msg = "❌ แก้ไขไม่สำเร็จ: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แก้ไขรายวิชา</title>
    <style>
        body {
            font-family: sans-serif;
            background: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        form {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            width: 350px;
        }
        input {
            display: block;
            width: 100%;
            margin: 10px 0;
            padding: 8px;
        }
        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 8px 15px;
            cursor: pointer;
            border-radius: 5px;
        }
        .msg {
            color: green;
        }
    </style>
</head>
<body>

<form method="POST">
    <h2>✏️ แก้ไขรายวิชา</h2>
    <input type="text" name="course_code" value="<?= htmlspecialchars($course['course_code']) ?>" required>
    <input type="text" name="course_name" value="<?= htmlspecialchars($course['course_name']) ?>" required>
    <button type="submit">บันทึกการแก้ไข</button>
    <p><a href="manage_courses.php">⬅ กลับ</a></p>
    <?php if ($msg): ?><p class="msg"><?= $msg ?></p><?php endif; ?>
</form>

</body>
</html>
