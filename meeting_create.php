<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

// ตรวจสอบสิทธิ์อาจารย์
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit;
}

$teacher_id = (int)$_SESSION['user_id'];
$fullname = $_SESSION['fullname'];
$group_id = intval($_GET['group_id'] ?? 0);
$msg = "";

// ตรวจสอบว่าเป็นที่ปรึกษาของกลุ่มนี้จริงหรือไม่
$stmt = $conn->prepare("SELECT id, project_name, advisor_id FROM project_groups WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();

if (!$group) {
    die("❌ ไม่พบกลุ่มโครงงาน");
}
if ($group['advisor_id'] != $teacher_id) {
    die("❌ คุณไม่ใช่อาจารย์ที่ปรึกษาของกลุ่มนี้");
}

// คำนวณสัปดาห์ถัดไป (Auto Increment Week)
$q = $conn->prepare("SELECT MAX(week_number) AS mx FROM project_meetings WHERE group_id = ?");
$q->bind_param("i", $group_id);
$q->execute();
$mx = $q->get_result()->fetch_assoc();
$next_week = ($mx['mx'] ?? 0) + 1;

// บันทึกข้อมูล
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $week_number = intval($_POST['week_number']);
    $note = trim($_POST['note']); // หัวข้อการประชุม หรือ หมายเหตุ

    if ($week_number < 1) $week_number = $next_week;

    $ins = $conn->prepare("INSERT INTO project_meetings (group_id, week_number, started_by, note, started_at, created_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
    $ins->bind_param("iiis", $group_id, $week_number, $teacher_id, $note);
    
    if ($ins->execute()) {
        // สร้างเสร็จแล้วเด้งไปหน้าแชทเลย
        $new_meeting_id = $ins->insert_id;
        header("Location: meeting_chat.php?meeting_id=" . $new_meeting_id);
        exit;
    } else {
        $msg = "❌ เกิดข้อผิดพลาด: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>สร้างห้องพบปะใหม่</title>
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
    .logout-btn { margin: 20px; padding: 12px; text-align: center; background: #dc2626; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.2s; }
    .logout-btn:hover { background: #b91c1c; }

    /* Content */
    .main-content { margin-left: 260px; padding: 30px; display: flex; justify-content: center; align-items: flex-start; min-height: 100vh; }
    
    /* Form Card */
    .form-card { background: white; width: 100%; max-width: 500px; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; margin-top: 50px; }
    .form-header { text-align: center; margin-bottom: 25px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; }
    .form-header h1 { margin: 0; font-size: 22px; color: #1e3a8a; }
    .form-header p { margin: 5px 0 0; font-size: 14px; color: #64748b; }

    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 8px; color: #475569; }
    .form-control { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; transition: 0.2s; }
    .form-control:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    
    .btn-group { display: flex; gap: 10px; margin-top: 30px; }
    .btn { flex: 1; padding: 10px; text-align: center; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: 0.2s; }
    .btn-submit { background: #10b981; color: white; }
    .btn-submit:hover { background: #059669; }
    .btn-cancel { background: #e2e8f0; color: #475569; }
    .btn-cancel:hover { background: #cbd5e1; }

    .alert-error { padding: 10px; background: #fee2e2; color: #991b1b; border-radius: 6px; margin-bottom: 15px; font-size: 14px; text-align: center; border: 1px solid #fca5a5; }
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>🎓 ระบบนิสิต</h2>
        <p><?= htmlspecialchars($fullname) ?> <br> (Teacher)</p>
    </div>
    <div class="nav-links">
        <a href="manage_courses.php"><i class="fas fa-book"></i> จัดการรายวิชา</a>
        <a href="teacher_groups.php" class="active"><i class="fas fa-user-graduate"></i> กลุ่มที่ปรึกษา</a> <a href="teacher_enrollments.php"><i class="fas fa-tasks"></i> อนุมัติลงทะเบียน</a>
        <a href="advisor_invitations.php"><i class="fas fa-envelope-open-text"></i> คำเชิญที่ปรึกษา</a>
        <a href="dashboard.php"><i class="fas fa-home"></i> กลับแดชบอร์ด</a>
    </div>
    <a href="logout.php" class="logout-btn">🚪 ออกจากระบบ</a>
</div>

<div class="main-content">
    
    <div class="form-card">
        <div class="form-header">
            <h1><i class="fas fa-calendar-plus"></i> นัดหมายการพบปะ</h1>
            <p>กลุ่ม: <strong><?= htmlspecialchars($group['project_name']) ?></strong></p>
        </div>

        <?php if ($msg): ?>
            <div class="alert-error"><?= $msg ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label class="form-label">สัปดาห์ที่ (Week No.)</label>
                <input type="number" name="week_number" class="form-control" value="<?= $next_week ?>" min="1" required>
            </div>

            <div class="form-group">
                <label class="form-label">หัวข้อการประชุม / หมายเหตุ (Optional)</label>
                <input type="text" name="note" class="form-control" placeholder="เช่น ตรวจความคืบหน้าบทที่ 1">
            </div>

            <div class="btn-group">
                <a href="meeting_list.php?group_id=<?= $group_id ?>" class="btn btn-cancel">ยกเลิก</a>
                <button type="submit" class="btn btn-submit">
                    <i class="fas fa-check"></i> สร้างและเข้าห้องแชท
                </button>
            </div>
        </form>
    </div>

</div>

</body>
</html>