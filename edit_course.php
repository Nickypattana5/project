<?php
session_start();
include 'db_connect.php';

// ตรวจสอบสิทธิ์เฉพาะอาจารย์
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'teacher') {
    header("Location: login.php");
    exit;
}

$teacher_id = (int)$_SESSION['user_id'];
$fullname = $_SESSION['fullname'];
$msg = "";
$msg_type = ""; // success, danger

// ดึงข้อมูลรายวิชาที่เลือกมาแก้ไข
if (!isset($_GET['id'])) {
    header("Location: manage_courses.php");
    exit;
}

$course_id = intval($_GET['id']);

// ตรวจสอบความเป็นเจ้าของ
$stmt = $conn->prepare("SELECT * FROM courses WHERE id = ? AND teacher_id = ?");
$stmt->bind_param("ii", $course_id, $teacher_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("❌ ไม่พบรายวิชานี้ หรือคุณไม่มีสิทธิ์แก้ไข");
}

$course = $result->fetch_assoc();

// เมื่อกดบันทึก
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $course_code = trim($_POST['course_code']);
    $course_name = trim($_POST['course_name']);

    if ($course_code && $course_name) {
        $update = $conn->prepare("UPDATE courses SET course_code = ?, course_name = ? WHERE id = ? AND teacher_id = ?");
        $update->bind_param("ssii", $course_code, $course_name, $course_id, $teacher_id);
        
        if ($update->execute()) {
            $msg = "✅ แก้ไขรายวิชาเรียบร้อยแล้ว!";
            $msg_type = "success";
            // อัปเดตข้อมูลในตัวแปรเพื่อแสดงผลทันที
            $course['course_code'] = $course_code;
            $course['course_name'] = $course_name;
        } else {
            $msg = "❌ แก้ไขไม่สำเร็จ: " . $conn->error;
            $msg_type = "danger";
        }
    } else {
        $msg = "⚠️ กรุณากรอกข้อมูลให้ครบ";
        $msg_type = "warning";
    }
}

// นับจำนวนแจ้งเตือน (สำหรับ Sidebar)
$count_advisor_invite = 0;
$q3 = $conn->prepare("SELECT COUNT(*) FROM advisor_invites WHERE teacher_id = ? AND status = 'pending'");
$q3->bind_param("i", $teacher_id); $q3->execute();
$count_advisor_invite = $q3->get_result()->fetch_row()[0];
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>แก้ไขรายวิชา</title>
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
    .menu-badge { background: #fbbf24; color: #1e3a8a; font-size: 11px; padding: 2px 8px; border-radius: 12px; margin-left: auto; font-weight: bold; }
    .logout-btn { margin: 20px; padding: 12px; text-align: center; background: #dc2626; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.2s; }
    .logout-btn:hover { background: #b91c1c; }

    /* Main Content */
    .main-content { margin-left: 260px; padding: 30px; display: flex; justify-content: center; }
    
    /* Form Card */
    .form-card { 
        background: white; width: 100%; max-width: 500px; 
        padding: 40px; border-radius: 12px; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;
        margin-top: 50px;
    }
    
    .form-header { text-align: center; margin-bottom: 30px; border-bottom: 1px solid #f1f5f9; padding-bottom: 20px; }
    .form-header h1 { margin: 0; font-size: 24px; color: #1e3a8a; }

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
    .btn-submit { background: #2563eb; color: white; }
    .btn-submit:hover { background: #1d4ed8; }
    .btn-cancel { background: #e2e8f0; color: #475569; }
    .btn-cancel:hover { background: #cbd5e1; }
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>🎓 ระบบนิสิต</h2>
        <p><?= htmlspecialchars($fullname) ?> <br> (Teacher)</p>
    </div>
    <div class="nav-links">
        <a href="dashboard.php"><i class="fas fa-home"></i> แดชบอร์ด</a>
        <a href="manage_courses.php" class="active"><i class="fas fa-book"></i> จัดการรายวิชา</a>
        <a href="teacher_groups.php"><i class="fas fa-user-graduate"></i> กลุ่มที่ปรึกษา</a>
        <a href="teacher_enrollments.php"><i class="fas fa-tasks"></i> อนุมัติลงทะเบียน</a>
        <a href="advisor_invitations.php"><i class="fas fa-envelope-open-text"></i> คำเชิญที่ปรึกษา <?php if ($count_advisor_invite > 0): ?><span class="menu-badge"><?= $count_advisor_invite ?></span><?php endif; ?></a>
    </div>
    <a href="logout.php" class="logout-btn">🚪 ออกจากระบบ</a>
</div>

<div class="main-content">
    
    <div class="form-card">
        <div class="form-header">
            <h1>✏️ แก้ไขรายวิชา</h1>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msg_type ?>">
                <?= $msg ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label class="form-label">รหัสวิชา</label>
                <input type="text" name="course_code" class="form-control" value="<?= htmlspecialchars($course['course_code']) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">ชื่อรายวิชา</label>
                <input type="text" name="course_name" class="form-control" value="<?= htmlspecialchars($course['course_name']) ?>" required>
            </div>

            <div class="btn-group">
                <a href="manage_courses.php" class="btn btn-cancel">ยกเลิก</a>
                <button type="submit" class="btn btn-submit">💾 บันทึกการแก้ไข</button>
            </div>
        </form>
    </div>

</div>

</body>
</html>