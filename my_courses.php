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
$msg_type = ""; // success, danger, warning

// 🔥 เคลียร์แจ้งเตือนทันทีที่เข้ามาหน้านี้
$clear_notif = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE receiver_id = ? AND type IN ('enrollment_result', 'drop_result')");
$clear_notif->bind_param("i", $student_id);
$clear_notif->execute();

// ตรวจสิทธิ์ Project Access (สำหรับ Sidebar)
$has_project_access = false;
$q = $conn->prepare("SELECT 1 FROM enrollments WHERE student_id = ? AND status = 'approved' LIMIT 1");
$q->bind_param("i", $student_id);
$q->execute();
if ($q->get_result()->num_rows > 0) $has_project_access = true;

// --- จัดการ Action ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $enroll_id = intval($_GET['id']);
    $action = $_GET['action'];

    if ($action == 'cancel_req') {
        $stmt = $conn->prepare("DELETE FROM enrollments WHERE id = ? AND student_id = ? AND status = 'pending'");
        $stmt->bind_param("ii", $enroll_id, $student_id);
        if ($stmt->execute()) {
            $msg = "✅ ยกเลิกคำขอเรียบร้อย";
            $msg_type = "success";
        }
    } 
    elseif ($action == 'delete_rejected') {
        $stmt = $conn->prepare("DELETE FROM enrollments WHERE id = ? AND student_id = ? AND status = 'rejected'");
        $stmt->bind_param("ii", $enroll_id, $student_id);
        if ($stmt->execute()) {
            $msg = "🗑️ ลบรายการที่ไม่ผ่านออกเรียบร้อย (สามารถลงใหม่ได้)";
            $msg_type = "success";
        }
    }
    elseif ($action == 'request_drop') {
        $info_q = $conn->prepare("SELECT c.teacher_id, c.course_name, c.course_code, c.id AS course_id FROM enrollments e JOIN courses c ON e.course_id = c.id WHERE e.id = ?");
        $info_q->bind_param("i", $enroll_id);
        $info_q->execute();
        $info = $info_q->get_result()->fetch_assoc();

        $stmt = $conn->prepare("UPDATE enrollments SET status = 'drop_pending' WHERE id = ? AND student_id = ? AND status = 'approved'");
        $stmt->bind_param("ii", $enroll_id, $student_id);
        if ($stmt->execute() && $info) {
            $notif_msg = "นิสิต " . $_SESSION['fullname'] . " ขอถอนรายวิชา " . $info['course_code'];
            $n = $conn->prepare("INSERT INTO notifications (receiver_id, sender_id, type, group_id, message, is_read, created_at) VALUES (?, ?, 'drop_request', ?, ?, 0, NOW())");
            $n->bind_param("iiis", $info['teacher_id'], $student_id, $info['course_id'], $notif_msg);
            $n->execute();
            $msg = "✅ ส่งคำขอถอนรายวิชาเรียบร้อย รออาจารย์อนุมัติ";
            $msg_type = "warning";
        }
    }
    elseif ($action == 'cancel_drop') {
        $stmt = $conn->prepare("UPDATE enrollments SET status = 'approved' WHERE id = ? AND student_id = ? AND status = 'drop_pending'");
        $stmt->bind_param("ii", $enroll_id, $student_id);
        if ($stmt->execute()) {
            $msg = "✅ ยกเลิกการขอถอนแล้ว กลับเข้าเรียนตามปกติ";
            $msg_type = "success";
        }
    }
}

