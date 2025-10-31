<?php
require __DIR__ . '/../connect.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Bangkok');

function out($d,$code=200){ http_response_code($code); echo json_encode($d,JSON_UNESCAPED_UNICODE); exit; }

$api_key = $_GET['api_key'] ?? '';
if ($api_key === '') out(['ok'=>false,'error'=>'missing_api_key'], 400);

/* map api_key -> device_id */
$sql = "SELECT id FROM sleep_devices WHERE api_key=? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "s", $api_key);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$dev = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);
if (!$dev) out(['ok'=>false,'error'=>'invalid_api_key'], 200);
$device_id = (int)$dev['id'];

/* ปิด session เดิมถ้ายัง active */
$sql = "UPDATE sleep_sessions SET status='ended', ended_at=NOW()
        WHERE device_id=? AND status='active'";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $device_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

/* สร้าง session ใหม่ */
$sql = "INSERT INTO sleep_sessions (device_id, started_at, status)
        VALUES (?, NOW(), 'active')";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $device_id);
$ok = mysqli_stmt_execute($stmt);
if (!$ok) out(['ok'=>false,'error'=>'create_failed','detail'=>mysqli_stmt_error($stmt)], 500);
$new_id = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

out(['ok'=>true,'session_id'=>$new_id,'status'=>'active']);
