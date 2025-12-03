<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

// ตรวจล็อกอิน
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_id = (int)$_SESSION['user_id'];
$fullname = $_SESSION['fullname'];
$role = $_SESSION['role'] ?? 'student';
$meeting_id = intval($_GET['meeting_id'] ?? 0);

// 1. โหลดข้อมูล Meeting + Group
$stmt = $conn->prepare("
    SELECT m.*, g.project_name, g.advisor_id, c.course_code
    FROM project_meetings m
    JOIN project_groups g ON m.group_id = g.id
    LEFT JOIN courses c ON g.course_id = c.id
    WHERE m.id = ?
");
$stmt->bind_param("i", $meeting_id);
$stmt->execute();
$meeting = $stmt->get_result()->fetch_assoc();
if (!$meeting) die("❌ ไม่พบห้องพบปะนี้");

$group_id = $meeting['group_id'];

// 2. ตรวจสิทธิ์ (เพิ่มให้ Admin เข้าได้)
$allow = false;
if ($role === 'admin') {
    $allow = true; // 🔥 แอดมินเข้าได้
} elseif ($role === 'teacher' && $meeting['advisor_id'] == $user_id) {
    $allow = true;
} else {
    $chk = $conn->prepare("SELECT id FROM project_members WHERE group_id = ? AND student_id = ?");
    $chk->bind_param("ii", $group_id, $user_id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) $allow = true;
}
if (!$allow) die("❌ ไม่มีสิทธิ์เข้าห้องนี้");

// 3. โหลดข้อความแชท
$chats = $conn->prepare("
    SELECT c.*, u.fullname, u.role
    FROM project_chat c
    JOIN users u ON c.sender_id = u.id
    WHERE c.meeting_id = ?
    ORDER BY c.created_at ASC
");
$chats->bind_param("i", $meeting_id);
$chats->execute();
$chat_result = $chats->get_result();

// 4. โหลดไฟล์ทั้งหมด (สำหรับ Dropdown)
$files_q = $conn->prepare("
    SELECT c.file_path, c.created_at, u.fullname
    FROM project_chat c
    JOIN users u ON c.sender_id = u.id
    WHERE c.meeting_id = ? AND c.file_path IS NOT NULL AND c.file_path != ''
    ORDER BY c.created_at DESC
");
$files_q->bind_param("i", $meeting_id);
$files_q->execute();
$all_files = $files_q->get_result();

// สถานะห้อง
$is_closed = (int)$meeting['is_closed'] === 1;

// 5. Handle POST (ส่งข้อความ/ปิดห้อง)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 🔥 ป้องกัน Admin ส่งข้อมูล (View Only)
    if ($role === 'admin') {
        header("Location: meeting_chat.php?meeting_id=".$meeting_id);
        exit;
    }

    // กรณีอาจารย์กดปิดห้อง
    if (isset($_POST['close_meeting']) && $role === 'teacher') {
        $score = intval($_POST['progress_score']);
        $end = $conn->prepare("UPDATE project_meetings SET is_closed = 1, closed_at = NOW(), progress_score = ? WHERE id = ?");
        $end->bind_param("ii", $score, $meeting_id);
        $end->execute();
        header("Location: meeting_chat.php?meeting_id=".$meeting_id);
        exit;
    }

    // กรณีส่งข้อความ
    $text = trim($_POST['message'] ?? '');
    $file_path = null;

    if ($is_closed) { $text = ""; } // ปิดแล้วห้ามส่งข้อความ

    if (!empty($_FILES['chat_file']['name'])) {
        $dir = "uploads/chat_meeting/";
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $filename = time() . "_" . basename($_FILES['chat_file']['name']);
        if (move_uploaded_file($_FILES['chat_file']['tmp_name'], $dir.$filename)) {
            $file_path = $dir.$filename;
        }
    }

    if ($text !== '' || $file_path !== null) {
        $ins = $conn->prepare("INSERT INTO project_chat (group_id, sender_id, message, file_path, channel, meeting_id) VALUES (?, ?, ?, ?, 'meeting', ?)");
        $ins->bind_param("iissi", $group_id, $user_id, $text, $file_path, $meeting_id);
        $ins->execute();
    }
    header("Location: meeting_chat.php?meeting_id=".$meeting_id);
    exit;
}

function getCleanFileName($path) { return preg_replace('/^\d+_/', '', basename($path)); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Meeting Week <?= $meeting['week_number'] ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Global Theme */
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: #f4f6f9; color: #333; height: 100vh; overflow: hidden; }
    
    /* Sidebar */
    .sidebar { width: 260px; height: 100vh; background: #1e3a8a; color: white; position: fixed; left: 0; top: 0; padding-top: 20px; z-index: 100; display: flex; flex-direction: column; }
    .sidebar h2 { text-align: center; margin-bottom: 30px; font-size: 22px; }
    .nav-links { flex: 1; }
    .nav-links a { display: block; padding: 12px 20px; color: white; text-decoration: none; font-size: 15px; transition: 0.2s; border-left: 4px solid transparent; }
    .nav-links a:hover { background: #3b82f6; border-left-color: #fff; }
    .logout-btn { margin: 20px; padding: 12px; text-align: center; background: #dc2626; color: white; text-decoration: none; border-radius: 8px; }

    /* Layout */
    .content { margin-left: 260px; height: 100vh; display: flex; flex-direction: column; }
    .chat-container { width: 100%; max-width: 900px; margin: 20px auto; background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); overflow: hidden; display: flex; flex-direction: column; height: 90vh; }

    /* Header */
    .chat-header { padding: 15px 25px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: #fff; }
    .header-left { display: flex; align-items: center; gap: 15px; }
    .chat-title h2 { margin: 0; font-size: 18px; color: #1e3a8a; }
    .chat-title p { margin: 0; font-size: 13px; color: #64748b; }
    
    .btn-back { text-decoration: none; color: #64748b; font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 5px; }
    .btn-back:hover { color: #1e3a8a; }

    .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; }
    .st-open { background: #dcfce7; color: #166534; }
    .st-closed { background: #fee2e2; color: #991b1b; }

    .btn-close { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; margin-left: 10px; border: none; cursor: pointer; }
    .btn-close:hover { background: #fecaca; }

    /* File Dropdown */
    .file-bar { border-bottom: 1px solid #f1f5f9; background: #f8fafc; }
    .file-toggle { padding: 10px 25px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; font-size: 13px; font-weight: 600; color: #475569; }
    .file-toggle:hover { background: #e2e8f0; }
    .dropdown-icon { transition: transform 0.3s; }
    .dropdown-icon.rotate { transform: rotate(180deg); }
    .file-list { display: none; padding: 0 25px 15px 25px; max-height: 200px; overflow-y: auto; border-bottom: 1px solid #eee; }
    .file-item { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
    .file-name { flex: 1; color: #2563eb; text-decoration: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .file-date { font-size: 11px; color: #94a3b8; }

    /* Messages */
    .chat-box { flex: 1; padding: 25px; overflow-y: auto; background: #f8fafc; display: flex; flex-direction: column; gap: 12px; }
    .msg-row { display: flex; flex-direction: column; max-width: 75%; }
    .msg-row.me { align-self: flex-end; align-items: flex-end; }
    .msg-row.other { align-self: flex-start; align-items: flex-start; }
    .msg-sender { font-size: 12px; color: #64748b; margin-bottom: 2px; margin-left: 2px; }
    .msg-bubble { padding: 12px 16px; border-radius: 14px; font-size: 14px; line-height: 1.5; box-shadow: 0 1px 2px rgba(0,0,0,0.05); position: relative; }
    .msg-row.me .msg-bubble { background: #3b82f6; color: white; border-bottom-right-radius: 2px; }
    .msg-row.other .msg-bubble { background: white; color: #334155; border: 1px solid #e2e8f0; border-bottom-left-radius: 2px; }
    .msg-meta { font-size: 11px; margin-top: 4px; color: #94a3b8; opacity: 0.8; }
    .file-attach { display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.2); padding: 8px; border-radius: 6px; margin-top: 5px; text-decoration: none; color: inherit; border: 1px solid rgba(255,255,255,0.3); font-size: 13px; }
    .msg-row.other .file-attach { background: #f1f5f9; border-color: #cbd5e1; color: #2563eb; }

    /* Input */
    .input-area { padding: 20px; background: white; border-top: 1px solid #eee; }
    .input-wrapper { display: flex; gap: 10px; align-items: flex-end; }
    .txt-input { flex: 1; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; resize: none; height: 50px; font-family: inherit; font-size: 14px; }
    .txt-input:focus { outline: none; border-color: #3b82f6; }
    .btn-send { background: #3b82f6; color: white; border: none; padding: 0 20px; height: 50px; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 14px; display: flex; align-items: center; gap: 5px; }
    .file-label { cursor: pointer; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; height: 50px; width: 50px; display: flex; align-items: center; justify-content: center; background: #f8fafc; transition: 0.2s; }
    .file-label:hover { background: #e2e8f0; }
    .closed-notice { width: 100%; text-align: center; padding: 15px; background: #fffbeb; color: #92400e; border: 1px dashed #fcd34d; border-radius: 8px; font-size: 14px; }
    .admin-notice { width: 100%; text-align: center; padding: 15px; background: #e0f2fe; color: #0369a1; border: 1px dashed #7dd3fc; border-radius: 8px; font-size: 14px; }

    /* Modal */
    .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
    .modal-content { background-color: white; margin: 15% auto; padding: 30px; border-radius: 12px; width: 400px; text-align: center; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
    .close-modal { float: right; font-size: 24px; cursor: pointer; color: #aaa; }
    .rating-options { display: flex; flex-direction: column; gap: 10px; margin: 20px 0; }
    .rating-label { display: flex; align-items: center; padding: 10px; border: 2px solid #eee; border-radius: 8px; cursor: pointer; transition: 0.2s; }
    .rating-label:hover { border-color: #3b82f6; background: #eff6ff; }
    .rating-input { margin-right: 15px; transform: scale(1.5); }
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
            <a href="meeting_list.php?group_id=<?= $group_id ?>">🔙 กลับรายการพบปะ</a>
        <?php endif; ?>
    </div>
    <a href="logout.php" class="logout-btn">🚪 ออกจากระบบ</a>
</div>

<div class="main-content">
    <div class="chat-container">
        
        <div class="chat-header">
            <div class="header-left">
                <a href="meeting_list.php?group_id=<?= $group_id ?>" class="btn-back"><i class="fas fa-arrow-left"></i> กลับ</a>
                <div class="chat-title">
                    <h2>📅 สัปดาห์ที่ <?= htmlspecialchars($meeting['week_number']) ?></h2>
                    <p><?= htmlspecialchars($meeting['project_name']) ?></p>
                </div>
            </div>
            <div>
                <?php if ($is_closed): ?>
                    <span class="status-badge st-closed">🔴 ปิดแล้ว (<?= $meeting['progress_score'] ?>%)</span>
                <?php else: ?>
                    <span class="status-badge st-open">🟢 เปิดอยู่</span>
                <?php endif; ?>
                
                <?php if ($role === 'teacher' && !$is_closed): ?>
                    <button onclick="openModal()" class="btn-close"><i class="fas fa-lock"></i> ปิดห้อง</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="file-bar">
            <div class="file-toggle" onclick="toggleFiles()">
                <span>📂 ไฟล์ในห้องนี้ (<?= $all_files->num_rows ?>)</span>
                <i class="fas fa-chevron-down dropdown-icon" id="fileArrow"></i>
            </div>
            <div class="file-list" id="fileList">
                <?php if ($all_files->num_rows > 0): ?>
                    <?php while($f = $all_files->fetch_assoc()): ?>
                        <div class="file-item">
                            <i class="fas fa-file-alt" style="color:#64748b;"></i>
                            <a href="<?= htmlspecialchars($f['file_path']) ?>" target="_blank" class="file-name">
                                <?= htmlspecialchars(getCleanFileName($f['file_path'])) ?>
                            </a>
                            <span class="file-date"><?= date("d/m H:i", strtotime($f['created_at'])) ?></span>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="text-align:center; color:#ccc; padding:10px;">ไม่มีไฟล์แนบ</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="chat-box" id="chatBox">
            <?php while ($c = $chat_result->fetch_assoc()): ?>
                <?php $is_me = ($c['sender_id'] == $user_id); ?>
                <div class="msg-row <?= $is_me ? 'me' : 'other' ?>">
                    <?php if(!$is_me): ?><div class="msg-sender"><?= htmlspecialchars($c['fullname']) ?></div><?php endif; ?>
                    <div class="msg-bubble">
                        <?php if(!empty($c['message'])): ?><?= nl2br(htmlspecialchars($c['message'])) ?><?php endif; ?>
                        <?php if ($c['file_path']): ?>
                            <a href="<?= htmlspecialchars($c['file_path']) ?>" target="_blank" class="file-attach">
                                <i class="fas fa-file-download"></i> <?= htmlspecialchars(getCleanFileName($c['file_path'])) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="msg-meta"><?= date("H:i", strtotime($c['created_at'])) ?></div>
                </div>
            <?php endwhile; ?>
        </div>

        <div class="input-area">
            <?php if ($role === 'admin'): ?>
                <div class="admin-notice"><i class="fas fa-eye"></i> คุณอยู่ในโหมดผู้ดูแลระบบ (ดูได้อย่างเดียว)</div>
            <?php elseif (!$is_closed): ?>
                <form method="POST" enctype="multipart/form-data" class="input-wrapper">
                    <label class="file-label" title="แนบไฟล์">
                        <input type="file" name="chat_file" style="display:none;">
                        <i class="fas fa-paperclip" style="color:#64748b; font-size:18px;"></i>
                    </label>
                    <textarea name="message" class="txt-input" placeholder="พิมพ์ข้อความ..."></textarea>
                    <button type="submit" class="btn-send">ส่ง <i class="fas fa-paper-plane"></i></button>
                </form>
            <?php else: ?>
                <div class="closed-notice"><i class="fas fa-lock"></i> ปิดการสนทนาแล้ว (ส่งได้เฉพาะไฟล์)</div>
                <form method="POST" enctype="multipart/form-data" style="margin-top:10px; text-align:center;">
                    <input type="file" name="chat_file" required>
                    <button type="submit" class="btn-send" style="display:inline-flex; width:auto; padding:0 15px;">ส่งไฟล์</button>
                </form>
            <?php endif; ?>
        </div>

    </div>
</div>

<div id="scoreModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal()">&times;</span>
        <h2 style="color:#1e3a8a; margin-top:0;">📊 ประเมินความคืบหน้า</h2>
        <form method="POST">
            <div class="rating-options">
                <label class="rating-label"><input type="radio" name="progress_score" value="25" class="rating-input" required><div>⭐ 25% <small>(น้อย)</small></div></label>
                <label class="rating-label"><input type="radio" name="progress_score" value="50" class="rating-input"><div>⭐⭐ 50% <small>(ปานกลาง)</small></div></label>
                <label class="rating-label"><input type="radio" name="progress_score" value="75" class="rating-input"><div>⭐⭐⭐ 75% <small>(มาก)</small></div></label>
                <label class="rating-label"><input type="radio" name="progress_score" value="100" class="rating-input"><div>⭐⭐⭐⭐ 100% <small>(เสร็จ)</small></div></label>
            </div>
            <button type="submit" name="close_meeting" class="btn-close" style="width:100%; margin-top:15px; padding:12px;">ยืนยันการปิดห้อง</button>
        </form>
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
    
    function openModal() { document.getElementById('scoreModal').style.display = "block"; }
    function closeModal() { document.getElementById('scoreModal').style.display = "none"; }
    window.onclick = function(event) { if (event.target == document.getElementById('scoreModal')) closeModal(); }
</script>

</body>
</html>