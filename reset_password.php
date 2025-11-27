<?php
include 'db_connect.php';
$message = "";
$msg_type = "";

// ตรวจว่ามี id ของผู้ใช้ส่งมาไหม
if (!isset($_GET['id'])) {
    die("❌ ลิงก์ไม่ถูกต้อง หรือไม่มีข้อมูลผู้ใช้");
}

$user_id = intval($_GET['id']);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_password = trim($_POST['new_password']);
    $confirm = trim($_POST['confirm_password']);

    if (strlen($new_password) < 4) {
        $message = "⚠️ รหัสผ่านต้องมีความยาวอย่างน้อย 4 ตัวอักษร";
        $msg_type = "warning";
    } elseif ($new_password !== $confirm) {
        $message = "⚠️ รหัสผ่านยืนยันไม่ตรงกัน";
        $msg_type = "danger";
    } else {
        // เข้ารหัสและอัปเดต
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashed, $user_id);
        
        if ($stmt->execute()) {
            $message = "✅ ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว!";
            $msg_type = "success";
        } else {
            $message = "❌ เกิดข้อผิดพลาด: " . $conn->error;
            $msg_type = "danger";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งรหัสผ่านใหม่</title>
    <style>
        /* Theme Login */
        body { margin: 0; padding: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: #f4f6f9; display: flex; justify-content: center; align-items: center; height: 100vh; }
        
        .card {
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
            width: 100%; padding: 12px; background: #10b981; color: white;
            border: none; border-radius: 8px; font-size: 16px; font-weight: bold;
            cursor: pointer; transition: 0.2s;
        }
        .btn-submit:hover { background: #059669; }

        .alert { padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; text-align: center; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; }

        .footer-link { margin-top: 20px; font-size: 14px; color: #64748b; }
        .footer-link a { color: #2563eb; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>

<div class="card">
    <span class="brand-logo">🔐</span>
    <h1 class="title">ตั้งรหัสผ่านใหม่</h1>
    <p class="subtitle">กรุณาระบุรหัสผ่านใหม่ของคุณ</p>

    <?php if ($message): ?>
        <div class="alert alert-<?= $msg_type ?>">
            <?= $message ?>
            <?php if ($msg_type == 'success'): ?>
                <br><br><a href="login.php" style="font-weight:bold; color:inherit;">เข้าสู่ระบบทันที</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($msg_type != 'success'): ?>
    <form method="POST">
        <div class="form-group">
            <label class="form-label">รหัสผ่านใหม่</label>
            <input type="password" name="new_password" class="form-control" placeholder="••••••••" required>
        </div>

        <div class="form-group">
            <label class="form-label">ยืนยันรหัสผ่าน</label>
            <input type="password" name="confirm_password" class="form-control" placeholder="••••••••" required>
        </div>
        
        <button type="submit" class="btn-submit">บันทึกรหัสผ่าน</button>
    </form>
    <?php endif; ?>

    <div class="footer-link">
        <a href="login.php">⬅ กลับไปหน้าเข้าสู่ระบบ</a>
    </div>
</div>

</body>
</html>