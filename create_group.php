<?php
session_start();
include 'db_connect.php';

// ตรวจสอบสิทธิ์นิสิต
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header("Location: login.php");
    exit;
}

$student_id = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'];
$role = $_SESSION['role'];
$msg = "";

// 1. เช็คก่อนว่า "มีกลุ่มอยู่แล้วหรือยัง?"
$check_group = $conn->prepare("SELECT COUNT(*) FROM project_members WHERE student_id = ?");
$check_group->bind_param("i", $student_id);
$check_group->execute();
$has_group = $check_group->get_result()->fetch_row()[0];

if ($has_group > 0) {
    // ถ้ามีกลุ่มแล้ว ให้เด้งกลับไปหน้า my_groups พร้อมแจ้งเตือน
    header("Location: my_groups.php?msg=already_in_group");
    exit;
}

// 2. ดึงรายวิชาที่นิสิต "ลงทะเบียนและผ่านการอนุมัติแล้ว" เท่านั้น
$course_sql = "
    SELECT c.id, c.course_code, c.course_name 
    FROM enrollments e
    JOIN courses c ON e.course_id = c.id
    WHERE e.student_id = ? AND e.status = 'approved'
";
$course_stmt = $conn->prepare($course_sql);
$course_stmt->bind_param("i", $student_id);
$course_stmt->execute();
$my_courses = $course_stmt->get_result();

