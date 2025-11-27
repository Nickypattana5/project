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
$group_id = intval($_GET['group_id'] ?? 0);
$msg = "";
$msg_type = "";

// 1. ตรวจสิทธิ์: เป็นสมาชิกกลุ่มนี้ไหม?
$chk = $conn->prepare("SELECT * FROM project_members WHERE group_id = ? AND student_id = ?");
$chk->bind_param("ii", $group_id, $student_id);
$chk->execute();
if ($chk->get_result()->num_rows == 0) {
    die("❌ คุณไม่มีสิทธิ์เชิญอาจารย์สำหรับกลุ่มนี้");
}
$chk->close();

// 2. ดึงข้อมูลกลุ่ม
$chk2 = $conn->prepare("SELECT project_name, advisor_id FROM project_groups WHERE id = ?");
$chk2->bind_param("i", $group_id);
$chk2->execute();
$group_data = $chk2->get_result()->fetch_assoc();
$chk2->close();

// 3. ถ้ามีการส่งเชิญ (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['teacher_id'])) {
    $teacher_id = intval($_POST['teacher_id']);
    
    if (!empty($group_data['advisor_id'])) {
        $msg = "❌ กลุ่มนี้มีอาจารย์ที่ปรึกษาแล้ว";
        $msg_type = "danger";
    } else {
        // ตรวจว่า target เป็น teacher
        $r = $conn->prepare("SELECT role FROM users WHERE id = ?");
        $r->bind_param("i", $teacher_id);
        $r->execute();
        $role_res = $r->get_result()->fetch_assoc();

        if (!$role_res || $role_res['role'] !== 'teacher') {
            $msg = "❌ ไม่สามารถเชิญผู้ใช้นี้ได้";
            $msg_type = "danger";
        } else {
            // ตรวจว่าเชิญซ้ำไหม
            $chk3 = $conn->prepare("SELECT id FROM advisor_invites WHERE group_id = ? AND teacher_id = ? AND status = 'pending'");
            $chk3->bind_param("ii", $group_id, $teacher_id);
            $chk3->execute();
            if ($chk3->get_result()->num_rows > 0) {
                $msg = "⚠️ ได้ส่งคำเชิญให้อาจารย์คนนี้ไปแล้ว (รอการตอบรับ)";
                $msg_type = "warning";
            } else {
                // A. สร้างคำเชิญ
                $ins = $conn->prepare("INSERT INTO advisor_invites (group_id, sender_id, teacher_id) VALUES (?, ?, ?)");
                $ins->bind_param("iii", $group_id, $student_id, $teacher_id);
                
                if ($ins->execute()) {
                    // B. แจ้งเตือนอาจารย์
                    $notif_msg = "คุณได้รับคำเชิญเป็นที่ปรึกษาโครงงาน: " . $group_data['project_name'];
                    $n_ins = $conn->prepare("INSERT INTO notifications (receiver_id, sender_id, type, group_id, message, is_read, created_at) VALUES (?, ?, 'invite_advisor', ?, ?, 0, NOW())");
                    $n_ins->bind_param("iiis", $teacher_id, $student_id, $group_id, $notif_msg);
                    $n_ins->execute();
                    
                    $msg = "✅ ส่งคำเชิญเรียบร้อยแล้ว";
                    $msg_type = "success";
                } else {
                    $msg = "❌ เกิดข้อผิดพลาด: " . $conn->error;
                    $msg_type = "danger";
                }
            }
        }
    }
}

// ค้นหาอาจารย์
$search = trim($_GET['search'] ?? '');
$sql_t = "SELECT id, fullname, email FROM users WHERE role = 'teacher' AND id != ?";
if ($search !== '') {
    $sql_t .= " AND (fullname LIKE ? OR email LIKE ?)";
}
$stmt = $conn->prepare($sql_t);
if ($search !== '') {
    $like = "%$search%";
    $stmt->bind_param("iss", $student_id, $like, $like);
} else {
    $stmt->bind_param("i", $student_id);
}
$stmt->execute();
$teachers = $stmt->get_result();

