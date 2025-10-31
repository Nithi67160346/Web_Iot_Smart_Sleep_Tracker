<?php
// connect.php
$DB_HOST = "localhost";
$DB_NAME = "s67160346";          // <<< เปลี่ยนเป็นฐานของคุณ
$DB_USER = "s67160346";          // <<< ผู้ใช้ MySQL
$DB_PASS = "ccKWeE31";   // <<< รหัสผ่าน

$conn = mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if (!$conn) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  exit("DB connect error: " . mysqli_connect_error());
}
mysqli_set_charset($conn, "utf8mb4");
