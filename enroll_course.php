<?php
session_start();
include 'db_connect.php';

// ตรวจสอบสิทธิ์นักเรียน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header("Location: login.php");
    exit;
}

$student_id = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'];
$role = $_SESSION['role'];
$msg = "";
$msg_type = "";

// ตรวจสิทธิ์ Project Access (สำหรับ Sidebar)
$has_project_access = false;
$q = $conn->prepare("SELECT 1 FROM enrollments WHERE student_id = ? AND status = 'approved' LIMIT 1");
$q->bind_param("i", $student_id);
$q->execute();
if ($q->get_result()->num_rows > 0) $has_project_access = true;

// นับจำนวนแจ้งเตือนสำหรับ Sidebar
$count_invite = 0;
$c_inv_q = $conn->prepare("SELECT COUNT(*) FROM project_members WHERE student_id = ? AND is_confirmed = 0");
$c_inv_q->bind_param("i", $student_id); $c_inv_q->execute();
$count_invite = $c_inv_q->get_result()->fetch_row()[0];

// ส่วนจัดการเมื่อกดลงทะเบียน
if (isset($_GET['enroll'])) {
    $course_id = intval($_GET['enroll']);

    // 1. เช็คข้อมูลเดิมก่อน
    $check = $conn->prepare("SELECT id, status FROM enrollments WHERE student_id = ? AND course_id = ?");
    $check->bind_param("ii", $student_id, $course_id);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();

    // 2. ดึงข้อมูลอาจารย์ (เพื่อส่งแจ้งเตือน)
    $t_query = $conn->prepare("SELECT teacher_id, course_code, course_name FROM courses WHERE id = ?");
    $t_query->bind_param("i", $course_id);
    $t_query->execute();
    $course_info = $t_query->get_result()->fetch_assoc();

    if ($course_info) {
        $teacher_id = $course_info['teacher_id'];
        $notif_msg = "นิสิต " . $_SESSION['fullname'] . " ขอลงทะเบียนวิชา " . $course_info['course_code'];
        $success = false;

        if ($existing) {
            // ถ้ามีข้อมูลอยู่แล้ว (เคยลงแล้ว)
            if ($existing['status'] == 'rejected') {
                // กรณี "ไม่ผ่าน" -> ให้โอกาสแก้ตัว (Update กลับเป็น Pending)
                $upd = $conn->prepare("UPDATE enrollments SET status = 'pending', enrolled_at = NOW() WHERE id = ?");
                $upd->bind_param("i", $existing['id']);
                if ($upd->execute()) $success = true;
            } else {
                $msg = "⚠️ คุณได้ลงทะเบียนวิชานี้ไปแล้ว";
                $msg_type = "warning";
            }
        } else {
            // ถ้ายังไม่มี -> Insert ใหม่
            $ins = $conn->prepare("INSERT INTO enrollments (student_id, course_id, status) VALUES (?, ?, 'pending')");
            $ins->bind_param("ii", $student_id, $course_id);
            if ($ins->execute()) $success = true;
        }

        // ถ้าสำเร็จ -> ส่งแจ้งเตือนหาอาจารย์
        if ($success) {
            $msg = "✅ ส่งคำขอลงทะเบียนเรียบร้อย! รออาจารย์อนุมัติ";
            $msg_type = "success";
            
            // ส่ง Notification
            // (ใช้ group_id เก็บ course_id ชั่วคราวเพื่อให้ลิ้งค์ไปถูก)
            $n_stmt = $conn->prepare("INSERT INTO notifications (receiver_id, sender_id, type, group_id, message, is_read, created_at) VALUES (?, ?, 'enroll_request', ?, ?, 0, NOW())");
            $n_stmt->bind_param("iiis", $teacher_id, $student_id, $course_id, $notif_msg);
            $n_stmt->execute();
        } elseif (empty($msg)) {
            $msg = "❌ เกิดข้อผิดพลาดระบบ";
            $msg_type = "danger";
        }
    } else {
        $msg = "❌ ไม่พบข้อมูลรายวิชา";
        $msg_type = "danger";
    }
}

