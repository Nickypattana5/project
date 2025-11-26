<?php 
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db_connect.php';

$role = $_SESSION['role'];
$fullname = $_SESSION['fullname'];
$user_id = $_SESSION['user_id'];

/* ===============================
   1. จัดการระบบแจ้งเตือน (Notification Logic)
   =============================== */

// เคลียร์แจ้งเตือน (อ่านทั้งหมด - เฉพาะข่าวทั่วไป)
if (isset($_GET['read_all'])) {
    $upd = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE receiver_id = ? AND type != 'invite_group'");
    $upd->bind_param("i", $user_id);
    $upd->execute();
    header("Location: dashboard.php");
    exit;
}

// A. นับข่าวทั่วไปที่ยังไม่อ่าน
$q1 = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE receiver_id = ? AND is_read = 0 AND type != 'invite_group'");
$q1->bind_param("i", $user_id);
$q1->execute();
$count_general = $q1->get_result()->fetch_row()[0];

// B. นับคำเชิญเข้ากลุ่ม (เฉพาะ Student) - เช็คงานค้าง
$count_invite = 0;
if ($role == 'student') {
    $q2 = $conn->prepare("SELECT COUNT(*) FROM project_members WHERE student_id = ? AND is_confirmed = 0");
    $q2->bind_param("i", $user_id);
    $q2->execute();
    $count_invite = $q2->get_result()->fetch_row()[0];
}

// C. นับคำเชิญที่ปรึกษา (เฉพาะ Teacher) - เช็คงานค้าง
$count_advisor_invite = 0;
if ($role == 'teacher') {
    $q3 = $conn->prepare("SELECT COUNT(*) FROM advisor_invites WHERE teacher_id = ? AND status = 'pending'");
    $q3->bind_param("i", $user_id);
    $q3->execute();
    $count_advisor_invite = $q3->get_result()->fetch_row()[0];
}

// รวมยอด Badge ทั้งหมด
$total_badge = $count_general + $count_invite + $count_advisor_invite;

// D. ดึงรายการแจ้งเตือน 10 รายการล่าสุด
$sql_list = "
    SELECT n.*, 
           pm.is_confirmed AS student_confirmed,
           ai.status AS advisor_status
    FROM notifications n
    LEFT JOIN project_members pm 
        ON n.group_id = pm.group_id 
        AND pm.student_id = n.receiver_id 
        AND n.type = 'invite_group'
    LEFT JOIN advisor_invites ai
        ON n.group_id = ai.group_id
        AND ai.teacher_id = n.receiver_id
        AND n.type = 'invite_advisor'
    WHERE n.receiver_id = ? 
    ORDER BY n.created_at DESC 
    LIMIT 10
";
$stmt_list = $conn->prepare($sql_list);
$stmt_list->bind_param("i", $user_id);
$stmt_list->execute();
$notif_res = $stmt_list->get_result();

/* ===============================
   2. ตรวจสิทธิ์ (Access Control)
   =============================== */
