<?php
session_start();
include 'db_connect.php';

// ตรวจสอบสิทธิ์ Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$fullname = $_SESSION['fullname'];

// 🔥 เคลียร์แจ้งเตือน "คำขออนุมัติ" (invite_admin) ทันทีที่เข้ามาหน้านี้
$clear_notif = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE receiver_id = ? AND type = 'invite_admin'");
$clear_notif->bind_param("i", $_SESSION['user_id']);
$clear_notif->execute();

// ดึงคำขออนุมัติทั้งหมด + รายละเอียดวิชาและที่ปรึกษา
$sql = "
    SELECT 
        r.id AS req_id, r.group_id, r.status AS req_status, r.created_at,
        g.project_name, g.status AS group_status,
        u.fullname AS requester_name,
        c.course_code, c.course_name,
        a.fullname AS advisor_name
    FROM project_approval_requests r
    JOIN project_groups g ON r.group_id = g.id
    JOIN users u ON r.requested_by = u.id
    LEFT JOIN courses c ON g.course_id = c.id
    LEFT JOIN users a ON g.advisor_id = a.id
    ORDER BY 
        CASE WHEN r.status = 'pending' THEN 1 ELSE 2 END, -- เอา Pending ขึ้นก่อน
        r.created_at DESC
";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>รายการคำขออนุมัติโครงงาน</title>
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
    .logout-btn { margin: 20px; padding: 12px; text-align: center; background: #dc2626; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.2s; }
    .logout-btn:hover { background: #b91c1c; }

    /* Main Content */
    .main-content { margin-left: 260px; padding: 30px; }
    
    .page-header { margin-bottom: 25px; }
    .page-header h1 { margin: 0; font-size: 24px; color: #1e3a8a; }

    /* Alert */
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; font-size: 14px; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    /* Card & Table */
    .card { 
        background: white; padding: 25px; border-radius: 12px; 
        box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #e2e8f0; 
    }
    
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 15px; text-align: left; border-bottom: 1px solid #f1f5f9; }
    th { background: #f8fafc; color: #64748b; font-weight: 600; font-size: 14px; }
    td { font-size: 14px; color: #334155; vertical-align: middle; }
    tr:hover { background: #f8fafc; }

    /* Badges & Info */
    .badge { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; display: inline-block; text-transform: uppercase; }
    .bg-pending { background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; }
    .bg-approved { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .bg-rejected { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

    .course-tag { background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; display: inline-block; margin-bottom: 4px; }
    .text-muted { color: #94a3b8; font-size: 12px; }
    .advisor-text { font-size: 13px; color: #475569; display: flex; align-items: center; gap: 5px; }

    /* Action Button */
    .btn-review { 
        padding: 8px 16px; background: #2563eb; color: white; border-radius: 6px; 
        text-decoration: none; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s; 
    }
    .btn-review:hover { background: #1d4ed8; }

    .empty-state { text-align: center; padding: 50px; color: #94a3b8; }
    .empty-state i { font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e1; }
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>🎓 ระบบนิสิต</h2>
        <p><?= htmlspecialchars($fullname) ?> <br> (Admin)</p>
    </div>
    <div class="nav-links">
        <a href="dashboard.php"><i class="fas fa-home"></i> กลับแดชบอร์ด</a>
        <a href="admin_approval_list.php" class="active"><i class="fas fa-clipboard-check"></i> อนุมัติโครงงาน</a>
        <a href="admin_chat_list.php"><i class="fas fa-comments"></i> แชททั้งหมด</a>
        <a href="list_teachers.php"><i class="fas fa-chalkboard-teacher"></i> รายชื่ออาจารย์ทั้งหมด</a>
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">
    
    <div class="page-header">
        <h1>📋 รายการคำขออนุมัติโครงงาน</h1>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <?php if ($_GET['msg'] == 'approved'): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> อนุมัติโครงงานเรียบร้อยแล้ว</div>
        <?php elseif ($_GET['msg'] == 'rejected'): ?>
            <div class="alert alert-danger"><i class="fas fa-times-circle"></i> ปฏิเสธคำขอเรียบร้อยแล้ว</div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="card">
        <?php if ($result->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th width="25%">ชื่อโครงงาน</th>
                        <th width="20%">รายวิชา</th>
                        <th>ผู้ขอ (หัวหน้า)</th>
                        <th>ที่ปรึกษา</th>
                        <th>สถานะ</th>
                        <th>การดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <div style="font-weight:bold; color:#1e293b;"><?= htmlspecialchars($row['project_name']) ?></div>
                                <div class="text-muted" style="font-size:11px; margin-top:2px;">
                                    ส่งเมื่อ: <?= date("d/m H:i", strtotime($row['created_at'])) ?>
                                </div>
                            </td>
                            <td>
                                <span class="course-tag"><?= htmlspecialchars($row['course_code']) ?></span>
                                <div class="text-muted"><?= htmlspecialchars($row['course_name']) ?></div>
                            </td>
                            <td>
                                <?= htmlspecialchars($row['requester_name']) ?>
                            </td>
                            <td>
                                <?php if ($row['advisor_name']): ?>
                                    <span class="advisor-text"><i class="fas fa-user-tie"></i> <?= htmlspecialchars($row['advisor_name']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">- ไม่มี -</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $row['req_status'] ?>">
                                    <?= ucfirst($row['req_status']) ?>
                                </span>
                            </td>
                            <td>
                                <a href="admin_review_group.php?request_id=<?= $row['req_id'] ?>&group_id=<?= $row['group_id'] ?>" class="btn-review">
                                    <i class="fas fa-search"></i> ตรวจสอบ
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-check"></i>
                <h3>ไม่มีคำขอใหม่</h3>
                <p>รายการคำขออนุมัติโครงงานจะปรากฏที่นี่</p>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>