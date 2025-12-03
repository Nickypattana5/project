<?php
session_start();
include 'db_connect.php';

// ตรวจสอบสิทธิ์ Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit;
}

$fullname = $_SESSION['fullname'];
$msg = "";
$msg_type = ""; // success, danger, warning

// บันทึกข้อมูล
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $t_fullname = trim($_POST['fullname']);
    $password = trim($_POST['password']);
    $confirm_pwd = trim($_POST['confirm_password']);
    $email = trim($_POST['email']);

    if ($password !== $confirm_pwd) {
        $msg = "❌ รหัสผ่านไม่ตรงกัน";
        $msg_type = "danger";
    } else {
        // เช็คชื่อซ้ำ
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            $msg = "⚠️ ชื่อผู้ใช้นี้มีอยู่ในระบบแล้ว";
            $msg_type = "warning";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert
            $stmt = $conn->prepare("INSERT INTO users (username, password, fullname, email, role) VALUES (?, ?, ?, ?, 'teacher')");
            $stmt->bind_param("ssss", $username, $hashed_password, $t_fullname, $email);

            if ($stmt->execute()) {
                $msg = "✅ เพิ่มอาจารย์เรียบร้อยแล้ว!";
                $msg_type = "success";
                // เคลียร์ค่า form
                $username = $t_fullname = $email = "";
            } else {
                $msg = "❌ เกิดข้อผิดพลาด: " . $conn->error;
                $msg_type = "danger";
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
<title>เพิ่มอาจารย์ใหม่</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Global Reset & Theme */
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: #f4f6f9; color: #333; }

    /* Sidebar */
    .sidebar { width: 260px; height: 100vh; background: #1e3a8a; color: white; position: fixed; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; }
    .sidebar-header { padding: 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
    .sidebar-header h2 { margin: 0; font-size: 20px; font-weight: bold; }
    .sidebar-header p { margin: 5px 0 0; font-size: 13px; opacity: 0.8; }
    .nav-links { flex: 1; padding: 20px 0; overflow-y: auto; }
    .nav-links a { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; font-size: 15px; transition: 0.2s; border-left: 4px solid transparent; }
    .nav-links a:hover { background: rgba(255,255,255,0.1); color: white; border-left-color: #60a5fa; }
    .nav-links a.active { background: #2563eb; color: white; border-left-color: #fff; font-weight: bold; }
    .nav-links a i { width: 25px; text-align: center; margin-right: 10px; }
    .logout-btn { margin: 20px; padding: 12px; text-align: center; background: #dc2626; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.2s; }
    .logout-btn:hover { background: #b91c1c; }

    /* Main Content */
    .main-content { margin-left: 260px; padding: 30px; display: flex; justify-content: center; }
    
    /* Form Card */
    .form-card { 
        background: white; width: 100%; max-width: 600px; 
        padding: 40px; border-radius: 12px; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;
    }
    
    .form-header { text-align: center; margin-bottom: 30px; border-bottom: 1px solid #f1f5f9; padding-bottom: 20px; }
    .form-header h1 { margin: 0; font-size: 24px; color: #1e3a8a; }
    .form-header p { color: #64748b; margin-top: 5px; font-size: 14px; }

    /* Inputs */
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-size: 14px; font-weight: 600; color: #475569; margin-bottom: 8px; }
    .form-control { 
        width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; 
        font-size: 14px; transition: 0.2s; 
    }
    .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }

    /* Alert */
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; font-size: 14px; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; }

    /* Buttons */
    .btn-group { display: flex; gap: 10px; margin-top: 30px; }
    .btn { flex: 1; padding: 12px; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; border: none; transition: 0.2s; text-decoration: none; text-align: center; }
    .btn-submit { background: #10b981; color: white; }
    .btn-submit:hover { background: #059669; }
    .btn-cancel { background: #e2e8f0; color: #475569; }
    .btn-cancel:hover { background: #cbd5e1; }

</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>🎓 ระบบนิสิต</h2>
        <p><?= htmlspecialchars($fullname) ?> <br> (Admin)</p>
    </div>
    <div class="nav-links">
        <a href="dashboard.php"><i class="fas fa-home"></i> กลับแดชบอร์ด</a>
        <a href="list_teachers.php" class="active"><i class="fas fa-chalkboard-teacher"></i> รายชื่ออาจารย์ทั้งหมด</a>
        
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">
    
    <div class="form-card">
        <div class="form-header">
            <h1><i class="fas fa-user-plus"></i> เพิ่มอาจารย์ใหม่</h1>
            <p>สร้างบัญชีผู้ใช้สำหรับอาจารย์ที่ปรึกษา</p>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msg_type ?>">
                <?= $msg ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label class="form-label">ชื่อ - นามสกุล</label>
                <input type="text" name="fullname" class="form-control" placeholder="เช่น ดร.สมชาย ใจดี" value="<?= htmlspecialchars($t_fullname ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">อีเมล (ใช้สำหรับติดต่อ)</label>
                <input type="email" name="email" class="form-control" placeholder="teacher@example.com" value="<?= htmlspecialchars($email ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">ชื่อผู้ใช้ (Username)</label>
                <input type="text" name="username" class="form-control" placeholder="ตั้งชื่อผู้ใช้สำหรับล็อกอิน" value="<?= htmlspecialchars($username ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">รหัสผ่าน</label>
                <input type="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>

            <div class="form-group">
                <label class="form-label">ยืนยันรหัสผ่าน</label>
                <input type="password" name="confirm_password" class="form-control" placeholder="••••••••" required>
            </div>

            <div class="btn-group">
                <a href="list_teachers.php" class="btn btn-cancel">ยกเลิก</a>
                <button type="submit" class="btn btn-submit">บันทึกข้อมูล</button>
            </div>
        </form>
    </div>

</div>

</body>
</html>