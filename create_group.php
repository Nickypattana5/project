<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header("Location: login.php");
    exit;
}

$student_id = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'];
$role = $_SESSION['role'];
$msg = "";
$msg_type = "";

// Project Access Sidebar
$has_project_access = false;
$q = $conn->prepare("SELECT 1 FROM enrollments WHERE student_id = ? AND status = 'approved' LIMIT 1");
$q->bind_param("i", $student_id); $q->execute();
if ($q->get_result()->num_rows > 0) $has_project_access = true;

$count_invite = 0;
$c_inv_q = $conn->prepare("SELECT COUNT(*) FROM project_members WHERE student_id = ? AND is_confirmed = 0");
$c_inv_q->bind_param("i", $student_id); $c_inv_q->execute();
$count_invite = $c_inv_q->get_result()->fetch_row()[0];

// 🔥 [แก้ไขใหม่] ดึงวิชาที่ลงทะเบียนผ่านแล้ว และ **ยังไม่มีกลุ่ม**
$course_sql = "
    SELECT c.id, c.course_code, c.course_name 
    FROM enrollments e
    JOIN courses c ON e.course_id = c.id
    WHERE e.student_id = ? 
      AND e.status = 'approved'
      AND c.id NOT IN (
          /* ค้นหาวิชาที่นิสิตคนนี้มีกลุ่มอยู่แล้ว */
          SELECT pg.course_id 
          FROM project_members pm
          JOIN project_groups pg ON pm.group_id = pg.id
          WHERE pm.student_id = ?
      )
";
$course_stmt = $conn->prepare($course_sql);
$course_stmt->bind_param("ii", $student_id, $student_id); // bind 2 ตัว (student_id)
$course_stmt->execute();
$my_courses = $course_stmt->get_result();

