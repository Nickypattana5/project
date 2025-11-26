<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

// ตรวจล็อกอิน
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$fullname = $_SESSION['fullname'];
$role = $_SESSION['role'] ?? 'student';
$group_id = intval($_GET['id'] ?? 0);

// channel: 'group' หรือ 'admin'
$channel = $_GET['channel'] ?? 'group';
$valid = ['group','admin'];
if (!in_array($channel, $valid)) $channel = 'group';

// ดึงข้อมูลกลุ่ม + อาจารย์
$stmt = $conn->prepare("
    SELECT g.*, u.fullname AS advisor_name, c.course_name, c.course_code
    FROM project_groups g
    LEFT JOIN users u ON g.advisor_id = u.id
    LEFT JOIN courses c ON g.course_id = c.id
    WHERE g.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
if (!$group) die("❌ ไม่พบข้อมูลกลุ่ม");

// ตรวจสิทธิ์เข้าถึง
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

// ดึงสมาชิกนิสิต
$members = $conn->prepare("
    SELECT u.id, u.fullname, m.is_leader, m.is_confirmed
    FROM project_members m
    JOIN users u ON m.student_id = u.id
    WHERE m.group_id = ? AND m.is_confirmed = 1
    ORDER BY m.is_leader DESC, m.joined_at ASC
");
$members->bind_param("i", $group_id);
$members->execute();
$member_result = $members->get_result();

// โหลดข้อความแชท
$chats = $conn->prepare("
    SELECT c.*, u.fullname, u.role
    FROM project_chat c
    JOIN users u ON c.sender_id = u.id
    WHERE c.group_id = ? AND c.channel = ?
    ORDER BY c.created_at ASC
");
$chats->bind_param("is", $group_id, $channel);
$chats->execute();
$chat_result = $chats->get_result();

// Logic ตรวจสอบสถานะ (Lock Chat)
$can_send = true;
$lock_text = "";
$req_status = null;

// ดึงสถานะคำขออนุมัติล่าสุด
$req = $conn->prepare("SELECT status FROM project_approval_requests WHERE group_id = ? ORDER BY created_at DESC LIMIT 1");
$req->bind_param("i", $group_id);
$req->execute();
$r_res = $req->get_result()->fetch_assoc();
if ($r_res) $req_status = $r_res['status'];

if ($channel === 'group' && $group['status'] === 'approved') {
    $can_send = false;
    $lock_text = "🔒 โครงงานอนุมัติแล้ว: แชทกลุ่มถูกปิดเพื่อเก็บประวัติ";
}

if ($channel === 'admin') {
    if (!$req_status) {
        $can_send = false;
        $lock_text = "🔒 กรุณากด 'ขออนุมัติ' เพื่อเปิดช่องทางสื่อสารกับแอดมิน";
    }
    if ($group['status'] === 'approved') {
        $can_send = false;
        $lock_text = "🔒 โครงงานอนุมัติแล้ว: แชทแอดมินถูกปิด";
    }
}

// Handle ส่งข้อความ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_send) {
        // ถ้าส่งไม่ได้ ให้รีเฟรชเฉยๆ
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
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ห้องทำงาน: <?= htmlspecialchars($group['project_name']) ?></title>
<style>
    /* Global Theme */
    body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: #f4f6f9; color: #333; height: 100vh; overflow: hidden; }

    /* Layout: Sidebar + Main Content */
    .sidebar { width: 250px; height: 100vh; background: #1e3a8a; color: white; position: fixed; left: 0; top: 0; padding-top: 20px; z-index: 100; }
    .sidebar h2 { text-align: center; margin-bottom: 30px; font-size: 22px; }
    .sidebar a { display: block; padding: 12px 20px; color: white; text-decoration: none; font-size: 15px; transition: 0.2s; border-left: 4px solid transparent; }
    .sidebar a:hover { background: #3b82f6; border-left-color: #fff; }
    .logout { margin-top: 30px; display: inline-block; background: #dc2626; color: white; padding: 10px 15px; border-radius: 6px; text-decoration: none; margin-left: 20px; width: 180px; text-align: center; }

    /* Main Container */
    .content { margin-left: 260px; height: 100vh; display: flex; flex-direction: column; }
    
    /* Grid Layout: Chat (Left) | Info (Right) */
    .workspace-grid { 
        display: grid; grid-template-columns: 1fr 320px; 
        height: 100%; overflow: hidden; 
    }

    /* --- Left: Chat Area --- */
    .chat-section { display: flex; flex-direction: column; background: white; border-right: 1px solid #ddd; }
    
    .chat-header { 
        padding: 15px 25px; border-bottom: 1px solid #eee; background: white; 
        display: flex; justify-content: space-between; align-items: center;
    }
    .chat-header h1 { margin: 0; font-size: 18px; color: #1e3a8a; }
    
    .channel-tabs { display: flex; gap: 10px; }
    .tab { 
        text-decoration: none; padding: 6px 15px; border-radius: 20px; font-size: 13px; font-weight: 600; 
        background: #f1f5f9; color: #64748b; transition: 0.2s;
    }
    .tab.active { background: #3b82f6; color: white; }
    .tab:hover:not(.active) { background: #e2e8f0; }

    /* Messages Area */
    .chat-messages { 
        flex: 1; padding: 20px; overflow-y: auto; background: #f8fafc; 
        display: flex; flex-direction: column; gap: 10px; 
    }
    
    .msg-row { display: flex; flex-direction: column; max-width: 70%; }
    .msg-row.me { align-self: flex-end; align-items: flex-end; }
    .msg-row.other { align-self: flex-start; align-items: flex-start; }
    
    .msg-bubble { 
        padding: 10px 15px; border-radius: 12px; font-size: 14px; line-height: 1.5; 
        box-shadow: 0 1px 2px rgba(0,0,0,0.05); position: relative; word-wrap: break-word;
    }
    .msg-row.me .msg-bubble { background: #3b82f6; color: white; border-bottom-right-radius: 2px; }
    .msg-row.other .msg-bubble { background: white; color: #334155; border: 1px solid #e2e8f0; border-bottom-left-radius: 2px; }
    
    .msg-meta { font-size: 11px; margin-top: 4px; color: #94a3b8; }
    .msg-sender { font-weight: bold; font-size: 11px; margin-bottom: 2px; color: #64748b; }
    
    /* Input Area */
    .chat-input-area { 
        padding: 20px; background: white; border-top: 1px solid #eee; 
    }
    .input-wrapper { display: flex; gap: 10px; align-items: flex-start; }
    .txt-input { 
        flex: 1; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; 
        resize: none; height: 50px; font-family: inherit; font-size: 14px; 
    }
    .txt-input:focus { outline: none; border-color: #3b82f6; }
    .btn-send { 
        background: #3b82f6; color: white; border: none; padding: 0 20px; height: 50px; 
        border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 14px; 
    }
    .btn-send:hover { background: #2563eb; }
    .file-btn { 
        background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; 
        height: 50px; width: 50px; border-radius: 8px; cursor: pointer; 
        display: flex; justify-content: center; align-items: center; font-size: 20px;
    }

    /* --- Right: Info Panel --- */
    .info-section { 
        background: white; border-left: 1px solid #ddd; 
        padding: 25px; overflow-y: auto; 
    }
    .info-card { margin-bottom: 25px; }
    .info-title { 
        font-size: 13px; font-weight: bold; text-transform: uppercase; 
        color: #94a3b8; margin-bottom: 10px; letter-spacing: 0.5px; 
    }
    
    .member-list { list-style: none; padding: 0; margin: 0; }
    .member-item { 
        display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f1f5f9; 
    }
    .avatar { 
        width: 32px; height: 32px; background: #e0f2fe; color: #0369a1; 
        border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold;
    }
    .leader-badge { font-size: 10px; background: #fef3c7; color: #b45309; padding: 2px 6px; border-radius: 4px; }

    /* Action Buttons */
    .action-btn { 
        display: block; width: 100%; padding: 10px; margin-bottom: 10px; 
        text-align: center; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600; 
        border: none; cursor: pointer; box-sizing: border-box;
    }
    .btn-green { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .btn-green:hover { background: #bbf7d0; }
    .btn-blue { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }
    .btn-blue:hover { background: #bae6fd; }
    .btn-outline { background: white; border: 1px solid #cbd5e1; color: #475569; }
    .btn-outline:hover { background: #f8fafc; }

    .locked-notice { 
        padding: 15px; background: #fff3cd; color: #856404; text-align: center; 
        border-radius: 8px; font-size: 14px; 
    }
</style>
</head>
<body>

<div class="sidebar">
    <h2>📘 ระบบนิสิต</h2>
    <p style="text-align:center; font-size:13px; opacity:0.8; margin-bottom:20px;">
        <?= htmlspecialchars($fullname) ?><br>(<?= ucfirst($role) ?>)
    </p>
    <hr style="border-color:rgba(255,255,255,0.1); width:80%; margin: 0 auto 10px auto;">

    <?php if($role == 'student'): ?>
        <a href="dashboard.php">🏠 แดชบอร์ด</a>
        <a href="my_groups.php">🔙 กลับหน้ารวมกลุ่ม</a>
    <?php elseif($role == 'teacher'): ?>
        <a href="teacher_groups.php">🔙 กลับรายการกลุ่ม</a>
    <?php elseif($role == 'admin'): ?>
        <a href="admin_chat_list.php">🔙 กลับรายการแชท</a>
    <?php endif; ?>
    
    <a href="logout.php" class="logout">🚪 ออกจากระบบ</a>
</div>

<div class="content">
    <div class="workspace-grid">
        
        <div class="chat-section">
            <div class="chat-header">
                <div>
                    <h1><?= htmlspecialchars($group['project_name']) ?></h1>
                    <small style="color:#64748b"><?= htmlspecialchars($group['course_code']) ?> <?= htmlspecialchars($group['course_name']) ?></small>
                </div>
                <div class="channel-tabs">
                    <a href="?id=<?= $group_id ?>&channel=group" class="tab <?= $channel=='group'?'active':'' ?>">💬 แชทกลุ่ม</a>
                    <a href="?id=<?= $group_id ?>&channel=admin" class="tab <?= $channel=='admin'?'active':'' ?>">🛠 แอดมิน</a>
                </div>
            </div>

            <div class="chat-messages" id="chatBox">
                <?php if ($chat_result->num_rows > 0): ?>
                    <?php while($c = $chat_result->fetch_assoc()): ?>
                        <?php $is_me = ($c['sender_id'] == $user_id); ?>
                        <div class="msg-row <?= $is_me ? 'me' : 'other' ?>">
                            <?php if(!$is_me): ?>
                                <div class="msg-sender"><?= htmlspecialchars($c['fullname']) ?> (<?= ucfirst($c['role']) ?>)</div>
                            <?php endif; ?>
                            
                            <div class="msg-bubble">
                                <?= nl2br(htmlspecialchars($c['message'])) ?>
                                <?php if ($c['file_path']): ?>
                                    <div style="margin-top:5px; border-top:1px solid rgba(0,0,0,0.1); padding-top:5px;">
                                        <a href="<?= htmlspecialchars($c['file_path']) ?>" target="_blank" style="color:inherit; text-decoration:underline;">📎 ไฟล์แนบ</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="msg-meta"><?= date("H:i", strtotime($c['created_at'])) ?></div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="text-align:center; color:#94a3b8; margin-top:50px;">
                        <p>👋 ยังไม่มีข้อความในห้องนี้</p>
                        <small>เริ่มพิมพ์ทักทายกันได้เลย!</small>
                    </div>
                <?php endif; ?>
            </div>

            <div class="chat-input-area">
                <?php if ($can_send): ?>
                    <form method="POST" enctype="multipart/form-data" class="input-wrapper">
                        <label class="file-btn" title="แนบไฟล์">
                            📎 <input type="file" name="chat_file" style="display:none;">
                        </label>
                        <textarea name="message" class="txt-input" placeholder="พิมพ์ข้อความ..."></textarea>
                        <button type="submit" class="btn-send">ส่ง</button>
                    </form>
                <?php else: ?>
                    <div class="locked-notice">
                        <?= htmlspecialchars($lock_text) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="info-section">
            
            <div class="info-card">
                <div class="info-title">เมนูลัด</div>
                
                <?php if ($group['advisor_id']): ?>
                    <a href="meeting_list.php?group_id=<?= $group_id ?>" class="action-btn btn-blue">
                        📅 ห้องพบปะอาจารย์
                    </a>
                <?php endif; ?>

                <?php if ($channel == 'admin' && $role == 'student' && !$req_status): ?>
                    <a href="student_request_approval.php?group_id=<?= $group_id ?>" class="action-btn btn-green">
                        🚀 ขออนุมัติหัวข้อ
                    </a>
                <?php endif; ?>

                <?php if ($req_status == 'pending'): ?>
                    <div class="locked-notice">⏳ รอแอดมินตรวจสอบ</div>
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
                            <a href="invite_teacher.php?group_id=<?= $group_id ?>" style="display:block; margin-top:5px; font-size:12px; color:#3b82f6;">+ เชิญอาจารย์</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-card">
                <div class="info-title">สมาชิกในกลุ่ม</div>
                <ul class="member-list">
                    <?php while($m = $member_result->fetch_assoc()): ?>
                        <li class="member-item">
                            <div class="avatar"><?= mb_substr($m['fullname'], 0, 1) ?></div>
                            <div style="flex:1">
                                <div style="font-size:14px; font-weight:500;"><?= htmlspecialchars($m['fullname']) ?></div>
                                <?php if($m['is_leader']): ?>
                                    <span class="leader-badge">👑 หัวหน้ากลุ่ม</span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endwhile; ?>
                </ul>
                <?php 
                    // เช็คจำนวนสมาชิกเพื่อโชว์ปุ่มเชิญ
                    $cnt_q = $conn->prepare("SELECT COUNT(*) FROM project_members WHERE group_id = ?");
                    $cnt_q->bind_param("i", $group_id);
                    $cnt_q->execute();
                    $cnt = $cnt_q->get_result()->fetch_row()[0];
                ?>
                <?php if($role=='student' && $cnt < 3): ?>
                    <a href="invite_student.php?group_id=<?= $group_id ?>" class="action-btn btn-outline" style="margin-top:10px;">
                        + เชิญเพื่อนเพิ่ม
                    </a>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<script>
    // Auto Scroll to bottom
    const chatBox = document.getElementById('chatBox');
    chatBox.scrollTop = chatBox.scrollHeight;
</script>

</body>
</html>