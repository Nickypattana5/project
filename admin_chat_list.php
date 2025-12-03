<?php
session_start();
include 'db_connect.php';

// ตรวจสอบสิทธิ์ Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$fullname = $_SESSION['fullname'];

// ดึงข้อมูลกลุ่มทั้งหมด (เรียงจากล่าสุด)
$sql = "
    SELECT 
        g.id, g.project_name, g.status, g.created_at,
        c.course_code, c.course_name,
        u.fullname AS advisor_name
    FROM project_groups g
    LEFT JOIN courses c ON g.course_id = c.id
    LEFT JOIN users u ON g.advisor_id = u.id
    ORDER BY g.created_at DESC
";
$q = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>แชทของทุกกลุ่ม</title>
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

    /* Status Badge */
    .badge { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; display: inline-block; text-transform: uppercase; }
    .bg-draft { background: #f3f4f6; color: #374151; }
    .bg-pending { background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; }
    .bg-approved { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .bg-rejected { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

    /* Course Info */
    .course-tag { background: #eff6ff; color: #2563eb; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
    .text-muted { color: #94a3b8; font-size: 12px; }

    /* Button */
    .btn-chat { 
        padding: 8px 16px; background: #e0f2fe; color: #0284c7; border-radius: 6px; 
        text-decoration: none; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; 
    }
    .btn-chat:hover { background: #bae6fd; }

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
        <a href="admin_approval_list.php"><i class="fas fa-clipboard-check"></i> อนุมัติโครงงาน</a>
        <a href="admin_chat_list.php" class="active"><i class="fas fa-comments"></i> แชททั้งหมด</a>
        <a href="list_teachers.php"><i class="fas fa-chalkboard-teacher"></i> รายชื่ออาจารย์ทั้งหมด</a>
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">
    
    <div class="page-header">
        <h1>📥 แชทของทุกกลุ่ม</h1>
    </div>

    <div class="card">
        <?php if ($q->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>ชื่อโครงงาน / รายวิชา</th>
                        <th>อาจารย์ที่ปรึกษา</th>
                        <th width="15%">สถานะ</th>
                        <th width="15%">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($g = $q->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($g['project_name']) ?></strong><br>
                                <span class="course-tag"><?= htmlspecialchars($g['course_code']) ?></span> 
                                <span class="text-muted"><?= htmlspecialchars($g['course_name']) ?></span>
                            </td>
                            <td>
                                <?php if($g['advisor_name']): ?>
                                    <i class="fas fa-user-tie text-muted"></i> <?= htmlspecialchars($g['advisor_name']) ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $g['status'] ?>">
                                    <?= ucfirst($g['status']) ?>
                                </span>
                            </td>
                            <td>
                                <a href="group_chat.php?id=<?= $g['id'] ?>&channel=admin" class="btn-chat">
                                    <i class="fas fa-comment-dots"></i> ดูแชท
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-comments"></i>
                <h3>ยังไม่มีกลุ่มโครงงาน</h3>
                <p>เมื่อนิสิตสร้างกลุ่ม รายการจะปรากฏที่นี่</p>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>