// 4. จัดการ POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $project_name = trim($_POST['project_name']);
    $course_id = intval($_POST['course_id']);
    
    if (empty($project_name) || empty($course_id)) {
        $msg = "⚠️ กรุณากรอกชื่อโครงงานและเลือกรายวิชา";
        $msg_type = "warning";
    } else {
        $conn->begin_transaction();
        try {
            $stmt1 = $conn->prepare("INSERT INTO project_groups (project_name, course_id, created_by, status) VALUES (?, ?, ?, 'draft')");
            $stmt1->bind_param("sii", $project_name, $course_id, $student_id);
            $stmt1->execute();
            $new_group_id = $stmt1->insert_id;

            $stmt2 = $conn->prepare("INSERT INTO project_members (group_id, student_id, is_leader, is_confirmed, invited_by, joined_at) VALUES (?, ?, 1, 1, ?, NOW())");
            $stmt2->bind_param("iii", $new_group_id, $student_id, $student_id);
            $stmt2->execute();

            if (!empty($_FILES['project_file']['name'])) {
                $dir = "uploads/projects/";
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $filename = time() . "_" . basename($_FILES['project_file']['name']);
                $target = $dir . $filename;
                if (move_uploaded_file($_FILES['project_file']['tmp_name'], $target)) {
                    $stmt3 = $conn->prepare("INSERT INTO project_files (group_id, uploaded_by, filename, filepath) VALUES (?, ?, ?, ?)");
                    $stmt3->bind_param("iiss", $new_group_id, $student_id, $filename, $target);
                    $stmt3->execute();
                }
            }

            $conn->commit();
            header("Location: my_groups.php?msg=group_created");
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $msg = "❌ เกิดข้อผิดพลาด: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>สร้างกลุ่มโครงงานใหม่</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Theme Dashboard (เหมือนเดิม) */
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
    .menu-badge { background: #fbbf24; color: #1e3a8a; font-size: 11px; padding: 2px 8px; border-radius: 12px; margin-left: auto; font-weight: bold; }
    .logout-btn { margin: 20px; padding: 12px; text-align: center; background: #dc2626; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.2s; }
    .logout-btn:hover { background: #b91c1c; }
    .main-content { margin-left: 260px; padding: 40px; display: flex; justify-content: center; }
    .form-card { background: white; width: 100%; max-width: 600px; padding: 40px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
    .form-header { text-align: center; margin-bottom: 30px; border-bottom: 1px solid #f1f5f9; padding-bottom: 20px; }
    .form-header h1 { margin: 0; color: #1e3a8a; font-size: 24px; }
    .form-header p { color: #64748b; margin-top: 5px; font-size: 14px; }
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-weight: 600; margin-bottom: 8px; color: #475569; font-size: 14px; }
    .form-control { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; box-sizing: border-box; transition: 0.2s; }
    .form-control:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    .file-input-wrapper { border: 2px dashed #cbd5e1; padding: 20px; text-align: center; border-radius: 8px; cursor: pointer; background: #f8fafc; transition: 0.2s; }
    .file-input-wrapper:hover { border-color: #3b82f6; background: #eff6ff; }
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 14px; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; }
    .btn-group { display: flex; gap: 10px; margin-top: 30px; }
    .btn { flex: 1; padding: 12px; border: none; border-radius: 8px; font-size: 15px; font-weight: bold; cursor: pointer; text-decoration: none; text-align: center; transition: 0.2s; }
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
        <p><?= htmlspecialchars($fullname) ?><br>(Student)</p>
    </div>
    <div class="nav-links">
        <a href="dashboard.php"><i class="fas fa-home"></i> แดชบอร์ด</a>
        <a href="enroll_course.php"><i class="fas fa-book-open"></i> ลงทะเบียนเรียน</a>
        <a href="my_courses.php"><i class="fas fa-list"></i> วิชาที่ลงทะเบียน</a>
        <?php if ($has_project_access): ?>
            <a href="my_groups.php"><i class="fas fa-users"></i> กลุ่มโครงงาน</a> 
            <?php if ($count_invite > 0): ?>
                <a href="invitations.php"><i class="fas fa-envelope"></i> คำเชิญเข้ากลุ่ม <span class="menu-badge"><?= $count_invite ?></span></a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">

    <div class="form-card">
        <div class="form-header">
            <h1>✨ สร้างกลุ่มโครงงานใหม่</h1>
            <p>กรอกข้อมูลเพื่อเริ่มต้นโปรเจกต์ของคุณ</p>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msg_type ?>"><?= $msg ?></div>
        <?php endif; ?>

        <?php if ($my_courses->num_rows > 0): ?>
            <form method="POST" enctype="multipart/form-data">
                
                <div class="form-group">
                    <label class="form-label">ชื่อโครงงาน (ภาษาไทยหรืออังกฤษ)</label>
                    <input type="text" name="project_name" class="form-control" placeholder="เช่น ระบบจัดการร้านอาหาร..." required>
                </div>

                <div class="form-group">
                    <label class="form-label">เลือกรายวิชา</label>
                    <select name="course_id" class="form-control" required>
                        <option value="">-- กรุณาเลือกรายวิชา --</option>
                        <?php while ($c = $my_courses->fetch_assoc()): ?>
                            <option value="<?= $c['id'] ?>">
                                <?= htmlspecialchars($c['course_code']) ?> - <?= htmlspecialchars($c['course_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <small style="color:#64748b; font-size:12px;">* แสดงเฉพาะวิชาที่คุณยังไม่มีกลุ่มโครงงาน</small>
                </div>

                <div class="form-group">
                    <label class="form-label">อัปโหลดไฟล์ Proposal (PDF/Word)</label>
                    <div class="file-input-wrapper" onclick="document.getElementById('fileInput').click()">
                        <input type="file" id="fileInput" name="project_file" accept=".pdf,.doc,.docx" style="display:none" onchange="document.getElementById('fileName').innerText = this.files[0].name">
                        <i class="fas fa-cloud-upload-alt" style="font-size: 24px; color: #cbd5e1; margin-bottom: 5px;"></i><br>
                        <span id="fileName" style="color:#64748b; font-size:14px;">คลิกเพื่อเลือกไฟล์ (ขนาดไม่เกิน 10MB)</span>
                    </div>
                </div>

                <div class="btn-group">
                    <a href="my_groups.php" class="btn btn-cancel">ยกเลิก</a>
                    <button type="submit" class="btn btn-submit">✅ สร้างกลุ่ม</button>
                </div>

            </form>
        <?php else: ?>
            <div style="text-align:center; padding:20px; color:#64748b;">
                <i class="fas fa-check-circle" style="font-size:40px; color:#10b981; margin-bottom:15px;"></i><br>
                คุณมีกลุ่มโครงงานครบทุกรายวิชาที่ลงทะเบียนแล้ว<br>
                <a href="my_groups.php" class="btn btn-cancel" style="display:inline-block; margin-top:20px; width:auto;">กลับหน้ารวมกลุ่ม</a>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>