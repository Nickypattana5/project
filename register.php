<?php
session_start();
include 'db_connect.php';

$error = "";
$success = "";

// ถ้าล็อกอินอยู่แล้ว ให้เด้งไป Dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $student_id = trim($_POST['student_id']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);

    // รวมชื่อเต็ม
    $fullname = $first_name . " " . $last_name;

    // Validation
    if ($password !== $confirm_password) {
        $error = "❌ รหัสผ่านไม่ตรงกัน";
    } else {
        // ตรวจสอบข้อมูลซ้ำ (Username, Email, Student ID)
        $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? OR student_id = ?");
        $check->bind_param("sss", $username, $email, $student_id);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            $error = "⚠️ ชื่อผู้ใช้, อีเมล หรือ รหัสนิสิตนี้ ถูกใช้งานแล้ว";
        } else {
            // บันทึกข้อมูล (Role = student เสมอ)
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, password, fullname, student_id, email, role) VALUES (?, ?, ?, ?, ?, 'student')");
            $stmt->bind_param("sssss", $username, $hashed, $fullname, $student_id, $email);

            if ($stmt->execute()) {
                // Auto Login หลังสมัครเสร็จ
                $_SESSION['user_id'] = $stmt->insert_id;
                $_SESSION['fullname'] = $fullname;
                $_SESSION['role'] = 'student';
                
                header("Location: dashboard.php");
                exit;
            } else {
                $error = "❌ เกิดข้อผิดพลาดระบบ: " . $conn->error;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>สมัครสมาชิก - นิสิต</title>
<style>
    body {
        margin: 0; padding: 0; font-family: "Segoe UI", Tahoma, sans-serif;
        background: #f4f6f9; display: flex; justify-content: center; align-items: center; min-height: 100vh;
    }
    .reg-card {
        background: white; width: 100%; max-width: 500px; padding: 40px;
        border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); margin: 20px;
    }
    .header { text-align: center; margin-bottom: 30px; }
    .title { font-size: 22px; font-weight: bold; color: #1e3a8a; margin: 0; }
    .subtitle { color: #64748b; font-size: 14px; margin-top: 5px; }

    /* Form Layout */
    .row { display: flex; gap: 15px; }
    .col { flex: 1; }

    .form-group { margin-bottom: 15px; }
    .form-label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
    .form-control {
        width: 100%; padding: 10px; border: 1px solid #cbd5e1;
        border-radius: 6px; font-size: 14px; box-sizing: border-box; transition: 0.2s;
    }
    .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }

    .btn-submit {
        width: 100%; padding: 12px; background: #10b981; color: white;
        border: none; border-radius: 8px; font-size: 16px; font-weight: bold;
        cursor: pointer; margin-top: 10px; transition: 0.2s;
    }
    .btn-submit:hover { background: #059669; }

    .error-box { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 6px; margin-bottom: 20px; text-align: center; font-size: 14px; border: 1px solid #fca5a5; }
    
    .footer { text-align: center; margin-top: 20px; font-size: 14px; color: #64748b; }
    .footer a { color: #2563eb; text-decoration: none; font-weight: 600; }
</style>
</head>
<body>

<div class="reg-card">
    <div class="header">
        <h1 class="title">📝 สมัครสมาชิกใหม่</h1>
        <p class="subtitle">สำหรับนิสิตเพื่อใช้งานระบบโครงงาน</p>
    </div>

    <?php if ($error): ?>
        <div class="error-box"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="row">
            <div class="col form-group">
                <label class="form-label">ชื่อจริง</label>
                <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="col form-group">
                <label class="form-label">นามสกุล</label>
                <input type="text" name="last_name" class="form-control" required>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">รหัสนิสิต (Student ID)</label>
            <input type="text" name="student_id" class="form-control" placeholder="เช่น 64xxxxxxxx" required>
        </div>

        <div class="form-group">
            <label class="form-label">อีเมลมหาวิทยาลัย</label>
            <input type="email" name="email" class="form-control" placeholder="name.s@ku.th" required>
        </div>

        <hr style="border:none; border-top:1px solid #eee; margin: 20px 0;">

        <div class="form-group">
            <label class="form-label">ตั้งชื่อผู้ใช้ (Username)</label>
            <input type="text" name="username" class="form-control" placeholder="ใช้สำหรับล็อกอิน" required>
        </div>

        <div class="row">
            <div class="col form-group">
                <label class="form-label">รหัสผ่าน</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="col form-group">
                <label class="form-label">ยืนยันรหัสผ่าน</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>
        </div>

        <button type="submit" class="btn-submit">ลงทะเบียน</button>
    </form>

    <div class="footer">
        มีบัญชีอยู่แล้ว? <a href="login.php">เข้าสู่ระบบ</a>
    </div>
</div>

</body>
</html>