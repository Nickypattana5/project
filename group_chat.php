<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_id = (int)$_SESSION['user_id'];
$fullname = $_SESSION['fullname'];
$role = $_SESSION['role'] ?? 'student';
$group_id = intval($_GET['id'] ?? 0);

// Channel
$channel = $_GET['channel'] ?? 'group';
$valid = ['group','admin'];
if (!in_array($channel, $valid)) $channel = 'group';

// 1. โหลดข้อมูล
$stmt = $conn->prepare("
    SELECT g.*, u.fullname AS advisor_name, c.course_name, c.course_code
    FROM project_groups g
    LEFT JOIN users u ON g.advisor_id = u.id
    LEFT JOIN courses c ON g.course_id = c.id
    WHERE g.id = ? LIMIT 1
");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
if (!$group) die("❌ ไม่พบข้อมูลกลุ่ม");

// 2. ตรวจสิทธิ์
$allow = false;
if ($role === 'teacher' && $group['advisor_id'] == $user_id) $allow = true;
elseif ($role === 'admin') $allow = true;
else {
    $chk = $conn->prepare("SELECT id FROM project_members WHERE group_id = ? AND student_id = ? LIMIT 1");
    $chk->bind_param("ii", $group_id, $user_id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) $allow = true;
}
if (!$allow) die("❌ คุณไม่มีสิทธิ์เข้ากลุ่มนี้");

// 3. สมาชิก
$members = $conn->prepare("SELECT u.id, u.fullname, m.is_leader FROM project_members m JOIN users u ON m.student_id = u.id WHERE m.group_id = ? AND m.is_confirmed = 1 ORDER BY m.is_leader DESC, m.joined_at ASC");
$members->bind_param("i", $group_id);
$members->execute();
$member_result = $members->get_result();

// 4. แชท
$chats = $conn->prepare("SELECT c.*, u.fullname, u.role FROM project_chat c JOIN users u ON c.sender_id = u.id WHERE c.group_id = ? AND c.channel = ? ORDER BY c.created_at ASC");
$chats->bind_param("is", $group_id, $channel);
$chats->execute();
$chat_result = $chats->get_result();

// 5. ไฟล์ (Dropdown)
$files_q = $conn->prepare("SELECT c.file_path, c.created_at, u.fullname FROM project_chat c JOIN users u ON c.sender_id = u.id WHERE c.group_id = ? AND c.channel = ? AND c.file_path IS NOT NULL AND c.file_path != '' ORDER BY c.created_at DESC");
$files_q->bind_param("is", $group_id, $channel);
$files_q->execute();
$all_files = $files_q->get_result();

// 6. Logic สถานะ (ตรวจสอบสิทธิ์การส่งข้อความ)
$can_send = true;
$lock_text = "";
$req_status = null;
$admin_note = "";

$req = $conn->prepare("SELECT status, note FROM project_approval_requests WHERE group_id = ? ORDER BY created_at DESC LIMIT 1");
$req->bind_param("i", $group_id);
$req->execute();
$r_res = $req->get_result()->fetch_assoc();
if ($r_res) {
    $req_status = $r_res['status'];
    $admin_note = $r_res['note'];
}

// --- 🟢 กฎห้องแชทกลุ่ม ---
if ($channel === 'group') {
    // 1. Approved -> ปิดถาวร
    if ($group['status'] === 'approved') {
        $can_send = false;
        $lock_text = "🔒 โครงงานอนุมัติแล้ว: แชทกลุ่มถูกปิด";
    }
    // 2. 🔥 Pending (รอตรวจสอบ) -> ปิดแชทกลุ่มชั่วคราว (ให้ไปคุยในแชทแอดมิน)
    elseif ($group['status'] === 'pending') {
        $can_send = false;
        $lock_text = "⏳ อยู่ระหว่างการตรวจสอบ: แชทกลุ่มถูกระงับชั่วคราว (กรุณาคุยในแชทแอดมิน)";
    }
    
    // 3. Admin -> ดูได้อย่างเดียวเสมอ
    if ($role === 'admin') {
        $can_send = false;
        $lock_text = "👀 แอดมินสามารถดูได้อย่างเดียว (View Only)";
    }
}

// --- 🔴 กฎห้องแชทแอดมิน ---
if ($channel === 'admin') {
    // 1. Draft (หรือยังไม่ขอ) -> ปิด (ต้องกดขอก่อน)
    if ($group['status'] === 'draft' || !$req_status) { 
        $can_send = false; 
        $lock_text = "🔒 สถานะ Draft: กรุณากด 'ขออนุมัติ' เพื่อเปิดช่องทางแอดมิน"; 
    }
    
    // 2. Approved -> ปิดถาวร
    if ($group['status'] === 'approved') { 
        $can_send = false; 
        $lock_text = "🔒 โครงงานอนุมัติแล้ว"; 
    }

    // 3. Pending / Rejected -> เปิดให้ทุกคนคุยได้ (Default: $can_send = true)
}

// 7. Handle Post
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // เช็คสิทธิ์การส่ง (ถ้า $can_send = false แสดงว่าถูกล็อก)
    // ยกเว้นกรณีเดียว: แอดมินในห้องแอดมิน ที่ยังไม่อนุมัติ/draft (ซึ่งจริงๆ $can_send จะ true อยู่แล้วในเคส Pending/Rejected)
    $isAdminInAdminChannel = ($channel == 'admin' && $role == 'admin' && $group['status'] != 'approved' && $group['status'] != 'draft');

    if (!$can_send && !$isAdminInAdminChannel) {
        header("Location: group_chat.php?id=$group_id&channel=$channel"); 
        exit; 
    }
    
    $text = trim($_POST['message'] ?? '');
    $file_path = null;

    if (!empty($_FILES['chat_file']['name'])) {
        $dir = "uploads/chat/";
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $filename = time() . "_" . basename($_FILES['chat_file']['name']);
        if (move_uploaded_file($_FILES['chat_file']['tmp_name'], $dir.$filename)) {
            $file_path = $dir.$filename;
        }
    }

    if ($text !== '' || $file_path !== null) {
        $ins = $conn->prepare("INSERT INTO project_chat (group_id, sender_id, message, file_path, channel) VALUES (?, ?, ?, ?, ?)");
        $ins->bind_param("iisss", $group_id, $user_id, $text, $file_path, $channel);
        $ins->execute();
    }
    header("Location: group_chat.php?id=$group_id&channel=$channel");
    exit;
}

