<?php
session_start();
include 'db_connect.php';

// ตรวจสอบสิทธิ์ Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit;
}

$fullname = $_SESSION['fullname'];
$msg = "";
$msg_type = ""; // success, danger

// ถ้ามีการกดลบ (GET ?delete=id)
if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);

    // ป้องกันไม่ให้ admin ลบตัวเอง
    if ($delete_id == $_SESSION['user_id']) {
        $msg = "❌ ไม่สามารถลบบัญชีแอดมินตัวเองได้";
        $msg_type = "danger";
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'teacher'");
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
            $msg = "✅ ลบอาจารย์เรียบร้อยแล้ว!";
            $msg_type = "success";
        } else {
            $msg = "❌ ลบไม่สำเร็จ: " . $conn->error;
            $msg_type = "danger";
        }
    }
}

// ดึงรายชื่ออาจารย์ทั้งหมด (role = teacher)
$result = $conn->query("SELECT id, username, fullname, created_at, email FROM users WHERE role = 'teacher' ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายชื่ออาจารย์</title>
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
        
        /* Page Header */
        .page-header { margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; }
        .page-header h1 { margin: 0; font-size: 24px; color: #1e3a8a; }

        /* Alert */
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; font-size: 14px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

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

        /* Teacher Info */
        .teacher-info { display: flex; align-items: center; gap: 10px; }
        .avatar { width: 40px; height: 40px; background: #e0f2fe; color: #0369a1; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 16px; }
        .teacher-name { font-weight: 600; color: #1e293b; display: block; }
        .teacher-email { font-size: 12px; color: #64748b; }

        /* Buttons */
        .btn-add { padding: 10px 20px; background: #10b981; color: white; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14px; display: inline-flex; align-items: center; gap: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .btn-add:hover { background: #059669; }

        .action-btn { padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; margin-right: 5px; display: inline-flex; align-items: center; gap: 5px; }
        .btn-edit { background: #fef3c7; color: #d97706; }
        .btn-edit:hover { background: #fde68a; }
        .btn-delete { background: #fee2e2; color: #dc2626; }
        .btn-delete:hover { background: #fecaca; }

        .empty-state { text-align: center; padding: 50px; color: #94a3b8; font-size: 14px; }
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
        <a href="admin_chat_list.php"><i class="fas fa-comments"></i> แชททั้งหมด</a>
        <a href="list_teachers.php" class="active"><i class="fas fa-chalkboard-teacher"></i> รายชื่ออาจารย์ทั้งหมด</a>
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
</div>

<div class="main-content">
    
    <div class="page-header">
        <h1>📋 รายชื่ออาจารย์ทั้งหมด</h1>
        <a href="add_teacher.php" class="btn-add"><i class="fas fa-user-plus"></i> เพิ่มอาจารย์ใหม่</a>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?>">
            <i class="fas fa-info-circle"></i> <?= $msg ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <?php if ($result->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th width="10%">ID</th>
                        <th>ชื่อ-นามสกุล</th>
                        <th>ชื่อผู้ใช้ (Username)</th>
                        <th>วันที่เพิ่ม</th>
                        <th width="20%">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td>#<?= $row['id'] ?></td>
                            <td>
                                <div class="teacher-info">
                                    <div class="avatar"><?= mb_substr($row['fullname'], 0, 1) ?></div>
                                    <div>
                                        <span class="teacher-name"><?= htmlspecialchars($row['fullname']) ?></span>
                                        <span class="teacher-email"><?= htmlspecialchars($row['email'] ?? '-') ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($row['username']) ?></td>
                            <td><?= date("d/m/Y", strtotime($row['created_at'])) ?></td>
                            <td>
                                <a href="edit_teacher.php?id=<?= $row['id'] ?>" class="action-btn btn-edit">
                                    <i class="fas fa-edit"></i> แก้ไข
                                </a>
                                <a href="?delete=<?= $row['id'] ?>" class="action-btn btn-delete" onclick="return confirm('⚠️ ยืนยันการลบบัญชีอาจารย์ท่านนี้? \n(ข้อมูลวิชาและการเป็นที่ปรึกษาจะหายไปด้วย)')">
                                    <i class="fas fa-trash-alt"></i> ลบ
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-users-slash" style="font-size: 48px; margin-bottom: 10px;"></i>
                <h3>ยังไม่มีข้อมูลอาจารย์</h3>
                <p>กดปุ่ม "เพิ่มอาจารย์ใหม่" เพื่อเริ่มใช้งาน</p>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>