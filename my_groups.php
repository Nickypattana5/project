<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
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

// เคลียร์แจ้งเตือน
$clear_notif = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE receiver_id = ? AND type = 'approval_result'");
$clear_notif->bind_param("i", $student_id);
$clear_notif->execute();

// ตรวจสิทธิ์การเข้าถึง
$has_project_access = false;
$q = $conn->prepare("SELECT 1 FROM enrollments WHERE student_id = ? AND status = 'approved' LIMIT 1");
$q->bind_param("i", $student_id);
$q->execute();
if ($q->get_result()->num_rows > 0) $has_project_access = true;

// นับจำนวนแจ้งเตือนคำเชิญ
$count_invite = 0;
$c_inv_q = $conn->prepare("SELECT COUNT(*) FROM project_members WHERE student_id = ? AND is_confirmed = 0");
$c_inv_q->bind_param("i", $student_id); $c_inv_q->execute();
$count_invite = $c_inv_q->get_result()->fetch_row()[0];

// ดึงข้อมูลกลุ่ม
$sql = "
    SELECT 
        g.id, 
        g.project_name, 
        g.status, 
        g.created_at,
        g.advisor_id,
        m.is_leader,
        c.course_name,
        c.course_code,
        u.fullname AS advisor_name
    FROM project_members m
    JOIN project_groups g ON m.group_id = g.id
    LEFT JOIN courses c ON g.course_id = c.id
    LEFT JOIN users u ON g.advisor_id = u.id
    WHERE m.student_id = ?
    ORDER BY g.created_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>กลุ่มโครงงานของฉัน</title>
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
    .menu-badge { background: #fbbf24; color: #1e3a8a; font-size: 11px; padding: 2px 8px; border-radius: 12px; margin-left: auto; font-weight: bold; }
    .logout-btn { margin: 20px; padding: 12px; text-align: center; background: #dc2626; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.2s; }
    .logout-btn:hover { background: #b91c1c; }
    .main-content { margin-left: 260px; padding: 30px; }
    .header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
    .header-bar h1 { margin: 0; color: #1e3a8a; font-size: 24px; }
    .alert { padding: 15px; margin-bottom: 20px; border-radius: 8px; font-weight: 500; display: flex; align-items: center; gap: 10px; font-size: 14px; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .grid-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; }
    .project-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); transition: transform 0.2s; border: 1px solid #e2e8f0; position: relative; display: flex; flex-direction: column; }
    .project-card:hover { transform: translateY(-5px); box-shadow: 0 10px 15px rgba(0,0,0,0.05); }
    .project-card.approved { border-top: 4px solid #10b981; }
    .project-card.pending { border-top: 4px solid #f59e0b; }
    .project-card.rejected { border-top: 4px solid #ef4444; }
    .project-card.draft { border-top: 4px solid #6b7280; }
    .card-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px; }
    .project-title { font-size: 18px; font-weight: bold; color: #1f2937; margin: 0; line-height: 1.4; }
    .course-badge { background: #eff6ff; color: #2563eb; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; margin-top: 5px; display: inline-block; }
    .card-body { flex: 1; margin-bottom: 20px; }
    .card-body p { margin: 5px 0; font-size: 14px; color: #64748b; display: flex; align-items: center; gap: 8px; }
    .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
    .status-draft { background: #f3f4f6; color: #374151; }
    .status-pending { background: #fffbeb; color: #b45309; }
    .status-approved { background: #d1fae5; color: #065f46; }
    .status-rejected { background: #fee2e2; color: #991b1b; }
    .card-footer { display: flex; gap: 10px; margin-top: auto; }
    .btn { flex: 1; padding: 10px; text-align: center; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; transition: 0.2s; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 5px; }
    .btn-open { background: #eff6ff; color: #2563eb; border: 1px solid #dbeafe; }
    .btn-open:hover { background: #dbeafe; }
    .btn-delete { background: #fff; color: #dc2626; border: 1px solid #fee2e2; }
    .btn-delete:hover { background: #fee2e2; }
    .btn-create { background: #10b981; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.1); display: inline-flex; align-items: center; gap: 5px; }
    .btn-create:hover { background: #059669; }
    .empty-state { text-align: center; padding: 50px; color: #9ca3af; grid-column: 1 / -1; }
    .empty-icon { font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e1; }
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
            <a href="my_groups.php" class="active"><i class="fas fa-users"></i> กลุ่มโครงงาน</a>
            <?php if ($count_invite > 0): ?>
                <a href="invitations.php"><i class="fas fa-envelope"></i> คำเชิญเข้ากลุ่ม <span class="menu-badge"><?= $count_invite ?></span></a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">

    <div class="header-bar">
        <h1>👥 กลุ่มโครงงานของฉัน</h1>
        
        <a href="create_group.php" class="btn-create"><i class="fas fa-plus"></i> สร้างกลุ่มใหม่</a>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <?php if ($_GET['msg'] == 'group_deleted'): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> ลบกลุ่มเรียบร้อยแล้ว</div>
        <?php elseif ($_GET['msg'] == 'already_in_group'): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> คุณมีกลุ่มในรายวิชานี้อยู่แล้ว</div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="grid-container">
        <?php if ($result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <div class="project-card <?= $row['status'] ?>">
                    <div class="card-header">
                        <div>
                            <h3 class="project-title"><?= htmlspecialchars($row['project_name']) ?></h3>
                            <span class="course-badge">
                                <?= htmlspecialchars($row['course_code']) ?> <?= htmlspecialchars($row['course_name']) ?>
                            </span>
                        </div>
                        <span class="status-badge status-<?= $row['status'] ?>">
                            <?= ucfirst($row['status']) ?>
                        </span>
                    </div>

                    <div class="card-body">
                        <p><i class="far fa-calendar-alt"></i> สร้างเมื่อ: <?= date("d/m/Y", strtotime($row['created_at'])) ?></p>
                        <p><i class="fas fa-user-tie"></i> ที่ปรึกษา: 
                            <?= $row['advisor_name'] ? htmlspecialchars($row['advisor_name']) : '<span style="color:#999">- รอการเชิญ -</span>' ?>
                        </p>
                    </div>

                    <div class="card-footer">
                        <a href="group_chat.php?id=<?= $row['id'] ?>" class="btn btn-open">
                            <i class="fas fa-comments"></i> ห้องทำงาน
                        </a>

                        <?php if ($row['is_leader'] == 1 && $row['status'] !== 'approved'): ?>
                            <a href="delete_group.php?group_id=<?= $row['id'] ?>" 
                               class="btn btn-delete" 
                               onclick="return confirm('⚠️ คำเตือน!\nการลบกลุ่มจะทำให้ข้อมูลแชทและไฟล์ทั้งหมดหายไป\n\nยืนยันที่จะลบหรือไม่?')">
                               <i class="fas fa-trash-alt"></i> ลบกลุ่ม
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-folder-open empty-icon"></i>
                <h3>คุณยังไม่มีกลุ่มโครงงาน</h3>
                <p>เริ่มสร้างกลุ่มของคุณเพื่อเริ่มทำงานร่วมกับเพื่อนและอาจารย์</p>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>