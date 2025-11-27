<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

// ตรวจสิทธิ์อาจารย์
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit;
}

$teacher_id = (int)$_SESSION['user_id'];
$fullname = $_SESSION['fullname'];

// นับจำนวนแจ้งเตือน (สำหรับ Sidebar)
$count_advisor_invite = 0;
$q3 = $conn->prepare("SELECT COUNT(*) FROM advisor_invites WHERE teacher_id = ? AND status = 'pending'");
$q3->bind_param("i", $teacher_id); $q3->execute();
$count_advisor_invite = $q3->get_result()->fetch_row()[0];

// ดึงข้อมูลกลุ่มที่อาจารย์เป็นที่ปรึกษา + ชื่อวิชา + จำนวนสมาชิก
$sql = "
    SELECT 
        g.*, 
        c.course_name, 
        c.course_code,
        (SELECT COUNT(*) FROM project_members pm WHERE pm.group_id = g.id AND pm.is_confirmed = 1) AS member_count
    FROM project_groups g
    LEFT JOIN courses c ON g.course_id = c.id
    WHERE g.advisor_id = ?
    ORDER BY g.created_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$groups = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>กลุ่มที่ฉันเป็นที่ปรึกษา</title>
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

    /* Grid Layout */
    .grid-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }

    /* Card Style */
    .group-card { 
        background: white; border-radius: 12px; padding: 20px; 
        box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #e2e8f0;
        transition: transform 0.2s; display: flex; flex-direction: column;
        position: relative; overflow: hidden;
    }
    .group-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
    
    /* Top Border Color based on Status */
    .group-card.approved { border-top: 4px solid #10b981; }
    .group-card.pending { border-top: 4px solid #f59e0b; }
    .group-card.rejected { border-top: 4px solid #ef4444; }
    .group-card.draft { border-top: 4px solid #6b7280; }

    .card-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px; }
    .group-name { font-size: 18px; font-weight: bold; color: #1e293b; margin: 0; line-height: 1.4; }
    .course-tag { display: inline-block; background: #f1f5f9; color: #64748b; font-size: 12px; padding: 3px 8px; border-radius: 4px; margin-top: 5px; }

    .status-badge { font-size: 11px; font-weight: bold; padding: 4px 10px; border-radius: 20px; text-transform: uppercase; }
    .status-badge.approved { background: #dcfce7; color: #166534; }
    .status-badge.pending { background: #fef3c7; color: #92400e; }
    .status-badge.rejected { background: #fee2e2; color: #991b1b; }
    .status-badge.draft { background: #f3f4f6; color: #374151; }

    .card-body { flex: 1; margin-bottom: 20px; font-size: 14px; color: #475569; }
    .info-row { display: flex; align-items: center; gap: 8px; margin-bottom: 5px; }
    .info-row i { color: #94a3b8; width: 20px; text-align: center; }

    /* Action Buttons */
    .card-footer { display: flex; gap: 10px; }
    .btn { flex: 1; padding: 10px; text-align: center; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 5px; border: 1px solid transparent; }
    
    .btn-chat { background: #eff6ff; color: #2563eb; border-color: #dbeafe; }
    .btn-chat:hover { background: #dbeafe; }
    
    .btn-meet { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
    .btn-meet:hover { background: #bbf7d0; }

    .empty-state { text-align: center; padding: 50px; color: #94a3b8; grid-column: 1 / -1; }
    .empty-state i { font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e1; }
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
        <a href="manage_courses.php"><i class="fas fa-book"></i> จัดการรายวิชา</a>
        <a href="teacher_groups.php" class="active"><i class="fas fa-user-graduate"></i> กลุ่มที่ปรึกษา</a>
        <a href="teacher_enrollments.php"><i class="fas fa-tasks"></i> อนุมัติลงทะเบียน</a>
        <a href="advisor_invitations.php" <?= $count_advisor_invite > 0 ? 'style="color:#fbbf24;"' : '' ?>><i class="fas fa-envelope-open-text"></i> คำเชิญที่ปรึกษา <?php if ($count_advisor_invite > 0): ?><span class="menu-badge"><?= $count_advisor_invite ?></span><?php endif; ?></a>
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">
    
    <div class="page-header">
        <h1>🎓 กลุ่มโครงงานในความดูแล</h1>
    </div>

    <div class="grid-container">
        <?php if ($groups->num_rows > 0): ?>
            <?php while($g = $groups->fetch_assoc()): ?>
                <div class="group-card <?= $g['status'] ?>"> 
                    
                    <div class="card-header">
                        <div>
                            <h3 class="group-name"><?= htmlspecialchars($g['project_name']) ?></h3>
                            <span class="course-tag">
                                <?= htmlspecialchars($g['course_code']) ?> <?= htmlspecialchars($g['course_name']) ?>
                            </span>
                        </div>
                        <span class="status-badge <?= $g['status'] ?>">
                            <?= ucfirst($g['status']) ?>
                        </span>
                    </div>

                    <div class="card-body">
                        <div class="info-row">
                            <i class="fas fa-users"></i>
                            <span>สมาชิก: <strong><?= (int)$g['member_count'] ?> คน</strong></span>
                        </div>
                        <div class="info-row">
                            <i class="far fa-calendar-alt"></i>
                            <span>สร้างเมื่อ: <?= date("d/m/Y", strtotime($g['created_at'])) ?></span>
                        </div>
                    </div>

                    <div class="card-footer">
                        <a href="group_chat.php?id=<?= $g['id'] ?>" class="btn btn-chat">
                            <i class="fas fa-comments"></i> แชทกลุ่ม
                        </a>
                        <?php if($g['status'] == 'approved'): ?>
                            <a href="meeting_list.php?group_id=<?= $g['id'] ?>" class="btn btn-meet">
                                <i class="fas fa-video"></i> ห้องพบปะ
                            </a>
                        <?php endif; ?>
                    </div>

                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-user-graduate"></i>
                <h3>ยังไม่มีกลุ่มในความดูแล</h3>
                <p>รอให้นิสิตส่งคำเชิญเป็นที่ปรึกษาเข้ามาก่อนนะครับ</p>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>