$has_project_access = false;
if ($role === 'student') {
    $q = $conn->prepare("SELECT 1 FROM enrollments WHERE student_id = ? AND status = 'approved' LIMIT 1");
    $q->bind_param("i", $user_id);
    $q->execute();
    if ($q->get_result()->num_rows > 0) $has_project_access = true;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>แดชบอร์ด - <?= htmlspecialchars($fullname) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* Global Reset */
* { box-sizing: border-box; }
body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: #f4f6f9; color: #333; }

/* Sidebar */
.sidebar {
    width: 260px; height: 100vh; background: #1e3a8a; color: white;
    position: fixed; left: 0; top: 0; display: flex; flex-direction: column;
    box-shadow: 2px 0 5px rgba(0,0,0,0.1); z-index: 1000;
}
.sidebar-header { padding: 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
.sidebar-header h2 { margin: 0; font-size: 20px; font-weight: bold; }
.sidebar-header p { margin: 5px 0 0; font-size: 13px; opacity: 0.8; }

.nav-links { flex: 1; padding: 20px 0; overflow-y: auto; }
.nav-links a {
    display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8);
    text-decoration: none; font-size: 15px; transition: 0.2s; border-left: 4px solid transparent;
}
.nav-links a:hover { background: rgba(255,255,255,0.1); color: white; border-left-color: #60a5fa; }
.nav-links a i { width: 25px; text-align: center; margin-right: 10px; }

.menu-badge {
    background: #fbbf24; color: #1e3a8a; font-size: 11px; padding: 2px 8px;
    border-radius: 12px; margin-left: auto; font-weight: bold;
}

.logout-btn {
    margin: 20px; padding: 12px; text-align: center; background: #dc2626; color: white;
    text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.2s;
}
.logout-btn:hover { background: #b91c1c; }

/* Main Content */
.main-content { margin-left: 260px; padding: 30px; }

/* Header Bar */
.top-bar {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 30px; background: white; padding: 15px 25px;
    border-radius: 12px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}
.welcome-msg h1 { margin: 0; font-size: 22px; color: #1e3a8a; }

/* Notification Styles */
.notif-wrapper { position: relative; }
.notif-btn {
    font-size: 24px; color: #64748b; cursor: pointer; position: relative;
    padding: 5px; transition: 0.2s;
}
.notif-btn:hover { color: #1e3a8a; }
.notif-badge {
    position: absolute; top: 0; right: 0; background: #ef4444; color: white;
    font-size: 10px; font-weight: bold; height: 18px; width: 18px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%; border: 2px solid white;
}

/* Popup Box */
.notif-dropdown {
    display: none; position: absolute; right: 0; top: 50px;
    width: 320px; background: white; border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15); border: 1px solid #e2e8f0;
    z-index: 2000; overflow: hidden;
}
.notif-dropdown.show { display: block; animation: slideDown 0.2s ease-out; }
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

.dropdown-header {
    padding: 15px; border-bottom: 1px solid #f1f5f9; background: #fff;
    display: flex; justify-content: space-between; align-items: center; font-weight: bold;
}
.dropdown-header a { font-size: 12px; color: #2563eb; text-decoration: none; }

.notif-list { max-height: 350px; overflow-y: auto; }
.notif-item {
    display: block; padding: 15px; border-bottom: 1px solid #f1f5f9;
    text-decoration: none; color: #333; transition: 0.2s; position: relative;
}
.notif-item:hover { background: #f8fafc; }
.notif-item.unread { background: #eff6ff; border-left: 3px solid #2563eb; }

.notif-msg { font-size: 14px; margin-bottom: 5px; display: block; }
.notif-time { font-size: 11px; color: #94a3b8; }
.action-tag {
    display: inline-block; background: #fef3c7; color: #b45309;
    font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-left: 5px;
}

/* Cards Grid */
.grid-cards {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 25px;
}
.card {
    background: white; padding: 25px; border-radius: 16px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #e2e8f0;
    transition: transform 0.2s, box-shadow 0.2s; display: flex; flex-direction: column;
}
.card:hover { transform: translateY(-5px); box-shadow: 0 10px 15px rgba(0,0,0,0.05); }
.card-icon {
    font-size: 32px; margin-bottom: 15px; color: #2563eb;
    background: #eff6ff; width: 60px; height: 60px;
    display: flex; align-items: center; justify-content: center; border-radius: 12px;
}
.card h3 { margin: 0 0 10px 0; font-size: 18px; color: #1e293b; }
.card p { margin: 0 0 20px 0; color: #64748b; font-size: 14px; flex-grow: 1; }
.card-link {
    color: #2563eb; text-decoration: none; font-weight: 600; font-size: 14px;
    display: inline-flex; align-items: center;
}
.card-link:hover { text-decoration: underline; }
.card-link i { margin-left: 5px; font-size: 12px; }
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>🎓 ระบบนิสิต</h2>
        <p><?= htmlspecialchars($fullname) ?> <br> (<?= ucfirst($role) ?>)</p>
    </div>

    <div class="nav-links">
        <?php if ($role == 'admin'): ?>
            <a href="admin_approval_list.php"><i class="fas fa-clipboard-check"></i> อนุมัติโครงงาน</a>
            <a href="admin_chat_list.php"><i class="fas fa-comments"></i> แชททั้งหมด</a>
            <a href="list_teachers.php"><i class="fas fa-chalkboard-teacher"></i> จัดการอาจารย์</a>
        <?php endif; ?>

        <?php if ($role == 'student'): ?>
            <a href="enroll_course.php"><i class="fas fa-book-open"></i> ลงทะเบียนเรียน</a>
            <a href="my_courses.php"><i class="fas fa-list"></i> วิชาที่ลงทะเบียน</a>
            <?php if ($has_project_access): ?>
                <a href="my_groups.php"><i class="fas fa-users"></i> กลุ่มโครงงาน</a>
                <?php if ($count_invite > 0): ?>
                    <a href="invitations.php" style="color:#fbbf24;">
                        <i class="fas fa-envelope"></i> คำเชิญเข้ากลุ่ม 
                        <span class="menu-badge"><?= $count_invite ?></span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($role == 'teacher'): ?>
            <a href="manage_courses.php"><i class="fas fa-book"></i> จัดการรายวิชา</a>
            <a href="teacher_groups.php"><i class="fas fa-user-graduate"></i> กลุ่มที่ปรึกษา</a>
            <a href="teacher_enrollments.php"><i class="fas fa-tasks"></i> อนุมัติลงทะเบียน</a>
            <a href="advisor_invitations.php" <?= $count_advisor_invite > 0 ? 'style="color:#fbbf24;"' : '' ?>>
                <i class="fas fa-envelope-open-text"></i> คำเชิญที่ปรึกษา
                <?php if ($count_advisor_invite > 0): ?>
                    <span class="menu-badge"><?= $count_advisor_invite ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    </div>

    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">
    
    <div class="top-bar">
        <div class="welcome-msg">
            <h1>👋 สวัสดี, <?= htmlspecialchars($fullname) ?></h1>
        </div>

        <div class="notif-wrapper">
            <div class="notif-btn" onclick="toggleNotif()">
                <i class="fas fa-bell"></i>
                <?php if ($total_badge > 0): ?>
                    <span class="notif-badge"><?= $total_badge > 9 ? '9+' : $total_badge ?></span>
                <?php endif; ?>
            </div>

            <div id="notifDropdown" class="notif-dropdown">
                <div class="dropdown-header">
                    <span>การแจ้งเตือน</span>
                    <?php if ($total_badge > 0): ?>
                        <a href="?read_all=1">อ่านทั้งหมด</a>
                    <?php endif; ?>
                </div>
                
                <div class="notif-list">
                    <?php if ($notif_res->num_rows > 0): ?>
                        <?php while ($n = $notif_res->fetch_assoc()): ?>
                            <?php
                                // Link Router (Final Version)
                                $type = $n['type'];
                                $link = "#";
                                $action_text = "";
                                $is_pending_action = false;

                                if ($type == 'invite_advisor') {
                                    $link = "advisor_invitations.php";
                                    if ($n['advisor_status'] == 'pending') { $is_pending_action = true; $action_text = "รอตอบรับ"; }
                                } 
                                elseif ($type == 'invite_group') {
                                    $link = "invitations.php";
                                    if ($n['student_confirmed'] == 0) { $is_pending_action = true; $action_text = "รอกดรับ"; }
                                } 
                                elseif ($type == 'approval_result') {
                                    $link = "my_groups.php";
                                } 
                                elseif ($type == 'invite_admin') {
                                    $link = "admin_approval_list.php";
                                }
                                elseif ($type == 'enroll_request') {
                                    $link = "teacher_enrollments.php";
                                    $action_text = "รออนุมัติ";
                                    $is_pending_action = true;
                                }
                                elseif ($type == 'enrollment_result') {
                                    $link = "my_courses.php";
                                }
                                elseif ($type == 'drop_request') {
                                    $link = "view_students.php?id=" . $n['group_id'];
                                    $action_text = "รออนุมัติ";
                                    $is_pending_action = true;
                                }
                                elseif ($type == 'drop_result') {
                                    $link = "my_courses.php";
                                }

                                $is_unread = ($n['is_read'] == 0);
                                $is_active = $is_pending_action || ($is_unread && !$is_pending_action);
                            ?>
                            
                            <a href="<?= $link ?>" class="notif-item <?= $is_active ? 'unread' : '' ?>">
                                <div style="flex:1">
                                    <span class="notif-msg">
                                        <?= htmlspecialchars($n['message']) ?>
                                        <?php if ($is_pending_action): ?>
                                            <span class="action-tag"><?= $action_text ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="notif-time">
                                        <i class="far fa-clock"></i> <?= date("d/m/Y H:i", strtotime($n['created_at'])) ?>
                                    </span>
                                </div>
                            </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="padding:20px; text-align:center; color:#999; font-size:13px;">
                            ไม่มีการแจ้งเตือนใหม่
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="grid-cards">
        
        <?php if ($role == 'student'): ?>
            <div class="card">
                <div class="card-icon"><i class="fas fa-book-reader"></i></div>
                <h3>ลงทะเบียนเรียน</h3>
                <p>ดูรายวิชาที่คุณลงทะเบียนเรียนและสถานะการอนุมัติ</p>
                <a href="my_courses.php" class="card-link">ดูรายละเอียด <i class="fas fa-arrow-right"></i></a>
            </div>
            <?php if ($has_project_access): ?>
            <div class="card">
                <div class="card-icon"><i class="fas fa-project-diagram"></i></div>
                <h3>กลุ่มโครงงาน</h3>
                <p>สร้างกลุ่มโครงงาน ติดตามสถานะ หรือเข้าห้องแชทกลุ่ม</p>
                <a href="my_groups.php" class="card-link">จัดการกลุ่ม <i class="fas fa-arrow-right"></i></a>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($role == 'teacher'): ?>
            <div class="card">
                <div class="card-icon"><i class="fas fa-users-cog"></i></div>
                <h3>นักเรียนในที่ปรึกษา</h3>
                <p>ดูแลและติดตามความคืบหน้าโครงงานของนักเรียน</p>
                <a href="teacher_groups.php" class="card-link">เข้าดู <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="card">
                <div class="card-icon"><i class="fas fa-clipboard-list"></i></div>
                <h3>คำขอลงทะเบียน</h3>
                <p>อนุมัติหรือปฏิเสธคำขอเข้าเรียนรายวิชา</p>
                <a href="teacher_enrollments.php" class="card-link">ตรวจสอบ <i class="fas fa-arrow-right"></i></a>
            </div>
        <?php endif; ?>

        <?php if ($role == 'admin'): ?>
            <div class="card">
                <div class="card-icon"><i class="fas fa-stamp"></i></div>
                <h3>รออนุมัติหัวข้อ</h3>
                <p>ตรวจสอบและอนุมัติหัวข้อโครงงานที่เสนอมา</p>
                <a href="admin_approval_list.php" class="card-link">ไปตรวจสอบ <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="card">
                <div class="card-icon"><i class="fas fa-cogs"></i></div>
                <h3>จัดการระบบ</h3>
                <p>จัดการข้อมูลผู้ใช้ อาจารย์ และตั้งค่าระบบ</p>
                <a href="list_teachers.php" class="card-link">จัดการ <i class="fas fa-arrow-right"></i></a>
            </div>
        <?php endif; ?>

    </div>

</div>

<script>
// Toggle Notification Dropdown
function toggleNotif() {
    document.getElementById("notifDropdown").classList.toggle("show");
}

// Close Dropdown when clicking outside
window.onclick = function(event) {
    if (!event.target.closest('.notif-wrapper')) {
        var dropdowns = document.getElementsByClassName("notif-dropdown");
        for (var i = 0; i < dropdowns.length; i++) {
            var openDropdown = dropdowns[i];
            if (openDropdown.classList.contains('show')) {
                openDropdown.classList.remove('show');
            }
        }
    }
}
</script>

</body>
</html>