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

/* ปิด session ล่าสุดที่ยัง active */
$sql = "SELECT id, started_at FROM sleep_sessions
        WHERE device_id=? AND status='active'
        ORDER BY started_at DESC LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $device_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$ses = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$ses) out(['ok'=>false,'error'=>'no_active_session'], 200);

$sql = "UPDATE sleep_sessions SET status='ended', ended_at=NOW() WHERE id=?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $ses['id']);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);
if (!$ok) out(['ok'=>false,'error'=>'end_failed'], 500);

out(['ok'=>true,'session_id'=>$ses['id'],'status'=>'ended']);