// ดึงข้อมูล
$sql = "SELECT e.id AS enroll_id, e.status, e.enrolled_at, c.course_code, c.course_name, u.fullname AS teacher_name FROM enrollments e JOIN courses c ON e.course_id = c.id JOIN users u ON c.teacher_id = u.id WHERE e.student_id = ? ORDER BY e.enrolled_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>วิชาที่ลงทะเบียนแล้ว</title>
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

    /* Badges */
    .badge { padding: 5px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; display: inline-block; }
    .bg-pending { background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; }
    .bg-approved { background: #f0fdf4; color: #15803d; border: 1px solid #dcfce7; }
    .bg-rejected { background: #fef2f2; color: #b91c1c; border: 1px solid #fee2e2; }
    .bg-drop { background: #fefce8; color: #a16207; border: 1px solid #fef9c3; }

    /* Action Buttons */
    .btn { padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s; border: 1px solid transparent; }
    
    .btn-cancel { background: white; color: #dc2626; border-color: #fca5a5; }
    .btn-cancel:hover { background: #fef2f2; }

    .btn-drop { background: #fee2e2; color: #dc2626; }
    .btn-drop:hover { background: #fecaca; }

    .btn-undo { background: #e5e7eb; color: #374151; }
    .btn-undo:hover { background: #d1d5db; }

    .btn-ack { background: #1f2937; color: white; }
    .btn-ack:hover { background: #111827; }

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
        <a href="enroll_course.php"><i class="fas fa-book-open"></i> ลงทะเบียนเรียน</a>
        <a href="my_courses.php" class="active"><i class="fas fa-list"></i> วิชาที่ลงทะเบียน</a>
        <?php if ($has_project_access): ?>
            <a href="my_groups.php"><i class="fas fa-users"></i> กลุ่มโครงงาน</a>
            <a href="invitations.php"><i class="fas fa-envelope"></i> คำเชิญเข้ากลุ่ม</a>
        <?php endif; ?>
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">
    
    <div class="page-header">
        <h1>📚 รายวิชาที่ฉันลงทะเบียนไว้</h1>
        <a href="enroll_course.php" class="btn btn-undo" style="background:#2563eb; color:white;">
            <i class="fas fa-plus"></i> ลงทะเบียนเพิ่ม
        </a>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?>">
            <i class="fas fa-info-circle"></i> <?= $msg ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <?php if ($result->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>รหัสวิชา</th>
                        <th>ชื่อวิชา</th>
                        <th>อาจารย์ผู้สอน</th>
                        <th>สถานะ</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['course_code']) ?></strong></td>
                            <td><?= htmlspecialchars($row['course_name']) ?></td>
                            <td><?= htmlspecialchars($row['teacher_name']) ?></td>
                            <td>
                                <?php if ($row['status'] == 'pending'): ?>
                                    <span class="badge bg-pending"><i class="fas fa-clock"></i> รออนุมัติ</span>
                                <?php elseif ($row['status'] == 'approved'): ?>
                                    <span class="badge bg-approved"><i class="fas fa-check-circle"></i> เรียนอยู่</span>
                                <?php elseif ($row['status'] == 'rejected'): ?>
                                    <span class="badge bg-rejected"><i class="fas fa-times-circle"></i> ไม่ผ่าน</span>
                                <?php elseif ($row['status'] == 'drop_pending'): ?>
                                    <span class="badge bg-drop"><i class="fas fa-exclamation-circle"></i> รออนุมัติถอน</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['status'] == 'pending'): ?>
                                    <a href="?action=cancel_req&id=<?= $row['enroll_id'] ?>" class="btn btn-cancel" onclick="return confirm('ยกเลิกคำขอลงทะเบียน?')">
                                        <i class="fas fa-trash"></i> ยกเลิก
                                    </a>
                                <?php elseif ($row['status'] == 'approved'): ?>
                                    <a href="?action=request_drop&id=<?= $row['enroll_id'] ?>" class="btn btn-drop" onclick="return confirm('ยืนยันการขอถอนรายวิชานี้?')">
                                        <i class="fas fa-sign-out-alt"></i> ขอถอน
                                    </a>
                                <?php elseif ($row['status'] == 'drop_pending'): ?>
                                    <a href="?action=cancel_drop&id=<?= $row['enroll_id'] ?>" class="btn btn-undo" onclick="return confirm('ยกเลิกการขอถอน กลับไปเรียนเหมือนเดิม?')">
                                        <i class="fas fa-undo"></i> ไม่ถอนแล้ว
                                    </a>
                                <?php elseif ($row['status'] == 'rejected'): ?>
                                    <a href="?action=delete_rejected&id=<?= $row['enroll_id'] ?>" class="btn btn-ack" onclick="return confirm('ลบรายการนี้ออกเพื่อให้สามารถลงทะเบียนใหม่ได้?')">
                                        <i class="fas fa-eraser"></i> ลบออก (ลงใหม่)
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-book" style="font-size: 48px; color: #cbd5e1; margin-bottom: 10px;"></i>
                <p>ยังไม่มีรายการลงทะเบียน</p>
                <small>กดปุ่ม "ลงทะเบียนเพิ่ม" ด้านบนเพื่อเริ่มเรียน</small>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>