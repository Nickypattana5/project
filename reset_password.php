<?php
include 'db_connect.php';
$message = "";

// ตรวจว่ามี id ของผู้ใช้ไหม
if (!isset($_GET['id'])) {
    die("❌ ไม่มีข้อมูลผู้ใช้");
}
$user_id = intval($_GET['id']);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_password = trim($_POST['new_password']);
    $confirm = trim($_POST['confirm_password']);

    if ($new_password !== $confirm) {
        $message = "⚠️ รหัสผ่านไม่ตรงกัน";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashed, $user_id);
        if ($stmt->execute()) {
            $message = "✅ ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว! <a href='login.php'>เข้าสู่ระบบ</a>";
        } else {
            $message = "❌ เกิดข้อผิดพลาด: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ตั้งรหัสผ่านใหม่</title>
    <style>
        body { font-family:sans-serif; background:#f4f4f4; display:flex; justify-content:center; align-items:center; height:100vh; }
        form { background:white; padding:30px; border-radius:10px; width:350px; box-shadow:0 0 10px rgba(0,0,0,0.1); }
        input { width:100%; padding:8px; margin:10px 0; }
        button { width:100%; background:#28a745; color:white; padding:8px; border:none; border-radius:5px; cursor:pointer; }
        .msg { text-align:center; margin-top:10px; color:green; }
        a { text-decoration:none; color:#007bff; }
    </style>
</head>
<body>

<form method="POST">
    <h2>🔐 ตั้งรหัสผ่านใหม่</h2>
    <input type="password" name="new_password" placeholder="รหัสผ่านใหม่" required>
    <input type="password" name="confirm_password" placeholder="ยืนยันรหัสผ่าน" required>
    <button type="submit">บันทึกรหัสผ่านใหม่</button>
    <p class="msg"><?= $message ?></p>
</form>

</body>
</html>
