<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db_connect.php';

// -----------------------------
// ต้องเป็น admin เท่านั้น
// -----------------------------
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$admin_id   = $_SESSION['user_id'];
$request_id = intval($_GET['request_id'] ?? 0);
$group_id   = intval($_GET['group_id'] ?? 0);

// -----------------------------
// ดึงข้อมูลคำขออนุมัติ (และข้อมูลกลุ่ม/ผู้ขอ/อาจารย์)
// -----------------------------
$sql = "
    SELECT 
        r.id AS req_id,
        r.group_id AS req_group_id,
        r.requested_by,
        r.status AS req_status,
        r.admin_id AS req_admin_id,
        r.note AS req_note,
        r.created_at AS req_created_at,
        r.updated_at AS req_updated_at,
        g.project_name,
        g.advisor_id,
        g.status AS group_status,
        u.fullname AS requester_name,
        a.fullname AS advisor_name
    FROM project_approval_requests r
    JOIN project_groups g ON r.group_id = g.id
    JOIN users u ON r.requested_by = u.id
    LEFT JOIN users a ON g.advisor_id = a.id
    WHERE r.id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $request_id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();

if (!$request) {
    die("❌ ไม่พบข้อมูลคำขอ (request_id ไม่ถูกต้อง)");
}

// ให้แน่ใจว่า group_id ที่ได้จาก request ถูกใช้ (ป้องกัน mismatch)
$group_id = (int)$request['req_group_id'];

// -----------------------------
// ดึงสมาชิกในกลุ่ม (นิสิตที่ยืนยันแล้ว)
// -----------------------------
$sql = "
    SELECT u.id, u.fullname
    FROM project_members m
    JOIN users u ON m.student_id = u.id
    WHERE m.group_id = ? AND m.is_confirmed = 1
    ORDER BY m.joined_at ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $group_id);
$stmt->execute();
$member_list = $stmt->get_result();

// -----------------------------
// ดึงไฟล์แนบจาก project_chat (เฉพาะ channel ที่เกี่ยวข้อง)
// limit to group & advisor channels
// -----------------------------
$sql = "
    SELECT c.sender_id, c.file_path, u.fullname, c.created_at AS chat_created_at, c.channel
    FROM project_chat c
    JOIN users u ON c.sender_id = u.id
    WHERE c.group_id = ?
      AND c.file_path IS NOT NULL
      AND c.channel IN ('group','advisor')
    ORDER BY c.created_at ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $group_id);
$stmt->execute();
$file_list = $stmt->get_result();

