<?php
session_start();
include 'db_connect.php';
$message = "";
$msg_type = ""; // danger, success

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $identifier = trim($_POST['identifier']); // อาจเป็นอีเมลหรือรหัสนิสิต

    // ค้นหาผู้ใช้จาก email หรือ student_id
    $stmt = $conn->prepare("SELECT id, fullname FROM users WHERE email = ? OR student_id = ?");
    $stmt->bind_param("ss", $identifier, $identifier);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        // ในระบบจริงควรส่ง Email แต่สำหรับ Demo นี้ให้ Redirect ไปหน้าตั้งรหัสเลย
        header("Location: reset_password.php?id=" . $user['id']);
        exit;
    } else {
        $message = "❌ ไม่พบบัญชีผู้ใช้นี้ในระบบ";
        $msg_type = "danger";
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลืมรหัสผ่าน</title>
    <style>
        /* Theme เดียวกับ Login */
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
        
        .alert {
            padding: 10px; border-radius: 6px; font-size: 14px; margin-bottom: 20px;
            text-align: center;
        }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        
        .footer-links { margin-top: 20px; font-size: 14px; color: #64748b; }
        .footer-links a { color: #2563eb; text-decoration: none; font-weight: 600; }
        .footer-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="login-card">
    <span class="brand-logo">🔐</span>
    <h1 class="title">ลืมรหัสผ่าน</h1>
    <p class="subtitle">กรอกข้อมูลเพื่อยืนยันตัวตน</p>

    <?php if ($message): ?>
        <div class="alert alert-<?= $msg_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label class="form-label">อีเมล หรือ รหัสนิสิต</label>
            <input type="text" name="identifier" class="form-control" placeholder="กรอกข้อมูลที่ลงทะเบียนไว้" required>
        </div>
        
        <button type="submit" class="btn-submit">ตรวจสอบ</button>
    </form>

    <div class="footer-links">
        <a href="login.php">⬅ กลับไปหน้าเข้าสู่ระบบ</a>
    </div>
</div>

</body>
</html>