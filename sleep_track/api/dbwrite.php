<?php
// api/dbwrite.php
require __DIR__ . '/../connect.php';
header('Content-Type: application/json; charset=utf-8');

// ฟังก์ชันส่งออก JSON + สถานะ
function out($d, $code=200){
  http_response_code($code);
  echo json_encode($d, JSON_UNESCAPED_UNICODE);
  exit;
}

// ---- อ่านอินพุต (JSON > POST > GET) ----
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!$payload) $payload = $_POST ?: $_GET ?: null;
if (!$payload || !is_array($payload)) out(['ok'=>false,'error'=>'invalid_input','raw_len'=>strlen($raw ?? '')], 400);

// รองรับ alias เก่า
if (isset($payload['timestamp_ms']) && !isset($payload['ts_ms'])) {
  $payload['ts_ms'] = $payload['timestamp_ms'];
}

// ---- ตรวจฟิลด์จำเป็น ----
$need = ['api_key','epoch_idx','ts_ms','stage','avg_bpm','hr_std','avg_motion_g0','beats','signal_ok'];
foreach ($need as $k) {
  if (!array_key_exists($k, $payload)) out(['ok'=>false,'error'=>"missing_$k"], 400);
}

// ---- sanitize / แปลงชนิด ----
$api_key       = trim((string)$payload['api_key']);
$epoch_idx     = (int)$payload['epoch_idx'];
$ts_ms         = (int)$payload['ts_ms'];
$stage         = strtoupper(trim((string)$payload['stage']));
$avg_bpm       = (double)$payload['avg_bpm'];
$hr_std        = (double)$payload['hr_std'];
$avg_motion_g0 = (double)$payload['avg_motion_g0'];
$beats         = (int)$payload['beats'];
$signal_ok     = (int)$payload['signal_ok'];

if (!in_array($stage, ['AWAKE','LIGHT','DEEP','REM'], true)) {
  out(['ok'=>false,'error'=>'bad_stage'], 400);
}

// ---- map api_key -> device_id ----
$sql = "SELECT id FROM sleep_devices WHERE api_key = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "s", $api_key);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$dev = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$dev) out(['ok'=>false,'error'=>'unauthorized'], 401);
$device_id = (int)$dev['id'];

// ---- หา session ที่กำลัง active (เพื่อใส่ใน response; insert จะใช้ subquery อยู่แล้ว) ----
$session_id = null;
$sql = "SELECT id FROM sleep_sessions WHERE device_id=? AND status='active' ORDER BY started_at DESC LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $device_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
if ($row = mysqli_fetch_assoc($res)) {
  $session_id = (int)$row['id'];
}
mysqli_stmt_close($stmt);

// ---- INSERT / UPSERT
// ใช้ subquery หา session_id ตอน INSERT โดยตรง (จะได้ไม่ต้อง bind NULL)
// หมายเหตุ: ตาราง sleep_epochs ควรมี UNIQUE(device_id, epoch_idx) เพื่อให้ upsert ทำงานถูกต้อง
$sql = "
  INSERT INTO sleep_epochs
    (device_id, session_id, epoch_idx, ts_ms, stage, avg_bpm, hr_std, avg_motion_g0, beats, signal_ok)
  VALUES
    (
      ?,                                                     -- device_id
      (SELECT id FROM sleep_sessions
         WHERE device_id = ? AND status = 'active'
         ORDER BY started_at DESC LIMIT 1),                  -- session_id (อาจได้ NULL)
      ?, ?, ?, ?, ?, ?, ?, ?
    )
  ON DUPLICATE KEY UPDATE
    ts_ms=VALUES(ts_ms),
    stage=VALUES(stage),
    avg_bpm=VALUES(avg_bpm),
    hr_std=VALUES(hr_std),
    avg_motion_g0=VALUES(avg_motion_g0),
    beats=VALUES(beats),
    signal_ok=VALUES(signal_ok)
";
$stmt = mysqli_prepare($conn, $sql);
// ชนิด: device_id(i), device_id(i), epoch_idx(i), ts_ms(i), stage(s),
//       avg_bpm(d), hr_std(d), avg_motion_g0(d), beats(i), signal_ok(i)
mysqli_stmt_bind_param(
  $stmt,
  "iiiisdddii",
  $device_id, $device_id, $epoch_idx, $ts_ms, $stage, $avg_bpm, $hr_std, $avg_motion_g0, $beats, $signal_ok
);
$ok = mysqli_stmt_execute($stmt);
if (!$ok) {
  $err = mysqli_stmt_error($stmt);
  mysqli_stmt_close($stmt);
  out(['ok'=>false,'error'=>'insert_failed','detail'=>$err], 500);
}
mysqli_stmt_close($stmt);

// ---- ส่งผลลัพธ์กลับ
out([
  'ok'         => true,
  'device_id'  => $device_id,
  'session_id' => $session_id,   // ถ้าไม่มี session active จะเป็น null
  'upsert'     => true
]);