// ดึงรายวิชาทั้งหมดที่มีในระบบ
$sql = "
    SELECT 
        c.id, 
        c.course_code, 
        c.course_name, 
        u.fullname AS teacher_name,
        e.status AS enroll_status
    FROM courses c
    JOIN users u ON c.teacher_id = u.id
    LEFT JOIN enrollments e ON c.id = e.course_id AND e.student_id = ?
    ORDER BY c.id DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$courses = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ลงทะเบียนเรียน</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Theme Dashboard */
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
    .main-content { margin-left: 260px; padding: 30px; }
    .page-header { margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; }
    .page-header h1 { margin: 0; font-size: 24px; color: #1e3a8a; }

    /* Alert */
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 14px; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

    /* Card & Table */
    .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #e2e8f0; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 15px; text-align: left; border-bottom: 1px solid #f1f5f9; }
    th { background: #f8fafc; color: #64748b; font-weight: 600; font-size: 14px; }
    td { font-size: 14px; color: #334155; vertical-align: middle; }
    tr:hover { background: #f8fafc; }

    /* Badges & Buttons */
    .btn { padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s; border: none; cursor: pointer; }
    .btn-enroll { background: #2563eb; color: white; }
    .btn-enroll:hover { background: #1d4ed8; }
    .btn-retry { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
    .btn-retry:hover { background: #fde68a; }
    .badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; }
    .badge-approved { background: #dcfce7; color: #166534; }
    .badge-pending { background: #f1f5f9; color: #64748b; }
    .badge-rejected { background: #fee2e2; color: #991b1b; }

    .empty-state { text-align: center; padding: 50px; color: #94a3b8; }
    .empty-state i { font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e1; }
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>🎓 ระบบนิสิต</h2>
        <p><?= htmlspecialchars($fullname) ?> <br> (Student)</p>
    </div>
    <div class="nav-links">
        <a href="dashboard.php"><i class="fas fa-home"></i> แดชบอร์ด</a>
        <a href="enroll_course.php" class="active"><i class="fas fa-book-open"></i> ลงทะเบียนเรียน</a>
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
    
    <div class="page-header">
        <h1>📝 ลงทะเบียนเรียน</h1>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?>">
            <i class="fas fa-info-circle"></i> <?= $msg ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <?php if ($courses->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th width="15%">รหัสวิชา</th>
                        <th>ชื่อรายวิชา</th>
                        <th>อาจารย์ผู้สอน</th>
                        <th width="20%">สถานะ / การกระทำ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $courses->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['course_code']) ?></strong></td>
                            <td><?= htmlspecialchars($row['course_name']) ?></td>
                            <td>👨‍🏫 <?= htmlspecialchars($row['teacher_name']) ?></td>
                            <td>
                                <?php if ($row['enroll_status'] == 'approved'): ?>
                                    <span class="badge badge-approved"><i class="fas fa-check-circle"></i> ลงทะเบียนแล้ว</span>
                                <?php elseif ($row['enroll_status'] == 'pending'): ?>
                                    <span class="badge badge-pending"><i class="fas fa-clock"></i> รออนุมัติ</span>
                                <?php elseif ($row['enroll_status'] == 'rejected'): ?>
                                    <a href="?enroll=<?= $row['id'] ?>" class="btn btn-retry" onclick="return confirm('ต้องการขอลงทะเบียนใหม่อีกครั้ง?')">
                                        <i class="fas fa-redo"></i> ขอลงใหม่ (ไม่ผ่าน)
                                    </a>
                                <?php else: ?>
                                    <a href="?enroll=<?= $row['id'] ?>" class="btn btn-enroll" onclick="return confirm('ยืนยันลงทะเบียนรายวิชานี้?')">
                                        <i class="fas fa-plus-circle"></i> ลงทะเบียน
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h3>ไม่พบรายวิชาที่เปิดสอน</h3>
                <p>ยังไม่มีอาจารย์เพิ่มรายวิชาเข้าระบบ</p>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>