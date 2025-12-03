<?php
session_start();
include 'db_connect.php';
$message = "";
$msg_type = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $identifier = trim($_POST['identifier']);

    // 1. ค้นหาผู้ใช้จาก email หรือ student_id
    $stmt = $conn->prepare("SELECT id, email, fullname FROM users WHERE email = ? OR student_id = ?");
    $stmt->bind_param("ss", $identifier, $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user) {
        // 2. สร้าง Token ลับ (สุ่มตัวอักษร) และวันหมดอายุ (1 ชั่วโมง)
        $token = bin2hex(random_bytes(32));
        $expires = date("Y-m-d H:i:s", strtotime("+1 hour"));

        // 3. บันทึก Token ลงฐานข้อมูล
        $upd = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
        $upd->bind_param("ssi", $token, $expires, $user['id']);
        
        if ($upd->execute()) {
            // สร้างลิงก์กู้คืน (เปลี่ยน localhost เป็นชื่อเว็บจริงได้ในอนาคต)
            $reset_link = "http://localhost/school_system/reset_password.php?token=" . $token;

            // 🔥 จำลองการส่งเมล (Show Link) - ของจริงต้องใช้ PHPMailer ส่งไปที่ $user['email']
            $message = "
                ✅ <b>ระบบได้ส่งลิงก์กู้คืนรหัสผ่านไปที่อีเมล: {$user['email']} แล้ว</b><br><br>
                (ในโหมดทดสอบ กดที่นี่ได้เลย): <br>
                <a href='$reset_link' class='btn-link'>👉 คลิกลิงก์กู้คืนรหัสผ่าน</a>
            ";
            $msg_type = "success";
        } else {
            $message = "❌ เกิดข้อผิดพลาดระบบ";
            $msg_type = "danger";
        }
    } else {
        $message = "❌ ไม่พบข้อมูลในระบบ";
        $msg_type = "danger";
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
        .card { background:white; padding:30px; border-radius:10px; width:400px; box-shadow:0 0 10px rgba(0,0,0,0.1); text-align:center; }
        input { width:100%; padding:10px; margin:10px 0; border:1px solid #ddd; border-radius:5px; box-sizing:border-box; }
        button { width:100%; background:#007bff; color:white; padding:10px; border:none; border-radius:5px; cursor:pointer; font-weight:bold; }
        button:hover { background:#0056b3; }
        .alert { padding:15px; border-radius:5px; margin-bottom:20px; font-size:14px; text-align:left; line-height:1.5; }
        .alert-success { background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; }
        .alert-danger { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .btn-link { display:inline-block; margin-top:5px; background:#2563eb; color:white; padding:5px 10px; text-decoration:none; border-radius:4px; font-size:12px; }
        a { color:#007bff; text-decoration:none; }
    </style>
</head>
<body>

<div class="card">
    <h2>🔑 กู้คืนรหัสผ่าน</h2>
    
    <?php if ($message): ?>
        <div class="alert alert-<?= $msg_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <?php if ($msg_type !== 'success'): ?>
    <form method="POST">
        <p>กรอกอีเมล หรือ รหัสนิสิต เพื่อรับลิงก์เปลี่ยนรหัสผ่าน</p>
        <input type="text" name="identifier" placeholder="อีเมล / รหัสนิสิต" required>
        <button type="submit">ส่งลิงก์กู้คืน</button>
    </form>
    <?php endif; ?>

    <br>
    <a href="login.php">⬅ กลับไปหน้าเข้าสู่ระบบ</a>
</div>

</body>
</html>