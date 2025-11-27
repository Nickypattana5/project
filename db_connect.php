<?php
$servername = "localhost";
$username = "root";     // ปกติ XAMPP ใช้ root
$password = "";         // ถ้ายังไม่ได้ตั้งรหัสให้ว่างไว้
$dbname = "school_system"; // ชื่อฐานข้อมูลของคุณ

// สร้างการเชื่อมต่อ
$conn = new mysqli($servername, $username, $password, $dbname);

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ตั้งค่าภาษาไทยให้แสดงผลถูกต้อง
$conn->set_charset("utf8");

// ตั้งค่า Timezone เป็นประเทศไทย
date_default_timezone_set('Asia/Bangkok');
?>