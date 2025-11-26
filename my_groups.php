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

// 🔥 [เพิ่มใหม่] เคลียร์แจ้งเตือนผลอนุมัติโครงงาน (approval_result)
$clear_notif = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE receiver_id = ? AND type = 'approval_result'");
$clear_notif->bind_param("i", $student_id);
$clear_notif->execute();

// ตรวจสิทธิ์การเข้าถึง
$has_project_access = false;
$q = $conn->prepare("SELECT 1 FROM enrollments WHERE student_id = ? AND status = 'approved' LIMIT 1");
$q->bind_param("i", $student_id);
$q->execute();
if ($q->get_result()->num_rows > 0) $has_project_access = true;

// ดึงข้อมูลกลุ่ม (Query เดิม)
$sql = "
    SELECT 
        g.id, g.project_name, g.status, g.created_at, g.advisor_id,
        m.is_leader, c.course_name, c.course_code, u.fullname AS advisor_name
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
<style>
    /* Theme เดิม */
    body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: #f4f6f9; color: #333; }
    .sidebar { width: 250px; height: 100vh; background: #1e3a8a; color: white; position: fixed; left: 0; top: 0; padding-top: 20px; z-index: 100; }
    .sidebar h2 { text-align: center; margin-bottom: 30px; font-size: 22px; }
    .sidebar a { display: block; padding: 12px 20px; color: white; text-decoration: none; font-size: 15px; transition: 0.2s; border-left: 4px solid transparent; }
    .sidebar a:hover { background: #3b82f6; border-left-color: #fff; }
    .sidebar a.active { background: #2563eb; border-left-color: #fff; font-weight: bold; }
    .logout { margin-top: 30px; display: inline-block; background: #dc2626; color: white; padding: 10px 15px; border-radius: 6px; text-decoration: none; margin-left: 20px; width: 180px; text-align: center; }
    .content { margin-left: 260px; padding: 30px; }
    .header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
    .header-bar h1 { margin: 0; color: #1e3a8a; font-size: 24px; }
    .alert { padding: 15px; margin-bottom: 20px; border-radius: 8px; font-weight: 500; display: flex; align-items: center; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .grid-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; }
    .project-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-top: 5px solid #3b82f6; position: relative; transition: transform 0.2s; }
    .project-card:hover { transform: translateY(-5px); box-shadow: 0 10px 15px rgba(0,0,0,0.1); }
    .card-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px; }
    .project-title { font-size: 18px; font-weight: bold; color: #1f2937; margin: 0; }
    .course-badge { background: #e0f2fe; color: #0369a1; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; margin-top: 5px; display: inline-block; }
    .card-body p { margin: 5px 0; font-size: 14px; color: #666; }
    .status-badge { padding: 5px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; text-transform: uppercase; }
    .status-draft { background: #f3f4f6; color: #374151; }
    .status-pending { background: #fffbeb; color: #b45309; }
    .status-approved { background: #ecfdf5; color: #047857; }
    .status-rejected { background: #fef2f2; color: #b91c1c; }
    .card-footer { margin-top: 20px; display: flex; gap: 10px; }
    .btn { flex: 1; padding: 10px; text-align: center; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600; transition: 0.2s; border: none; cursor: pointer; }
    .btn-open { background: #2563eb; color: white; }
    .btn-open:hover { background: #1d4ed8; }
    .btn-delete { background: #fff; color: #dc2626; border: 1px solid #fca5a5; }
    .btn-delete:hover { background: #fef2f2; }
    .btn-create { background: #10b981; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    .btn-create:hover { background: #059669; }
    .empty-state { text-align: center; padding: 50px; color: #9ca3af; }
    .empty-icon { font-size: 48px; margin-bottom: 10px; display: block; }
</style>
</head>
<body>

<div class="sidebar">
    <h2>📘 ระบบนิสิต</h2>
    <p style="text-align:center; font-size:13px; opacity:0.8; margin-bottom:20px;"><?= htmlspecialchars($fullname) ?><br>(Student)</p>
    <hr style="border-color:rgba(255,255,255,0.1); width:80%; margin: 0 auto 10px auto;">
    <a href="dashboard.php">🏠 แดชบอร์ด</a>
    <a href="enroll_course.php">📝 ลงทะเบียนเรียน</a>
    <a href="my_courses.php">📚 วิชาที่ลงทะเบียน</a>
    <?php if ($has_project_access): ?>
        <a href="my_groups.php" class="active">👥 กลุ่มโครงงาน</a>
        <a href="invitations.php">📩 คำเชิญเข้ากลุ่ม</a>
    <?php endif; ?>
    <a href="logout.php" class="logout">🚪 ออกจากระบบ</a>
</div>

<div class="content">
    <div class="header-bar">
        <h1>👥 กลุ่มโครงงานของฉัน</h1>
        <?php if ($result->num_rows == 0): ?><a href="create_group.php" class="btn-create">➕ สร้างกลุ่มใหม่</a><?php endif; ?>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <?php if ($_GET['msg'] == 'group_deleted'): ?><div class="alert alert-success">✅ ลบกลุ่มเรียบร้อยแล้ว</div>
        <?php elseif ($_GET['msg'] == 'already_in_group'): ?><div class="alert alert-danger">⚠️ คุณมีกลุ่มโครงงานอยู่แล้ว</div><?php endif; ?>
    <?php endif; ?>

    <?php if ($result->num_rows > 0): ?>
        <div class="grid-container">
            <?php while ($row = $result->fetch_assoc()): ?>
                <div class="project-card">
                    <div class="card-header">
                        <div><h3 class="project-title"><?= htmlspecialchars($row['project_name']) ?></h3><span class="course-badge"><?= htmlspecialchars($row['course_code']) ?></span></div>
                        <span class="status-badge status-<?= $row['status'] ?>"><?= ucfirst($row['status']) ?></span>
                    </div>
                    <div class="card-body">
                        <p><strong>📅 วันที่สร้าง:</strong> <?= date("d/m/Y", strtotime($row['created_at'])) ?></p>
                        <p><strong>👨‍🏫 ที่ปรึกษา:</strong> <?= $row['advisor_name'] ? htmlspecialchars($row['advisor_name']) : '<span style="color:#999">- รอการเชิญ -</span>' ?></p>
                    </div>
                    <div class="card-footer">
                        <a href="group_chat.php?id=<?= $row['id'] ?>" class="btn btn-open">🚀 เข้าสู่ห้องทำงาน</a>
                        <?php if ($row['is_leader'] == 1): ?>
                            <a href="delete_group.php?group_id=<?= $row['id'] ?>" class="btn btn-delete" onclick="return confirm('⚠️ คำเตือน!\nการลบกลุ่มจะทำให้ข้อมูลแชทและไฟล์ทั้งหมดหายไป\n\nยืนยันที่จะลบหรือไม่?')">🗑 ลบกลุ่ม</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="empty-state"><span class="empty-icon">📂</span><h3>คุณยังไม่มีกลุ่มโครงงาน</h3><p>เริ่มสร้างกลุ่มของคุณเพื่อเริ่มทำงานร่วมกับเพื่อนและอาจารย์</p></div>
    <?php endif; ?>
</div>
</body>
</html>