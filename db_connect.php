<?php
$servername = "localhost";
$username = "root";     // ปกติ XAMPP ใช้ root
$password = "";         // ถ้ายังไม่ได้ตั้งรหัสให้ว่างไว้
$dbname = "school_system";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