// Sidebar Logic
$has_project_access = false;
$q = $conn->prepare("SELECT 1 FROM enrollments WHERE student_id = ? AND status = 'approved' LIMIT 1");
$q->bind_param("i", $student_id); $q->execute();
if ($q->get_result()->num_rows > 0) $has_project_access = true;

$count_invite = 0;
$c_inv_q = $conn->prepare("SELECT COUNT(*) FROM project_members WHERE student_id = ? AND is_confirmed = 0");
$c_inv_q->bind_param("i", $student_id); $c_inv_q->execute();
$count_invite = $c_inv_q->get_result()->fetch_row()[0];
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เชิญอาจารย์ที่ปรึกษา</title>
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

    /* Search Form */
    .search-box { display: flex; gap: 10px; margin-bottom: 20px; justify-content: center; }
    .search-input { padding: 10px; width: 60%; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }
    .btn-search { background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; }
    
    /* Table */
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 15px; text-align: left; border-bottom: 1px solid #f1f5f9; }
    th { background: #f8fafc; color: #64748b; font-weight: 600; font-size: 14px; }
    td { font-size: 14px; color: #334155; }
    tr:hover { background: #f8fafc; }

    .btn-invite { padding: 8px 16px; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s; }
    .btn-invite:hover { background: #059669; }
    .btn-invite:disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }

    .btn-back { display: inline-flex; align-items: center; gap: 5px; text-decoration: none; color: #64748b; font-weight: 600; margin-top: 20px; }
    .btn-back:hover { color: #1e3a8a; }
    
    .empty-state { text-align: center; padding: 40px; color: #94a3b8; font-size: 14px; }
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
            <a href="my_groups.php"><i class="fas fa-users"></i> กลุ่มโครงงาน</a>
            <?php if ($count_invite > 0): ?>
                <a href="invitations.php"><i class="fas fa-envelope"></i> คำเชิญเข้ากลุ่ม <span class="menu-badge"><?= $count_invite ?></span></a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <a href="logout.php" class="logout-btn">🚪 ออกจากระบบ</a>
</div>

<div class="main-content">
    <div class="card">
        <div class="page-header">
            <h2>👨‍🏫 เชิญอาจารย์เป็นที่ปรึกษา</h2>
            <p>กลุ่ม: <strong><?= htmlspecialchars($group_data['project_name']) ?></strong></p>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msg_type ?>">
                <i class="fas fa-info-circle"></i> <?= $msg ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($group_data['advisor_id'])): ?>
            <div class="alert alert-warning">
                <i class="fas fa-check-circle"></i> กลุ่มนี้มีอาจารย์ที่ปรึกษาแล้ว
            </div>
        <?php endif; ?>

        <form method="GET" class="search-box">
            <input type="hidden" name="group_id" value="<?= $group_id ?>">
            <input type="text" name="search" class="search-input" placeholder="ค้นหาชื่ออาจารย์..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn-search"><i class="fas fa-search"></i> ค้นหา</button>
        </form>

        <?php if ($teachers->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>ชื่อ-นามสกุล</th>
                        <th>อีเมล</th>
                        <th width="20%">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $teachers->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['fullname']) ?></td>
                            <td><?= htmlspecialchars($row['email']) ?></td>
                            <td>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="teacher_id" value="<?= $row['id'] ?>">
                                    <button type="submit" class="btn-invite" <?= !empty($group_data['advisor_id']) ? 'disabled' : '' ?>>
                                        <i class="fas fa-paper-plane"></i> ส่งคำเชิญ
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
                ไม่พบรายชื่ออาจารย์
            </div>
        <?php endif; ?>

        <div style="text-align:center;">
            <a href="group_chat.php?id=<?= $group_id ?>" class="btn-back"><i class="fas fa-arrow-left"></i> กลับหน้ากลุ่ม</a>
        </div>
    </div>
</div>

</body>
</html>