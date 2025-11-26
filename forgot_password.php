<?php
include 'db_connect.php';
$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $identifier = trim($_POST['identifier']); // อาจเป็นอีเมลหรือรหัสนิสิต

    // ค้นหาผู้ใช้จาก email หรือ student_id
    $stmt = $conn->prepare("SELECT id, fullname FROM users WHERE email = ? OR student_id = ?");
    $stmt->bind_param("ss", $identifier, $identifier);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        // ถ้ามีผู้ใช้ → ไปหน้าตั้งรหัสใหม่ พร้อมส่ง id ผ่าน URL (เพื่อความง่าย)
        header("Location: reset_password.php?id=" . $user['id']);
        exit;
    } else {
        $message = "❌ ไม่พบบัญชีในระบบ";
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ลืมรหัสผ่าน</title>
    <style>
        body { font-family:sans-serif; background:#f4f4f4; display:flex; justify-content:center; align-items:center; height:100vh; }
        form { background:white; padding:30px; border-radius:10px; width:350px; box-shadow:0 0 10px rgba(0,0,0,0.1); }
        input { width:100%; padding:8px; margin:10px 0; }
        button { width:100%; background:#007bff; color:white; padding:8px; border:none; border-radius:5px; cursor:pointer; }
        a { text-decoration:none; color:#007bff; }
        .msg { text-align:center; margin-top:10px; color:red; }
    </style>
</head>
<body>

<form method="POST">
    <h2>🔑 ลืมรหัสผ่าน</h2>
    <p>กรอกอีเมลหรือรหัสนิสิตของคุณ</p>
    <input type="text" name="identifier" placeholder="อีเมลหรือรหัสนิสิต" required>
    <button type="submit">ดำเนินการต่อ</button>
    <p class="msg"><?= $message ?></p>
    <p style="text-align:center;"><a href="login.php">⬅ กลับไปหน้าเข้าสู่ระบบ</a></p>
</form>

</body>
</html>
