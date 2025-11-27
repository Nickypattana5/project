<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_id = (int)$_SESSION['user_id'];
$fullname = $_SESSION['fullname'];
$role = $_SESSION['role'];
$group_id = intval($_GET['group_id'] ?? 0);

// ดึงข้อมูลกลุ่ม
$g_stmt = $conn->prepare("SELECT project_name, advisor_id FROM project_groups WHERE id = ?");
$g_stmt->bind_param("i", $group_id);
$g_stmt->execute();
$group = $g_stmt->get_result()->fetch_assoc();
if (!$group) die("❌ ไม่พบข้อมูลกลุ่ม");

// ดึงชื่ออาจารย์
$adv_q = $conn->prepare("SELECT fullname FROM users WHERE id = ?");
$adv_q->bind_param("i", $group['advisor_id']);
$adv_q->execute();
$advisor_name = $adv_q->get_result()->fetch_assoc()['fullname'] ?? '-';

// ดึงรายการ Meeting
$meetings = $conn->prepare("SELECT * FROM project_meetings WHERE group_id = ? ORDER BY week_number DESC");
$meetings->bind_param("i", $group_id);
$meetings->execute();
$res = $meetings->get_result();

// ฟังก์ชันแปลงคะแนนเป็นดาว
function render_stars($score) {
    if ($score <= 0) return "-";
    $stars = "";
    if ($score >= 25) $stars .= "⭐";
    if ($score >= 50) $stars .= "⭐";
    if ($score >= 75) $stars .= "⭐";
    if ($score >= 100) $stars .= "⭐";
    return "<span title='ความคืบหน้า $score%'>$stars <small style='color:#666; font-weight:normal;'>($score%)</small></span>";
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ประวัติการพบปะ</title>
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
    .main-content { margin-left: 260px; padding: 30px; }
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
    .page-header h1 { margin: 0; font-size: 24px; color: #1e3a8a; }
    
    .btn-back { text-decoration: none; color: #64748b; font-weight: 600; display: flex; align-items: center; gap: 5px; }
    .btn-back:hover { color: #333; }

    /* Card & Table */
    .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #e2e8f0; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th, td { padding: 15px; text-align: left; border-bottom: 1px solid #f1f5f9; }
    th { background: #f8fafc; color: #64748b; font-weight: 600; font-size: 14px; }
    td { font-size: 14px; color: #334155; vertical-align: middle; }
    tr:hover { background: #f8fafc; }

    /* Status Badges */
    .status-open { color: #166534; background: #dcfce7; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; }
    .status-closed { color: #991b1b; background: #fee2e2; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; }

    /* Buttons */
    .btn-create { padding: 10px 20px; background: #2563eb; color: white; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14px; display: inline-flex; align-items: center; gap: 5px; }
    .btn-create:hover { background: #1d4ed8; }
    
    .btn-view { padding: 6px 12px; background: #e0f2fe; color: #0284c7; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
    .btn-view:hover { background: #bae6fd; }

    .empty-state { text-align: center; padding: 50px; color: #94a3b8; font-size: 14px; }
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>🎓 ระบบนิสิต</h2>
        <p><?= htmlspecialchars($fullname) ?> <br> (<?= ucfirst($role) ?>)</p>
    </div>
    <div class="nav-links">
        <?php if($role == 'student'): ?>
            <a href="dashboard.php">🏠 แดชบอร์ด</a>
            <a href="my_groups.php">🔙 กลับหน้ารวมกลุ่ม</a>
        <?php elseif($role == 'teacher'): ?>
            <a href="teacher_groups.php">🔙 กลับรายการกลุ่ม</a>
        <?php elseif($role == 'admin'): ?>
            <a href="admin_project_list.php">🔙 กลับหน้ารวม</a>
        <?php endif; ?>
    </div>
    <a href="logout.php" class="logout-btn">🚪 ออกจากระบบ</a>
</div>

<div class="main-content">
    
    <div class="page-header">
        <div>
            <h1>📅 ประวัติการพบปะ (Meetings)</h1>
            <p style="margin:5px 0 0; color:#64748b; font-size:14px;">กลุ่ม: <?= htmlspecialchars($group['project_name']) ?></p>
        </div>
        
        <?php if ($role === 'teacher'): ?>
            <a href="meeting_create.php?group_id=<?= $group_id ?>" class="btn-create">
                <i class="fas fa-plus"></i> นัดหมายใหม่
            </a>
        <?php endif; ?>
    </div>

    <div class="card">
        <p style="margin-top:0; font-size:14px;"><strong>👨‍🏫 อาจารย์ที่ปรึกษา:</strong> <?= htmlspecialchars($advisor_name) ?></p>

        <?php if ($res->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>ครั้งที่</th>
                        <th>สถานะ</th>
                        <th>วันที่เริ่ม</th>
                        <th>ความคืบหน้า</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($m = $res->fetch_assoc()): ?>
                    <tr>
                        <td><strong>สัปดาห์ที่ <?= $m['week_number'] ?></strong></td>
                        <td>
                            <?php if($m['is_closed']): ?>
                                <span class="status-closed">ปิดแล้ว</span>
                            <?php else: ?>
                                <span class="status-open">เปิดอยู่</span>
                            <?php endif; ?>
                        </td>
                        <td><?= date("d/m/Y H:i", strtotime($m['created_at'])) ?></td>
                        
                        <td><?= render_stars($m['progress_score']) ?></td>
                        
                        <td>
                            <a href="meeting_chat.php?meeting_id=<?= $m['id'] ?>" class="btn-view">
                                <i class="fas fa-comments"></i> เข้าห้องแชท
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="far fa-calendar-times" style="font-size: 48px; margin-bottom: 10px;"></i>
                <p>ยังไม่มีประวัติการพบปะ</p>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>