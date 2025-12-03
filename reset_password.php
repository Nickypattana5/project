<?php
include 'db_connect.php';
$message = "";
$msg_type = "";
$show_form = false;

// รับ Token จาก URL
if (isset($_GET['token'])) {
    $token = $_GET['token'];

    // ตรวจสอบ Token ว่าถูกต้องและยังไม่หมดอายุ (reset_expires > NOW)
    $stmt = $conn->prepare("SELECT id, fullname FROM users WHERE reset_token = ? AND reset_expires > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        $show_form = true; // Token ถูกต้อง -> แสดงฟอร์ม
        $user_id = $user['id'];
    } else {
        $message = "❌ ลิงก์กู้คืนรหัสผ่านไม่ถูกต้อง หรือหมดอายุแล้ว";
        $msg_type = "danger";
    }
} else {
    die("❌ ไม่พบ Token");
}

// บันทึกรหัสผ่านใหม่
if ($_SERVER["REQUEST_METHOD"] == "POST" && $show_form) {
    $new_pass = trim($_POST['new_password']);
    $confirm = trim($_POST['confirm_password']);

    if (strlen($new_pass) < 4) {
        $message = "⚠️ รหัสผ่านต้องมีความยาวอย่างน้อย 4 ตัวอักษร";
        $msg_type = "warning";
    } elseif ($new_pass !== $confirm) {
        $message = "⚠️ รหัสผ่านยืนยันไม่ตรงกัน";
        $msg_type = "warning";
    } else {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        
        // อัปเดตรหัสผ่าน และ ล้าง Token ทิ้ง (เพื่อไม่ให้ใช้ซ้ำ)
        $upd = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $upd->bind_param("si", $hashed, $user_id);
        
        if ($upd->execute()) {
            $message = "✅ เปลี่ยนรหัสผ่านสำเร็จ! กรุณาเข้าสู่ระบบด้วยรหัสใหม่";
            $msg_type = "success";
            $show_form = false; // ซ่อนฟอร์ม
        } else {
            $message = "❌ เกิดข้อผิดพลาดระบบ";
            $msg_type = "danger";
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
        .card { background:white; padding:30px; border-radius:10px; width:400px; box-shadow:0 0 10px rgba(0,0,0,0.1); text-align:center; }
        input { width:100%; padding:10px; margin:10px 0; border:1px solid #ddd; border-radius:5px; box-sizing:border-box; }
        button { width:100%; background:#28a745; color:white; padding:10px; border:none; border-radius:5px; cursor:pointer; font-weight:bold; }
        button:hover { background:#218838; }
        .alert { padding:15px; border-radius:5px; margin-bottom:20px; font-size:14px; }
        .alert-success { background:#d1fae5; color:#065f46; }
        .alert-danger { background:#fee2e2; color:#991b1b; }
        .alert-warning { background:#fffbeb; color:#92400e; }
        a { color:#007bff; text-decoration:none; }
    </style>
</head>
<body>

<div class="card">
    <h2>🔐 ตั้งรหัสผ่านใหม่</h2>

    <?php if ($message): ?>
        <div class="alert alert-<?= $msg_type ?>">
            <?= $message ?>
            <?php if ($msg_type == 'success'): ?>
                <br><br><a href="login.php"><b>⬅ กลับไปหน้าเข้าสู่ระบบ</b></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($show_form): ?>
        <p>ผู้ใช้: <strong><?= htmlspecialchars($user['fullname']) ?></strong></p>
        <form method="POST">
            <input type="password" name="new_password" placeholder="รหัสผ่านใหม่" required>
            <input type="password" name="confirm_password" placeholder="ยืนยันรหัสผ่านใหม่" required>
            <button type="submit">บันทึกรหัสผ่าน</button>
        </form>
    <?php endif; ?>
    
    <?php if (!$show_form && $msg_type != 'success'): ?>
        <br><a href="forgot_password.php">ขอลิงก์ใหม่</a>
    <?php endif; ?>
</div>

</body>
</html>