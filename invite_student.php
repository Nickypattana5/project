<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

// ป้องกันผู้ไม่ได้ล็อกอิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'];
$role = $_SESSION['role'];
$group_id = intval($_GET['group_id'] ?? 0);
$message = "";
$msg_type = "";

// ดึงข้อมูลกลุ่มเพื่อดูรายวิชา
$stmt = $conn->prepare("
    SELECT g.project_name, g.course_id, c.course_name 
    FROM project_groups g
    LEFT JOIN courses c ON g.course_id = c.id
    WHERE g.id = ?
");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();

if (!$group) die("❌ ไม่พบข้อมูลกลุ่มนี้");
$course_id = intval($group['course_id']);

// 1. ตรวจสอบเมื่อกดปุ่มเชิญ
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['invite_id'])) {
    $invite_id = intval($_POST['invite_id']);

    // เช็คว่าลงวิชาเดียวกันและอนุมัติแล้ว
    $check_course = $conn->prepare("SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND course_id = ? AND status = 'approved'");
    $check_course->bind_param("ii", $invite_id, $course_id);
    $check_course->execute();
    $enrolled = $check_course->get_result()->fetch_row()[0];

    if ($enrolled == 0) {
        $message = "⚠️ ผู้ใช้นี้ไม่ได้ลงทะเบียนรายวิชาเดียวกัน (หรือยังไม่ได้รับการอนุมัติ)";
        $msg_type = "warning";
    } else {
        // เช็คว่ามีกลุ่มอยู่แล้วไหม
        $check_busy = $conn->prepare("
            SELECT COUNT(*) FROM project_members pm
            JOIN project_groups pg ON pm.group_id = pg.id
            WHERE pm.student_id = ? AND pg.course_id = ?
        ");
        $check_busy->bind_param("ii", $invite_id, $course_id);
        $check_busy->execute();
        $has_group = $check_busy->get_result()->fetch_row()[0];

        if ($has_group > 0) {
            $message = "❌ ไม่สามารถเชิญได้: นิสิตคนนี้มีกลุ่มอยู่แล้ว";
            $msg_type = "danger";
        } else {
            // เช็คว่าเชิญไปแล้วหรือยัง
            $check_dup = $conn->prepare("SELECT id FROM project_members WHERE group_id = ? AND student_id = ?");
            $check_dup->bind_param("ii", $group_id, $invite_id);
            $check_dup->execute();
            
            if ($check_dup->get_result()->num_rows > 0) {
                $message = "⚠️ ได้ส่งคำเชิญไปแล้ว หรือเป็นสมาชิกอยู่แล้ว";
                $msg_type = "warning";
            } else {
                // ✅ เพิ่มลง project_members (รอตอบรับ)
                $ins = $conn->prepare("INSERT INTO project_members (group_id, student_id, is_leader, is_confirmed, invited_by, joined_at) VALUES (?, ?, 0, 0, ?, NOW())");
                $ins->bind_param("iii", $group_id, $invite_id, $user_id);
                
                if ($ins->execute()) {
                    // แจ้งเตือน
                    $msg_text = "เชิญคุณเข้าร่วมกลุ่มโครงงาน '{$group['project_name']}'";
                    $n = $conn->prepare("INSERT INTO notifications (receiver_id, sender_id, type, group_id, message, is_read, created_at) VALUES (?, ?, 'invite_group', ?, ?, 0, NOW())");
                    $n->bind_param("iiis", $invite_id, $user_id, $group_id, $msg_text);
                    $n->execute();

                    $message = "✅ ส่งคำเชิญเรียบร้อยแล้ว!";
                    $msg_type = "success";
                } else {
                    $message = "❌ เกิดข้อผิดพลาด: " . $conn->error;
                    $msg_type = "danger";
                }
            }
        }
    }
}

// 2. ดึงรายชื่อเพื่อน (ที่ยังไม่มีกลุ่มในวิชานี้)
$sql = "
    SELECT u.id, u.fullname, u.student_id
    FROM enrollments e
    JOIN users u ON e.student_id = u.id
    WHERE e.course_id = ?
      AND e.status = 'approved'
      AND u.role = 'student'
      AND u.id != ?
      AND u.id NOT IN (
          SELECT pm.student_id FROM project_members pm 
          JOIN project_groups pg ON pm.group_id = pg.id 
          WHERE pg.course_id = ?
      )
    ORDER BY u.fullname ASC
";
$stmt2 = $conn->prepare($sql);
$stmt2->bind_param("iii", $course_id, $user_id, $course_id);
$stmt2->execute();
$students = $stmt2->get_result();
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เชิญเพื่อนเข้ากลุ่ม</title>
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
    .logout-btn { margin: 20px; padding: 12px; text-align: center; background: #dc2626; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.2s; }
    .logout-btn:hover { background: #b91c1c; }

    /* Main Content */
    .main-content { margin-left: 260px; padding: 30px; display: flex; justify-content: center; }
    
    .card { background: white; width: 100%; max-width: 800px; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
    .page-header { text-align: center; margin-bottom: 25px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; }
    .page-header h2 { margin: 0; font-size: 22px; color: #1e3a8a; }
    .page-header p { margin: 5px 0 0; font-size: 14px; color: #64748b; }

    .alert { padding: 12px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 14px; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; }

    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 15px; text-align: left; border-bottom: 1px solid #f1f5f9; }
    th { background: #f8fafc; color: #64748b; font-weight: 600; font-size: 14px; }
    td { font-size: 14px; color: #334155; }
    tr:hover { background: #f8fafc; }

    .btn-invite { padding: 8px 16px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s; }
    .btn-invite:hover { background: #1d4ed8; }

    .btn-back { display: inline-flex; align-items: center; gap: 5px; text-decoration: none; color: #64748b; font-weight: 600; margin-top: 20px; }
    .btn-back:hover { color: #1e3a8a; }
    
    .empty-state { text-align: center; padding: 40px; color: #94a3b8; font-size: 14px; border: 2px dashed #e2e8f0; border-radius: 8px; }
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
        <a href="my_groups.php">🔙 กลับหน้ารวมกลุ่ม</a>
    </div>
    <a href="logout.php" class="logout-btn">🚪 ออกจากระบบ</a>
</div>

<div class="main-content">
    <div class="card">
        <div class="page-header">
            <h2>👥 เชิญเพื่อนเข้ากลุ่ม</h2>
            <p>กลุ่ม: <strong><?= htmlspecialchars($group['project_name']) ?></strong></p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $msg_type ?>">
                <i class="fas fa-info-circle"></i> <?= $message ?>
            </div>
        <?php endif; ?>

        <?php if ($students->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>รหัสนิสิต</th>
                        <th>ชื่อ-นามสกุล</th>
                        <th width="20%">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $students->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['student_id']) ?></td>
                            <td><?= htmlspecialchars($row['fullname']) ?></td>
                            <td>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="invite_id" value="<?= $row['id'] ?>">
                                    <button type="submit" class="btn-invite">
                                        <i class="fas fa-user-plus"></i> เชิญ
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-user-slash" style="font-size: 32px; margin-bottom: 10px; display: block;"></i>
                ไม่พบเพื่อนที่เรียนวิชานี้ (หรือทุกคนมีกลุ่มหมดแล้ว)
            </div>
        <?php endif; ?>

        <div style="text-align:center;">
            <a href="group_chat.php?id=<?= $group_id ?>" class="btn-back"><i class="fas fa-arrow-left"></i> กลับหน้ากลุ่ม</a>
        </div>
    </div>
</div>

</body>
</html>