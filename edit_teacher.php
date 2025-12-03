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
$msg_type = "";

// ตรวจสอบ ID ที่ส่งมา
if (!isset($_GET['id'])) {
    header("Location: list_teachers.php");
    exit;
}

$teacher_id = intval($_GET['id']);

// ดึงข้อมูลเดิมมาแสดง
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'teacher'");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    die("❌ ไม่พบข้อมูลอาจารย์ท่านนี้");
}

// บันทึกการแก้ไข
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $t_fullname = trim($_POST['fullname']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $new_password = trim($_POST['password']); // ถ้าว่างแปลว่าไม่เปลี่ยน

    // เช็คชื่อผู้ใช้ซ้ำ (ยกเว้นตัวเอง)
    $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $check->bind_param("si", $username, $teacher_id);
    $check->execute();
    
    if ($check->get_result()->num_rows > 0) {
        $msg = "⚠️ ชื่อผู้ใช้นี้มีคนอื่นใช้แล้ว";
        $msg_type = "warning";
    } else {
        // กรณีเปลี่ยนรหัสผ่านด้วย
        if (!empty($new_password)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE users SET fullname=?, username=?, email=?, password=? WHERE id=?");
            $update->bind_param("ssssi", $t_fullname, $username, $email, $hashed_password, $teacher_id);
        } else {
            // กรณีไม่เปลี่ยนรหัสผ่าน (อัปเดตแค่ข้อมูล)
            $update = $conn->prepare("UPDATE users SET fullname=?, username=?, email=? WHERE id=?");
            $update->bind_param("sssi", $t_fullname, $username, $email, $teacher_id);
        }

        if ($update->execute()) {
            $msg = "✅ บันทึกการแก้ไขเรียบร้อยแล้ว";
            $msg_type = "success";
            // อัปเดตข้อมูลในตัวแปร $user เพื่อให้หน้าเว็บแสดงค่าล่าสุดทันที
            $user['fullname'] = $t_fullname;
            $user['username'] = $username;
            $user['email'] = $email;
        } else {
            $msg = "❌ เกิดข้อผิดพลาด: " . $conn->error;
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
<title>แก้ไขข้อมูลอาจารย์</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Theme Dashboard */
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: #f4f6f9; color: #333; }
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
    
    .main-content { margin-left: 260px; padding: 30px; display: flex; justify-content: center; }
    .form-card { background: white; width: 100%; max-width: 600px; padding: 40px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
    .form-header { text-align: center; margin-bottom: 30px; border-bottom: 1px solid #f1f5f9; padding-bottom: 20px; }
    .form-header h1 { margin: 0; font-size: 24px; color: #1e3a8a; }
    
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-size: 14px; font-weight: 600; color: #475569; margin-bottom: 8px; }
    .form-control { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; transition: 0.2s; }
    .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; font-size: 14px; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; }
    
    .btn-group { display: flex; gap: 10px; margin-top: 30px; }
    .btn { flex: 1; padding: 12px; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; border: none; transition: 0.2s; text-decoration: none; text-align: center; }
    .btn-submit { background: #2563eb; color: white; }
    .btn-submit:hover { background: #1d4ed8; }
    .btn-cancel { background: #e2e8f0; color: #475569; }
    .btn-cancel:hover { background: #cbd5e1; }
    
    .hint { font-size: 12px; color: #94a3b8; margin-top: 5px; }
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
        <a href="admin_approval_list.php"><i class="fas fa-clipboard-check"></i> อนุมัติโครงงาน</a>
        <a href="admin_chat_list.php"><i class="fas fa-comments"></i> แชททั้งหมด</a>
        <a href="list_teachers.php" class="active"><i class="fas fa-chalkboard-teacher"></i> รายชื่ออาจารย์ทั้งหมด</a>
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">
    <div class="form-card">
        <div class="form-header">
            <h1>✏️ แก้ไขข้อมูลอาจารย์</h1>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msg_type ?>"><?= $msg ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label class="form-label">ชื่อ - นามสกุล</label>
                <input type="text" name="fullname" class="form-control" value="<?= htmlspecialchars($user['fullname']) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">อีเมล</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">ชื่อผู้ใช้ (Username)</label>
                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
            </div>

            <hr style="border:0; border-top:1px solid #eee; margin: 25px 0;">

            <div class="form-group">
                <label class="form-label">🔑 เปลี่ยนรหัสผ่านใหม่</label>
                <input type="password" name="password" class="form-control" placeholder="กรอกเฉพาะเมื่อต้องการเปลี่ยนรหัสผ่าน">
                <div class="hint">* หากไม่ต้องการเปลี่ยนรหัสผ่าน ให้เว้นว่างไว้</div>
            </div>

            <div class="btn-group">
                <a href="list_teachers.php" class="btn btn-cancel">ย้อนกลับ</a>
                <button type="submit" class="btn btn-submit">💾 บันทึกการแก้ไข</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>