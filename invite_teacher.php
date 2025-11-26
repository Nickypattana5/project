<?php
session_start();
include 'db_connect.php';

// ตรวจ session
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header("Location: login.php");
    exit;
}

$student_id = $_SESSION['user_id'];
$group_id = intval($_GET['group_id'] ?? 0);
$msg = "";

// 1. ตรวจสิทธิ์: เป็นสมาชิกกลุ่มนี้ไหม?
$chk = $conn->prepare("SELECT * FROM project_members WHERE group_id = ? AND student_id = ?");
$chk->bind_param("ii", $group_id, $student_id);
$chk->execute();
if ($chk->get_result()->num_rows == 0) {
    die("❌ คุณไม่มีสิทธิ์เชิญอาจารย์สำหรับกลุ่มนี้");
}
$chk->close();

// 2. ดึงข้อมูลกลุ่ม (ชื่อกลุ่ม + เช็คว่ามี advisor หรือยัง)
$chk2 = $conn->prepare("SELECT project_name, advisor_id FROM project_groups WHERE id = ?");
$chk2->bind_param("i", $group_id);
$chk2->execute();
$group_data = $chk2->get_result()->fetch_assoc();
if ($group_data && !empty($group_data['advisor_id'])) {
    $msg = "❌ กลุ่มนี้มีอาจารย์ที่ปรึกษาแล้ว";
}
$chk2->close();

// 3. ถ้ามีการส่งเชิญ (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['teacher_id'])) {
    $teacher_id = intval($_POST['teacher_id']);

    // ตรวจว่า target เป็น teacher (ไม่ใช่ admin)
    $r = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $r->bind_param("i", $teacher_id);
    $r->execute();
    $role_res = $r->get_result()->fetch_assoc();
    $r->close();

    if (!$role_res || $role_res['role'] !== 'teacher') {
        $msg = "❌ ไม่สามารถเชิญผู้ใช้นี้ (ต้องเป็นอาจารย์เท่านั้น)";
    } else {
        // ตรวจว่าเชิญซ้ำไหม
        $chk3 = $conn->prepare("SELECT id FROM advisor_invites WHERE group_id = ? AND teacher_id = ? AND status = 'pending'");
        $chk3->bind_param("ii", $group_id, $teacher_id);
        $chk3->execute();
        if ($chk3->get_result()->num_rows > 0) {
            $msg = "⚠️ ได้ส่งคำเชิญให้อาจารย์คนนี้ไปแล้ว (รอการตอบรับ)";
            $chk3->close();
        } else {
            // A. สร้างคำเชิญในตาราง advisor_invites
            $ins = $conn->prepare("INSERT INTO advisor_invites (group_id, sender_id, teacher_id) VALUES (?, ?, ?)");
            $ins->bind_param("iii", $group_id, $student_id, $teacher_id);
            
            if ($ins->execute()) {
                // ✅ B. เพิ่มระบบแจ้งเตือน (Notification) ลงฐานข้อมูล!
                $notif_msg = "คุณได้รับคำเชิญเป็นที่ปรึกษาโครงงาน: " . $group_data['project_name'];
                
                // type = 'invite_advisor' (เพื่อให้รู้ว่าเป็นเรื่องเชิญอาจารย์)
                $n_ins = $conn->prepare("INSERT INTO notifications (receiver_id, sender_id, type, group_id, message, is_read) VALUES (?, ?, 'invite_advisor', ?, ?, 0)");
                $n_ins->bind_param("iiis", $teacher_id, $student_id, $group_id, $notif_msg);
                $n_ins->execute();
                
                $msg = "✅ ส่งคำเชิญและแจ้งเตือนอาจารย์เรียบร้อยแล้ว";
            } else {
                $msg = "❌ เกิดข้อผิดพลาด: " . $conn->error;
            }
            $ins->close();
        }
    }
}
 
// ค้นหาอาจารย์
$search = trim($_GET['search'] ?? '');
if ($search === '') {
    $stmt = $conn->prepare("SELECT id, fullname, email FROM users WHERE role = 'teacher' AND id != ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
} else {
    $like = "%$search%";
    $stmt = $conn->prepare("SELECT id, fullname, email FROM users WHERE role = 'teacher' AND (fullname LIKE ? OR email LIKE ?) AND id != ?");
    $stmt->bind_param("ssi", $like, $like, $_SESSION['user_id']);
}
$stmt->execute();
$teachers = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>เชิญอาจารย์เป็นที่ปรึกษา</title>
<style>
    /* Style ง่ายๆ สไตล์ Dashboard */
    body{font-family:"Segoe UI",sans-serif;background:#f4f6f9;padding:20px}
    .container{max-width:800px;margin:0 auto;background:#fff;padding:25px;border-radius:12px;box-shadow:0 4px 10px rgba(0,0,0,0.05)}
    h2 { text-align: center; color: #1e3a8a; }
    input[type="text"]{padding:10px;width:70%;border:1px solid #ddd;border-radius:6px;}
    button{padding:10px 15px;border-radius:6px;background:#2563eb;color:#fff;border:none;cursor:pointer;font-weight:bold;}
    button:hover { background: #1d4ed8; }
    button:disabled { background: #ccc; cursor: not-allowed; }
    .msg{background:#d1fae5;color:#065f46;padding:10px;border-radius:6px;text-align:center;margin-bottom:15px;}
    .note{background:#fee2e2;color:#991b1b;padding:10px;border-radius:6px;text-align:center;margin-bottom:15px;}
    table{width:100%;border-collapse:collapse;margin-top:20px}
    th,td{border-bottom:1px solid #eee;padding:12px;text-align:left}
    th{background:#f8fafc;color:#334155;}
    .back-link { display:block; text-align:center; margin-top:20px; color:#64748b; text-decoration:none; }
    .back-link:hover { color:#333; }
</style>
</head>
<body>
<div class="container">
  <h2>👨‍🏫 เชิญอาจารย์เป็นที่ปรึกษา</h2>
  
  <?php if($msg) echo "<div class='msg'>$msg</div>"; ?>
  <?php if(!empty($group_data['advisor_id'])): ?>
    <div class="note">⚠️ กลุ่มนี้มีอาจารย์ที่ปรึกษาแล้ว</div>
  <?php endif; ?>

  <form method="GET" style="display:flex; gap:10px; justify-content:center;">
    <input type="hidden" name="group_id" value="<?= $group_id ?>">
    <input type="text" name="search" placeholder="ค้นหาชื่ออาจารย์..." value="<?= htmlspecialchars($search) ?>">
    <button type="submit">🔍 ค้นหา</button>
  </form>

  <table>
    <tr><th>ชื่อ-นามสกุล</th><th>อีเมล</th><th>การดำเนินการ</th></tr>
    <?php if ($teachers->num_rows > 0): ?>
        <?php while($t = $teachers->fetch_assoc()): ?>
          <tr>
            <td><?= htmlspecialchars($t['fullname']) ?></td>
            <td><?= htmlspecialchars($t['email']) ?></td>
            <td>
              <form method="POST" style="margin:0">
                <input type="hidden" name="teacher_id" value="<?= $t['id'] ?>">
                <button type="submit" <?= !empty($group_data['advisor_id']) ? 'disabled' : '' ?>>
                    ส่งคำเชิญ
                </button>
              </form>
            </td>
          </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr><td colspan="3" style="text-align:center; color:#999;">ไม่พบรายชื่ออาจารย์</td></tr>
    <?php endif; ?>
  </table>

  <a href="group_chat.php?id=<?= $group_id ?>" class="back-link">⬅ กลับไปหน้ากลุ่ม</a>
</div>
</body>
</html>