function getCleanFileName($path) { return preg_replace('/^\d+_/', '', basename($path)); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ห้องทำงาน: <?= htmlspecialchars($group['project_name']) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Global Theme */
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: #f4f6f9; color: #333; height: 100vh; overflow: hidden; }
    
    /* Sidebar */
    .sidebar { width: 260px; height: 100vh; background: #1e3a8a; color: white; position: fixed; left: 0; top: 0; padding-top: 20px; z-index: 100; display: flex; flex-direction: column; }
    .sidebar h2 { text-align: center; margin-bottom: 30px; font-size: 22px; }
    .sidebar p { margin: 0; font-size: 13px; opacity: 0.8; }
    .nav-links { flex: 1; }
    .nav-links a { display: block; padding: 12px 20px; color: white; text-decoration: none; font-size: 15px; transition: 0.2s; border-left: 4px solid transparent; }
    .nav-links a:hover { background: #3b82f6; border-left-color: #fff; }
    .logout-btn { margin: 20px; padding: 12px; text-align: center; background: #dc2626; color: white; text-decoration: none; border-radius: 8px; }

    /* Layout */
    .content { margin-left: 260px; height: 100vh; display: flex; flex-direction: column; }
    .workspace-grid { display: grid; grid-template-columns: 1fr 320px; height: 100%; overflow: hidden; }

    /* Chat Section */
    .chat-section { display: flex; flex-direction: column; background: white; border-right: 1px solid #ddd; }
    .chat-header { padding: 15px 25px; border-bottom: 1px solid #eee; background: white; display: flex; justify-content: space-between; align-items: center; }
    .channel-tabs { display: flex; gap: 10px; }
    .tab { padding: 6px 15px; border-radius: 20px; font-size: 13px; font-weight: 600; background: #f1f5f9; color: #64748b; text-decoration: none; }
    .tab.active { background: #3b82f6; color: white; }

    .chat-messages { flex: 1; padding: 20px; overflow-y: auto; background: #f8fafc; display: flex; flex-direction: column; gap: 10px; }
    .msg-row { display: flex; flex-direction: column; max-width: 70%; }
    .msg-row.me { align-self: flex-end; align-items: flex-end; }
    .msg-row.other { align-self: flex-start; align-items: flex-start; }
    .msg-bubble { padding: 10px 15px; border-radius: 12px; font-size: 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); position: relative; word-wrap: break-word; }
    .msg-row.me .msg-bubble { background: #3b82f6; color: white; border-bottom-right-radius: 2px; }
    .msg-row.other .msg-bubble { background: white; color: #334155; border: 1px solid #e2e8f0; border-bottom-left-radius: 2px; }
    .msg-meta { font-size: 11px; margin-top: 4px; color: #94a3b8; }
    .msg-sender { font-weight: bold; font-size: 11px; margin-bottom: 2px; color: #64748b; }
    .file-attach { display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.2); padding: 8px; border-radius: 6px; margin-top: 5px; text-decoration: none; color: inherit; border: 1px solid rgba(255,255,255,0.3); font-size: 13px; }
    .msg-row.other .file-attach { background: #f1f5f9; border-color: #e2e8f0; color: #2563eb; }

    .chat-input-area { padding: 20px; background: white; border-top: 1px solid #eee; }
    .input-wrapper { display: flex; gap: 10px; }
    .txt-input { flex: 1; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; resize: none; height: 50px; font-family: inherit; }
    .btn-send { background: #3b82f6; color: white; border: none; padding: 0 20px; border-radius: 8px; cursor: pointer; font-weight: bold; }
    .file-btn { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; height: 50px; width: 50px; border-radius: 8px; cursor: pointer; display: flex; justify-content: center; align-items: center; font-size: 20px; }

    /* Info Panel */
    .info-section { background: white; border-left: 1px solid #ddd; padding: 25px; overflow-y: auto; }
    .info-card { margin-bottom: 25px; }
    .info-title { font-size: 13px; font-weight: bold; text-transform: uppercase; color: #94a3b8; margin-bottom: 10px; }
    
    .dropdown-header { display: flex; justify-content: space-between; align-items: center; cursor: pointer; padding: 10px; background: #f8fafc; border-radius: 8px; transition: 0.2s; font-weight: 600; font-size: 14px; color: #334155; }
    .dropdown-header:hover { background: #e2e8f0; }
    .dropdown-icon { transition: transform 0.3s ease; }
    .dropdown-icon.rotate { transform: rotate(180deg); }
    .file-list-container { display: none; margin-top: 10px; animation: slideDown 0.3s ease-out; }
    @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    .file-list { list-style: none; padding: 0; margin: 0; }
    .file-item { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    .file-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; font-weight: 500; color: #334155; flex: 1; }
    .file-download { color: #2563eb; text-decoration: none; }
    
    .action-btn { display: block; width: 100%; padding: 10px; margin-bottom: 10px; text-align: center; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600; border: none; cursor: pointer; }
    .btn-blue { background: #e0f2fe; color: #075985; }
    .btn-green { background: #dcfce7; color: #166534; }
    .btn-outline { background: white; border: 1px solid #cbd5e1; color: #475569; }
    
    .avatar { width: 32px; height: 32px; background: #e0f2fe; color: #0369a1; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; }
    .member-item { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
    .locked-notice { padding: 15px; background: #fff3cd; color: #856404; text-align: center; border-radius: 8px; font-size: 14px; }
    .reject-alert { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; padding: 15px; border-radius: 8px; margin-bottom: 15px; font-size: 13px; }
</style>
</head>
<body>

<div class="sidebar">
    <h2>📘 ระบบนิสิต</h2>
    <p style="text-align:center; font-size:13px; opacity:0.8; margin-bottom:20px;"><?= htmlspecialchars($fullname) ?><br>(<?= ucfirst($role) ?>)</p>
    <hr style="border-color:rgba(255,255,255,0.1); width:80%; margin: 0 auto 10px auto;">
    <div class="nav-links">
        <?php if($role == 'student'): ?>
            <a href="dashboard.php">🏠 แดชบอร์ด</a>
            <a href="my_groups.php">🔙 กลับหน้ารวมกลุ่ม</a>
        <?php elseif($role == 'teacher'): ?>
            <a href="teacher_groups.php">🔙 กลับรายการกลุ่ม</a>
        <?php elseif($role == 'admin'): ?>
            <a href="admin_chat_list.php">🔙 กลับรายการแชท</a>
        <?php endif; ?>
    </div>
    <a href="logout.php" class="logout-btn">🚪 ออกจากระบบ</a>
</div>

<div class="content">
    <div class="workspace-grid">
        <div class="chat-section">
            <div class="chat-header">
                <div>
                    <h1 style="font-size:18px; color:#1e3a8a; margin:0;"><?= htmlspecialchars($group['project_name']) ?></h1>
                    <small style="color:#64748b"><?= htmlspecialchars($group['course_code']) ?> <?= htmlspecialchars($group['course_name']) ?></small>
                </div>
                <div class="channel-tabs">
                    <a href="?id=<?= $group_id ?>&channel=group" class="tab <?= $channel=='group'?'active':'' ?>">💬 กลุ่ม</a>
                    <a href="?id=<?= $group_id ?>&channel=admin" class="tab <?= $channel=='admin'?'active':'' ?>">🛠 แอดมิน</a>
                </div>
            </div>

            <div class="chat-messages" id="chatBox">
                <?php if ($chat_result->num_rows > 0): ?>
                    <?php while($c = $chat_result->fetch_assoc()): ?>
                        <?php $is_me = ($c['sender_id'] == $user_id); ?>
                        <div class="msg-row <?= $is_me ? 'me' : 'other' ?>">
                            <?php if(!$is_me): ?><div class="msg-sender"><?= htmlspecialchars($c['fullname']) ?></div><?php endif; ?>
                            <div class="msg-bubble">
                                <?= nl2br(htmlspecialchars($c['message'])) ?>
                                <?php if ($c['file_path']): ?>
                                    <a href="<?= htmlspecialchars($c['file_path']) ?>" target="_blank" class="file-attach">
                                        <i class="fas fa-file-alt"></i> <?= htmlspecialchars(getCleanFileName($c['file_path'])) ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="msg-meta"><?= date("H:i", strtotime($c['created_at'])) ?></div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="text-align:center; color:#94a3b8; margin-top:50px;">เริ่มสนทนาได้เลย</div>
                <?php endif; ?>
            </div>

            <div class="chat-input-area">
                <?php 
                    $show_input = $can_send || ($channel == 'admin' && $role == 'admin' && $group['status'] != 'approved' && $group['status'] != 'draft');
                ?>

                <?php if ($show_input): ?>
                    <form method="POST" enctype="multipart/form-data" class="input-wrapper">
                        <label class="file-btn"><input type="file" name="chat_file" style="display:none;">📎</label>
                        <textarea name="message" class="txt-input" placeholder="พิมพ์ข้อความ..."></textarea>
                        <button type="submit" class="btn-send">ส่ง</button>
                    </form>
                <?php else: ?>
                    <div class="locked-notice"><?= htmlspecialchars($lock_text) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="info-section">
            <div class="info-card">
                <div class="dropdown-header" onclick="toggleFiles()">
                    <span>📂 ไฟล์ในแชท (<?= $all_files->num_rows ?>)</span>
                    <i class="fas fa-chevron-down dropdown-icon" id="fileArrow"></i>
                </div>
                <div class="file-list-container" id="fileList">
                    <?php if ($all_files->num_rows > 0): ?>
                        <ul class="file-list">
                            <?php while($f = $all_files->fetch_assoc()): ?>
                                <li class="file-item">
                                    <i class="fas fa-file-alt" style="color:#ef4444;"></i>
                                    <div style="flex:1; overflow:hidden;">
                                        <a href="<?= htmlspecialchars($f['file_path']) ?>" target="_blank" class="file-name" title="<?= htmlspecialchars(getCleanFileName($f['file_path'])) ?>">
                                            <?= htmlspecialchars(getCleanFileName($f['file_path'])) ?>
                                        </a>
                                        <div style="font-size:10px; color:#999;">
                                            <?= $f['fullname'] ?> • <?= date("d/m H:i", strtotime($f['created_at'])) ?>
                                        </div>
                                    </div>
                                    <a href="<?= htmlspecialchars($f['file_path']) ?>" target="_blank" class="file-download"><i class="fas fa-download"></i></a>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <div style="text-align:center; color:#ccc; font-size:13px; padding:10px;">ไม่มีไฟล์แนบ</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-card">
                <div class="info-title">เมนูลัด</div>
                <?php if (!empty($group['advisor_id']) && $group['status'] === 'approved'): ?>
                    <a href="meeting_list.php?group_id=<?= $group_id ?>" class="action-btn btn-blue">📅 ห้องพบปะอาจารย์</a>
                <?php endif; ?>
                
                <?php if ($channel == 'admin' && $role == 'student'): ?>
                    <?php if (!$req_status || $req_status == 'rejected'): ?>
                        <?php if ($req_status == 'rejected'): ?>
                            <div class="reject-alert">
                                <strong>❌ คำขอล่าสุดถูกปฏิเสธ</strong><br>
                                <small>กรุณาแก้ไขและส่งใหม่</small>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($group['advisor_id'])): ?>
                            <div class="locked-notice" style="margin-bottom:10px; background:#fffbeb; color:#92400e; border:1px solid #fcd34d;">
                                ⚠️ กรุณาเชิญอาจารย์ที่ปรึกษาก่อน
                            </div>
                            <button class="action-btn btn-outline" disabled style="opacity:0.5;">🚀 ขออนุมัติหัวข้อ (ล็อค)</button>
                        <?php else: ?>
                            <a href="student_request_approval.php?group_id=<?= $group_id ?>" class="action-btn btn-green" onclick="return confirm('⚠️ ยืนยันการส่งคำขออนุมัติโครงงาน?\n\nเมื่อส่งแล้ว แชทกลุ่มจะถูกล็อคชั่วคราวเพื่อรอการตรวจสอบจากแอดมิน\n\nคุณแน่ใจหรือไม่?')">
                                <?= ($req_status == 'rejected') ? '🔄 ส่งคำขอใหม่อีกครั้ง' : '🚀 ขออนุมัติหัวข้อ' ?>
                            </a>
                        <?php endif; ?>

                    <?php elseif ($req_status == 'pending'): ?>
                        <div class="locked-notice">⏳ รอแอดมินตรวจสอบ</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="info-card">
                <div class="info-title">อาจารย์ที่ปรึกษา</div>
                <div style="font-size:14px; color:#333;">
                    <?php if ($group['advisor_id']): ?>
                        👨‍🏫 <?= htmlspecialchars($group['advisor_name']) ?>
                    <?php else: ?>
                        <span style="color:#999">- ยังไม่มี -</span>
                        <?php if($role=='student'): ?>
                            <a href="invite_teacher.php?group_id=<?= $group_id ?>" class="action-btn btn-outline" style="margin-top:5px;">+ เชิญอาจารย์</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-card">
                <div class="info-title">สมาชิก</div>
                <ul class="member-list">
                    <?php while($m = $member_result->fetch_assoc()): ?>
                        <li class="member-item">
                            <div class="avatar"><?= mb_substr($m['fullname'], 0, 1) ?></div>
                            <div style="flex:1; font-size:13px;">
                                <?= htmlspecialchars($m['fullname']) ?>
                                <?php if($m['is_leader']): ?><i class="fas fa-crown" style="color:#f59e0b; font-size:10px;"></i><?php endif; ?>
                            </div>
                        </li>
                    <?php endwhile; ?>
                </ul>
                <?php 
                    $cnt_q = $conn->prepare("SELECT COUNT(*) FROM project_members WHERE group_id = ?");
                    $cnt_q->bind_param("i", $group_id);
                    $cnt_q->execute();
                    $cnt = $cnt_q->get_result()->fetch_row()[0];
                ?>
                <?php if($role=='student' && $cnt < 3): ?>
                    <a href="invite_student.php?group_id=<?= $group_id ?>" class="action-btn btn-outline" style="margin-top:10px;">+ เชิญเพื่อนเพิ่ม</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    const chatBox = document.getElementById('chatBox');
    chatBox.scrollTop = chatBox.scrollHeight;

    function toggleFiles() {
        const list = document.getElementById('fileList');
        const arrow = document.getElementById('fileArrow');
        if (list.style.display === 'none' || list.style.display === '') {
            list.style.display = 'block';
            arrow.classList.add('rotate');
        } else {
            list.style.display = 'none';
            arrow.classList.remove('rotate');
        }
    }
</script>

</body>
</html>