// -----------------------------
// ถ้ากดอนุมัติ/ปฏิเสธ
// -----------------------------
$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // ตรวจสถานะก่อน - ถ้าไม่ pending ให้หยุด
    if ($request['req_status'] !== 'pending' && $request['req_status'] !== 'draft') {
        $msg = "คำขอนี้ถูกดำเนินการแล้ว (สถานะ: " . htmlspecialchars($request['req_status']) . ")";
    } else {
        $action = $_POST['action']; // 'approve' หรือ 'reject'
        $note   = trim($_POST['note'] ?? '');

        if ($action === 'approve') {
            // อัปเดต request -> approved
            $u = $conn->prepare("
                UPDATE project_approval_requests
                SET status = 'approved', admin_id = ?, note = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $u->bind_param("isi", $admin_id, $note, $request_id);
            $ok = $u->execute();

            if ($ok) {
                // อัปเดตสถานะกลุ่มเป็น approved (prepared)
                $g = $conn->prepare("UPDATE project_groups SET status = 'approved' WHERE id = ?");
                $g->bind_param("i", $group_id);
                $g->execute();

                // ส่งแจ้งเตือนให้ผู้ขอ (requested_by)
                $notification_msg = "คำขออนุมัติโครงงาน '{$request['project_name']}' ได้รับการอนุมัติแล้ว";
                $n = $conn->prepare("
                    INSERT INTO notifications (receiver_id, sender_id, type, group_id, message)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $type = 'approval_result';
                $n->bind_param("iisis", $request['requested_by'], $admin_id, $type, $group_id, $notification_msg);
                $n->execute();

                header("Location: admin_approval_list.php?msg=approved");
                exit;
            } else {
                $msg = "❌ เกิดข้อผิดพลาดในการอัปเดต (approve): " . $conn->error;
            }
        } elseif ($action === 'reject') {
            // อัปเดต request -> rejected
            $u = $conn->prepare("
                UPDATE project_approval_requests
                SET status = 'rejected', admin_id = ?, note = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $u->bind_param("isi", $admin_id, $note, $request_id);
            $ok = $u->execute();

            if ($ok) {
                // อาจเลือกเปลี่ยนสถานะกลุ่มเป็น draft หรือ leave as-is
                $g = $conn->prepare("UPDATE project_groups SET status = 'rejected' WHERE id = ?");
                $g->bind_param("i", $group_id);
                $g->execute();

                // ส่งแจ้งเตือนให้ผู้ขอ (requested_by)
                $notification_msg = "คำขออนุมัติโครงงาน '{$request['project_name']}' ถูกปฏิเสธ: " . ($note ?: '-');
                $n = $conn->prepare("
                    INSERT INTO notifications (receiver_id, sender_id, type, group_id, message)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $type = 'approval_result';
                $n->bind_param("iisis", $request['requested_by'], $admin_id, $type, $group_id, $notification_msg);
                $n->execute();

                header("Location: admin_approval_list.php?msg=rejected");
                exit;
            } else {
                $msg = "❌ เกิดข้อผิดพลาดในการอัปเดต (reject): " . $conn->error;
            }
        } else {
            $msg = "คำสั่งไม่ถูกต้อง";
        }
    }
}

// -----------------------------
// ฟังก์ชันช่วยแสดงสถานะแบบมีสี
// -----------------------------
function render_status_badge($status) {
    $cls = 'pending'; $txt = htmlspecialchars($status);
    if ($status === 'approved') { $cls = 'approved'; }
    elseif ($status === 'rejected') { $cls = 'rejected'; }
    elseif ($status === 'pending') { $cls = 'pending'; }
    return "<span style='display:inline-block;padding:6px 10px;border-radius:6px;color:white;background:" .
           ($cls === 'approved' ? '#28a745' : ($cls === 'rejected' ? '#dc3545' : '#ffc107')) .
           "'>" . $txt . "</span>";
}

// -----------------------------
// HTML หน้าแสดงผล
// -----------------------------
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ตรวจสอบคำขออนุมัติโครงงาน</title>
<style>
body { font-family: sans-serif; background:#f4f4f4; padding:20px; }
.container { max-width:1000px; margin: auto; background:white; padding:20px; border-radius:10px; box-shadow:0 0 12px rgba(0,0,0,0.06); }
h2 { text-align:center; margin-top:0; }
.section { margin-bottom:20px; }
.table { width:100%; border-collapse:collapse; }
.table th, .table td { padding:8px 10px; border-bottom:1px solid #eee; text-align:left; }
.file-link { color:#007bff; text-decoration:none; }
.form-note { width:100%; height:100px; padding:8px; border:1px solid #ccc; border-radius:6px; }
.btn { padding:10px 14px; border-radius:8px; border:none; cursor:pointer; font-weight:bold; }
.btn-approve { background:#28a745; color:white; }
.btn-reject { background:#dc3545; color:white; margin-left:12px; }
.notice { padding:10px; border-radius:6px; background:#fff3cd; color:#856404; margin-bottom:15px; }
.error { padding:10px; border-radius:6px; background:#f8d7da; color:#721c24; margin-bottom:15px; }
</style>
</head>
<body>

<div class="container">
    <h2>📘 ตรวจสอบคำขออนุมัติโครงงาน</h2>

    <p><a href="admin_approval_list.php">⬅ กลับไปยังรายการคำขอ</a></p>

    <?php if ($msg): ?>
        <div class="error"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="section">
        <h3>ข้อมูลคำขอ</h3>
        <table class="table">
            <tr>
                <th>ชื่อโครงงาน</th>
                <td><?= htmlspecialchars($request['project_name']) ?></td>
            </tr>
            <tr>
                <th>ผู้ขอ</th>
                <td><?= htmlspecialchars($request['requester_name']) ?></td>
            </tr>
            <tr>
                <th>สถานะคำขอ</th>
                <td><?= render_status_badge($request['req_status']) ?></td>
            </tr>
            <tr>
                <th>สถานะกลุ่ม</th>
                <td><?= htmlspecialchars($request['group_status']) ?></td>
            </tr>
            <tr>
                <th>อาจารย์ที่ปรึกษา</th>
                <td><?= htmlspecialchars($request['advisor_name'] ?? '-') ?></td>
            </tr>
            <tr>
                <th>วันที่ส่งคำขอ</th>
                <td><?= htmlspecialchars($request['req_created_at']) ?></td>
            </tr>
            <?php if (!empty($request['req_note'])): ?>
            <tr>
                <th>หมายเหตุเดิม</th>
                <td><?= nl2br(htmlspecialchars($request['req_note'])) ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="section">
        <h3>สมาชิกกลุ่ม</h3>
        <ul>
            <?php while ($m = $member_list->fetch_assoc()): ?>
                <li><?= htmlspecialchars($m['fullname']) ?></li>
            <?php endwhile; ?>
        </ul>
    </div>

    <div class="section">
        <h3>ไฟล์แนบจากการปรึกษา (group / advisor)</h3>

        <?php if ($file_list->num_rows > 0): ?>
            <table class="table">
                <tr><th>ผู้ส่ง</th><th>ไฟล์</th><th>วันที่</th><th>ช่อง (channel)</th></tr>
                <?php while ($f = $file_list->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($f['fullname']) ?></td>
                        <td><a class="file-link" href="<?= htmlspecialchars($f['file_path']) ?>" target="_blank">ดาวน์โหลด</a></td>
                        <td><?= htmlspecialchars($f['chat_created_at']) ?></td>
                        <td><?= htmlspecialchars($f['channel']) ?></td>
                    </tr>
                <?php endwhile; ?>
            </table>
        <?php else: ?>
            <p style="color:#6b7280;">ไม่มีไฟล์แนบจากการปรึกษา</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h3>ดำเนินการ (approve / reject)</h3>

        <?php if ($request['req_status'] !== 'pending' && $request['req_status'] !== 'draft'): ?>
            <div class="notice">คำขอนี้ถูกดำเนินการแล้ว (สถานะ: <?= htmlspecialchars($request['req_status']) ?>)</div>
        <?php else: ?>

        <form method="POST">
            <label for="note">หมายเหตุ (แจ้งผู้ขอ) — ไม่บังคับ</label><br>
            <textarea id="note" name="note" class="form-note" placeholder="เช่น: ต้องปรับหัวข้อนี้..."></textarea>
            <br><br>
            <button type="submit" name="action" value="approve" class="btn btn-approve">✔ อนุมัติ</button>
            <button type="submit" name="action" value="reject" class="btn btn-reject">✖ ปฏิเสธ</button>
        </form>

        <?php endif; ?>
    </div>

</div>

</body>
</html>
