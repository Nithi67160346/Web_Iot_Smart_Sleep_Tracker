<?php
// api/epochs.php
require __DIR__ . '/../connect.php';
header('Content-Type: application/json; charset=utf-8');

$api_key = $_GET['api_key'] ?? '';
$limit   = max(1, min(1000, (int)($_GET['limit'] ?? 200)));

if (!$api_key) { echo json_encode(['ok'=>false,'error'=>'missing_api_key']); exit; }

$sql = "SELECT id FROM sleep_devices WHERE api_key=?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "s", $api_key);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$dev = mysqli_fetch_assoc($res);
if (!$dev) { echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
$device_id = (int)$dev['id'];

$rs = mysqli_query($conn, "SELECT epoch_idx, ts_ms, stage, avg_bpm, hr_std, avg_motion_g0, beats, signal_ok
                           FROM sleep_epochs
                           WHERE device_id = $device_id
                           ORDER BY epoch_idx DESC
                           LIMIT $limit");
$rows = [];
while ($r = mysqli_fetch_assoc($rs)) $rows[] = $r;
$rows = array_reverse($rows);
echo json_encode(['ok'=>true,'rows'=>$rows], JSON_UNESCAPED_UNICODE);