// 3. จัดการการส่งฟอร์ม (POST)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $project_name = trim($_POST['project_name']);
    $course_id = intval($_POST['course_id']);
    
    if (empty($project_name) || empty($course_id)) {
        $msg = "⚠️ กรุณากรอกชื่อโครงงานและเลือกรายวิชา";
    } else {
        // เริ่ม Transaction (เพื่อให้ข้อมูลเข้าพร้อมกันทุกตาราง)
        $conn->begin_transaction();

        try {
            // A. สร้างกลุ่ม
            $stmt1 = $conn->prepare("INSERT INTO project_groups (project_name, course_id, created_by, status) VALUES (?, ?, ?, 'draft')");
            $stmt1->bind_param("sii", $project_name, $course_id, $student_id);
            $stmt1->execute();
            $new_group_id = $stmt1->insert_id;

            // B. เพิ่มสมาชิก (ตัวเองเป็นหัวหน้า)
            $stmt2 = $conn->prepare("INSERT INTO project_members (group_id, student_id, is_leader, is_confirmed, invited_by) VALUES (?, ?, 1, 1, ?)");
            $stmt2->bind_param("iii", $new_group_id, $student_id, $student_id);
            $stmt2->execute();

            // C. อัปโหลดไฟล์ (ถ้ามี)
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

            // Commit ข้อมูล
            $conn->commit();
            header("Location: my_groups.php?msg=group_created");
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $msg = "❌ เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>สร้างกลุ่มโครงงานใหม่</title>
<style>
    /* Theme Dashboard */
    body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: #f4f6f9; color: #333; }
    
    /* Sidebar */
    .sidebar { width: 250px; height: 100vh; background: #1e3a8a; color: white; position: fixed; left: 0; top: 0; padding-top: 20px; z-index: 100; }
    .sidebar h2 { text-align: center; margin-bottom: 30px; font-size: 22px; }
    .sidebar a { display: block; padding: 12px 20px; color: white; text-decoration: none; font-size: 15px; transition: 0.2s; border-left: 4px solid transparent; }
    .sidebar a:hover { background: #3b82f6; border-left-color: #fff; }
    .logout { margin-top: 30px; display: inline-block; background: #dc2626; color: white; padding: 10px 15px; border-radius: 6px; text-decoration: none; margin-left: 20px; width: 180px; text-align: center; }

    /* Content */
    .content { margin-left: 260px; padding: 40px; display: flex; justify-content: center; }

    /* Form Card */
    .form-card {
        background: white; width: 100%; max-width: 600px;
        padding: 40px; border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    .form-header { text-align: center; margin-bottom: 30px; }
    .form-header h1 { margin: 0; color: #1e3a8a; font-size: 24px; }
    .form-header p { color: #666; margin-top: 5px; }

    /* Input Styles */
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-weight: 600; margin-bottom: 8px; color: #333; }
    .form-control {
        width: 100%; padding: 12px; border: 1px solid #ddd;
        border-radius: 8px; font-size: 14px; box-sizing: border-box;
        transition: 0.3s;
    }
    .form-control:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    
    /* File Upload */
    .file-input-wrapper { border: 2px dashed #ddd; padding: 20px; text-align: center; border-radius: 8px; cursor: pointer; background: #fafafa; }
    .file-input-wrapper:hover { border-color: #3b82f6; background: #f0f7ff; }

    /* Buttons */
    .btn-group { display: flex; gap: 10px; margin-top: 30px; }
    .btn { flex: 1; padding: 12px; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; text-decoration: none; text-align: center; }
    .btn-submit { background: #10b981; color: white; }
    .btn-submit:hover { background: #059669; }
    .btn-cancel { background: #e5e7eb; color: #374151; }
    .btn-cancel:hover { background: #d1d5db; }

    .msg-error { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 6px; margin-bottom: 20px; text-align: center; border: 1px solid #fecaca; }

</style>
</head>
<body>

<div class="sidebar">
    <h2>📘 ระบบนิสิต</h2>
    <p style="text-align:center; font-size:13px; opacity:0.8; margin-bottom:20px;">
        <?= htmlspecialchars($fullname) ?><br>(Student)
    </p>
    <hr style="border-color:rgba(255,255,255,0.1); width:80%; margin: 0 auto 10px auto;">

    <a href="dashboard.php">🏠 แดชบอร์ด</a>
    <a href="enroll_course.php">📝 ลงทะเบียนเรียน</a>
    <a href="my_courses.php">📚 วิชาที่ลงทะเบียน</a>
    <a href="my_groups.php">👥 กลุ่มโครงงาน</a> <a href="logout.php" class="logout">🚪 ออกจากระบบ</a>
</div>

<div class="content">

    <div class="form-card">
        <div class="form-header">
            <h1>✨ สร้างกลุ่มโครงงานใหม่</h1>
            <p>กรอกข้อมูลเพื่อเริ่มต้นโปรเจกต์ของคุณ</p>
        </div>

        <?php if ($msg): ?>
            <div class="msg-error"><?= $msg ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            
            <div class="form-group">
                <label class="form-label">ชื่อโครงงาน (ภาษาไทยหรืออังกฤษ)</label>
                <input type="text" name="project_name" class="form-control" placeholder="เช่น ระบบจัดการร้านอาหาร..." required>
            </div>

            <div class="form-group">
                <label class="form-label">เลือกรายวิชา</label>
                <select name="course_id" class="form-control" required>
                    <option value="">-- กรุณาเลือกรายวิชาที่ลงทะเบียนไว้ --</option>
                    <?php if ($my_courses->num_rows > 0): ?>
                        <?php while ($c = $my_courses->fetch_assoc()): ?>
                            <option value="<?= $c['id'] ?>">
                                <?= htmlspecialchars($c['course_code']) ?> - <?= htmlspecialchars($c['course_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <option value="" disabled>คุณยังไม่มีวิชาที่อนุมัติให้ลงทะเบียน</option>
                    <?php endif; ?>
                </select>
                <small style="color:#666; font-size:12px;">* แสดงเฉพาะวิชาที่คุณลงทะเบียนผ่านแล้วเท่านั้น</small>
            </div>

            <div class="form-group">
                <label class="form-label">อัปโหลดไฟล์ Proposal (PDF/Word)</label>
                <div class="file-input-wrapper">
                    <input type="file" name="project_file" accept=".pdf,.doc,.docx" style="width:100%">
                    <p style="margin:5px 0 0 0; font-size:12px; color:#888;">(ถ้ามี) ขนาดไม่เกิน 10MB</p>
                </div>
            </div>

            <div class="btn-group">
                <a href="my_groups.php" class="btn btn-cancel">ยกเลิก</a>
                <button type="submit" class="btn btn-submit">✅ สร้างกลุ่ม</button>
            </div>

        </form>
    </div>

</div>

</body>
</html>