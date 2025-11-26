<?php
session_start();
include 'db_connect.php';

// ตรวจสอบสิทธิ์เฉพาะอาจารย์
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'teacher') {
    header("Location: login.php");
    exit;
}

$teacher_id = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'];
$role = $_SESSION['role'];
$msg = "";
$msg_type = ""; // success, danger

// ------------------------------
// ✅ เพิ่มรายวิชา
// ------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_course'])) {
    $course_code = trim($_POST['course_code']);
    $course_name = trim($_POST['course_name']);

    if ($course_code && $course_name) {
        $stmt = $conn->prepare("INSERT INTO courses (course_code, course_name, teacher_id) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $course_code, $course_name, $teacher_id);
        if ($stmt->execute()) {
            $msg = "✅ เพิ่มรายวิชา '$course_code' เรียบร้อยแล้ว!";
            $msg_type = "success";
        } else {
            $msg = "❌ เกิดข้อผิดพลาด: " . $conn->error;
            $msg_type = "danger";
        }
    } else {
        $msg = "⚠️ กรุณากรอกข้อมูลให้ครบถ้วน";
        $msg_type = "warning";
    }
}

// ------------------------------
// ❌ ลบรายวิชา
// ------------------------------
if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    
    // ตรวจสอบว่าเป็นเจ้าของวิชาก่อนลบ
    $check = $conn->prepare("SELECT id FROM courses WHERE id = ? AND teacher_id = ?");
    $check->bind_param("ii", $delete_id, $teacher_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $stmt = $conn->prepare("DELETE FROM courses WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
            $msg = "🗑️ ลบรายวิชาเรียบร้อยแล้ว!";
            $msg_type = "success";
        } else {
            $msg = "❌ ลบไม่สำเร็จ (อาจมีข้อมูลนิสิตผูกอยู่): " . $conn->error;
            $msg_type = "danger";
        }
    } else {
        $msg = "❌ คุณไม่มีสิทธิ์ลบรายวิชานี้";
        $msg_type = "danger";
    }
}

// ดึงรายวิชาทั้งหมดของอาจารย์
$result = $conn->query("SELECT * FROM courses WHERE teacher_id = $teacher_id ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>จัดการรายวิชา</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Global & Sidebar Theme (เหมือน Dashboard) */
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
    .main-content { margin-left: 260px; padding: 30px; }
    
    /* Page Header */
    .page-header { margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; }
    .page-header h1 { margin: 0; font-size: 24px; color: #1e3a8a; }

    /* Alert Box */
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 14px; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }

    /* Card Styles */
    .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #e2e8f0; margin-bottom: 30px; }
    .card-title { font-size: 18px; font-weight: bold; color: #1e293b; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9; }

    /* Form Styles */
    .form-row { display: flex; gap: 20px; align-items: flex-end; }
    .form-group { flex: 1; }
    .form-label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; color: #475569; }
    .form-control { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; transition: 0.2s; }
    .form-control:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    
    .btn-submit { padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s; height: 42px; }
    .btn-submit:hover { background: #1d4ed8; }

    /* Table Styles */
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 15px; text-align: left; border-bottom: 1px solid #f1f5f9; }
    th { background: #f8fafc; color: #64748b; font-weight: 600; font-size: 14px; }
    td { font-size: 14px; color: #334155; }
    tr:hover { background: #f8fafc; }

    /* Action Buttons in Table */
    .action-btn { padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: 600; margin-right: 5px; display: inline-block; }
    .btn-view { background: #e0f2fe; color: #0284c7; }
    .btn-view:hover { background: #bae6fd; }
    .btn-edit { background: #fef3c7; color: #d97706; }
    .btn-edit:hover { background: #fde68a; }
    .btn-delete { background: #fee2e2; color: #dc2626; }
    .btn-delete:hover { background: #fecaca; }

    .empty-state { text-align: center; padding: 40px; color: #94a3b8; font-size: 14px; }
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>🎓 ระบบนิสิต</h2>
        <p><?= htmlspecialchars($fullname) ?> <br> (Teacher)</p>
    </div>
    <div class="nav-links">
        <a href="manage_courses.php" class="active"><i class="fas fa-book"></i> จัดการรายวิชา</a>
        <a href="teacher_groups.php"><i class="fas fa-user-graduate"></i> กลุ่มที่ปรึกษา</a>
        <a href="teacher_enrollments.php"><i class="fas fa-tasks"></i> อนุมัติลงทะเบียน</a>
        <a href="advisor_invitations.php"><i class="fas fa-envelope-open-text"></i> คำเชิญที่ปรึกษา</a>
        <a href="dashboard.php"><i class="fas fa-home"></i> กลับแดชบอร์ด</a>
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">
    
    <div class="page-header">
        <h1>📘 จัดการรายวิชา</h1>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?>">
            <i class="fas fa-info-circle"></i> <?= $msg ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title">✨ เพิ่มรายวิชาใหม่</div>
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">รหัสวิชา</label>
                    <input type="text" name="course_code" class="form-control" placeholder="เช่น CS101" required>
                </div>
                <div class="form-group" style="flex:2;">
                    <label class="form-label">ชื่อรายวิชา</label>
                    <input type="text" name="course_name" class="form-control" placeholder="เช่น การเขียนโปรแกรมเบื้องต้น" required>
                </div>
                <button type="submit" name="add_course" class="btn-submit">
                    <i class="fas fa-plus"></i> บันทึก
                </button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-title">📚 รายวิชาของคุณ</div>
        
        <?php if ($result->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th width="15%">รหัสวิชา</th>
                        <th>ชื่อรายวิชา</th>
                        <th width="20%">วันที่สร้าง</th>
                        <th width="25%">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['course_code']) ?></strong></td>
                            <td><?= htmlspecialchars($row['course_name']) ?></td>
                            <td><?= date("d/m/Y", strtotime($row['created_at'])) ?></td>
                            <td>
                                <a href="view_students.php?id=<?= $row['id'] ?>" class="action-btn btn-view" title="ดูรายชื่อนิสิต">
                                    <i class="fas fa-users"></i> นิสิต
                                </a>
                                <a href="edit_course.php?id=<?= $row['id'] ?>" class="action-btn btn-edit" title="แก้ไข">
                                    <i class="fas fa-edit"></i> แก้ไข
                                </a>
                                <a href="?delete=<?= $row['id'] ?>" class="action-btn btn-delete" onclick="return confirm('⚠️ ยืนยันการลบวิชานี้? \n(หากลบ ข้อมูลการลงทะเบียนของนิสิตจะหายไปด้วย)')" title="ลบ">
                                    <i class="fas fa-trash-alt"></i> ลบ
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-book-open" style="font-size: 48px; color: #cbd5e1; margin-bottom: 10px;"></i>
                <p>คุณยังไม่มีรายวิชาที่สอน</p>
                <small>เริ่มเพิ่มวิชาแรกได้ที่ฟอร์มด้านบน</small>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>