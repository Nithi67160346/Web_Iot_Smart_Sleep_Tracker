<?php
require __DIR__ . '/../connect.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Bangkok');

function out($d,$code=200){ http_response_code($code); echo json_encode($d,JSON_UNESCAPED_UNICODE); exit; }

$api_key = $_GET['api_key'] ?? '';
if ($api_key === '') out(['ok'=>false,'error'=>'missing_api_key'], 200);

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

/* stage counts + avg */
$sql = "SELECT
          COUNT(*) AS sample,
          AVG(avg_bpm) AS avg_bpm,
          AVG(hr_std) AS avg_hr_std,
          SUM(stage='DEEP')  AS deep_cnt,
          SUM(stage='LIGHT') AS light_cnt,
          SUM(stage='REM')   AS rem_cnt,
          SUM(stage='AWAKE') AS awake_cnt
        FROM sleep_epochs
        WHERE device_id=?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $device_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$agg = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

/* session ปัจจุบัน */
$sql = "SELECT id, started_at FROM sleep_sessions
        WHERE device_id=? AND status='active'
        ORDER BY started_at DESC LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $device_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$ses = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

out([
  'ok' => true,
  'sample' => (int)($agg['sample'] ?? 0),
  'avg_bpm' => (float)($agg['avg_bpm'] ?? 0),
  'avg_hr_std' => (float)($agg['avg_hr_std'] ?? 0),
  'stage_counts' => [
    'DEEP'  => (int)($agg['deep_cnt'] ?? 0),
    'LIGHT' => (int)($agg['light_cnt'] ?? 0),
    'REM'   => (int)($agg['rem_cnt'] ?? 0),
    'AWAKE' => (int)($agg['awake_cnt'] ?? 0),
  ],
  'session' => $ses
    ? ['active'=>true, 'id'=>(int)$ses['id'], 'started_at'=>$ses['started_at']]
    : ['active'=>false]
]);
