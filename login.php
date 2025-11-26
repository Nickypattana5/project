<?php
session_start();
include 'db_connect.php';

// ถ้าล็อกอินอยู่แล้ว ให้เด้งไป Dashboard เลย
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // รองรับการล็อกอินด้วย Username, Email หรือ Student ID
    $sql = "SELECT * FROM users WHERE username = ? OR email = ? OR student_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $username, $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['role'] = $user['role'];
            header("Location: dashboard.php");
            exit;
        } else {
            $error = "❌ รหัสผ่านไม่ถูกต้อง";
        }
    } else {
        $error = "❌ ไม่พบบัญชีผู้ใช้นี้";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>เข้าสู่ระบบ - ระบบบริหารโครงงาน</title>
<style>
    body {
        margin: 0; padding: 0;
        font-family: "Segoe UI", Tahoma, sans-serif;
        background: #f4f6f9;
        display: flex; justify-content: center; align-items: center;
        height: 100vh;
    }
    .login-card {
        background: white; width: 100%; max-width: 400px;
        padding: 40px; border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.05);
        text-align: center;
    }
    .brand-logo { font-size: 40px; margin-bottom: 10px; display: block; }
    .title { font-size: 24px; font-weight: bold; color: #1e3a8a; margin: 0 0 10px 0; }
    .subtitle { color: #64748b; font-size: 14px; margin-bottom: 30px; }
    
    .form-group { margin-bottom: 20px; text-align: left; }
    .form-label { display: block; font-size: 14px; font-weight: 600; color: #334155; margin-bottom: 8px; }
    .form-control {
        width: 100%; padding: 12px; border: 1px solid #cbd5e1;
        border-radius: 8px; font-size: 14px; box-sizing: border-box;
        transition: 0.2s;
    }
    .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    
    .btn-submit {
        width: 100%; padding: 12px; background: #2563eb; color: white;
        border: none; border-radius: 8px; font-size: 16px; font-weight: bold;
        cursor: pointer; transition: 0.2s;
    }
    .btn-submit:hover { background: #1d4ed8; }
    
    .error-msg {
        background: #fee2e2; color: #991b1b; padding: 10px;
        border-radius: 6px; font-size: 14px; margin-bottom: 20px;
        border: 1px solid #fca5a5;
    }
    
    .footer-links { margin-top: 20px; font-size: 14px; color: #64748b; }
    .footer-links a { color: #2563eb; text-decoration: none; font-weight: 600; }
    .footer-links a:hover { text-decoration: underline; }
    
    .divider { margin: 20px 0; border-top: 1px solid #e2e8f0; }
</style>
</head>
<body>

<div class="login-card">
    <span class="brand-logo">🎓</span>
    <h1 class="title">เข้าสู่ระบบ</h1>
    <p class="subtitle">ระบบบริหารจัดการโครงงานนิสิต</p>

    <?php if ($error): ?>
        <div class="error-msg"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label class="form-label">ชื่อผู้ใช้ / รหัสนิสิต / อีเมล</label>
            <input type="text" name="username" class="form-control" placeholder="กรอกข้อมูล..." required>
        </div>
        
        <div class="form-group">
            <label class="form-label">รหัสผ่าน</label>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required>
            <div style="text-align:right; margin-top:5px;">
                <a href="forgot_password.php" style="font-size:12px; color:#64748b; text-decoration:none;">ลืมรหัสผ่าน?</a>
            </div>
        </div>

        <button type="submit" class="btn-submit">เข้าสู่ระบบ</button>
    </form>

    <div class="divider"></div>

    <div class="footer-links">
        ยังไม่มีบัญชี? <a href="register.php">สมัครสมาชิก (นิสิต)</a>
    </div>
</div>

</body>
</html>