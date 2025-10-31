<?php
require __DIR__ . '/../connect.php';
header('Content-Type: application/json');

$api_key = $_GET['api_key'] ?? '';
$days = max(1, min(31, intval($_GET['days'] ?? 7)));

function map_api_key_to_device_id(mysqli $db, string $api_key): ?int {
  $stmt = $db->prepare("SELECT id FROM devices WHERE api_key=? LIMIT 1");
  $stmt->bind_param('s', $api_key);
  $stmt->execute();
  $stmt->bind_result($id);
  if ($stmt->fetch()) { $stmt->close(); return (int)$id; }
  $stmt->close();
  return null;
}
$device_id = map_api_key_to_device_id($mysqli, $api_key);
if (!$device_id) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'invalid api_key']); exit; }

$sql = "
SELECT
  DATE(created_at) AS day,
  COUNT(*) AS epochs,
  SUM(CASE WHEN stage='DEEP'  THEN 1 ELSE 0 END) AS c_deep,
  SUM(CASE WHEN stage='LIGHT' THEN 1 ELSE 0 END) AS c_light,
  SUM(CASE WHEN stage='REM'   THEN 1 ELSE 0 END) AS c_rem,
  SUM(CASE WHEN stage='AWAKE' THEN 1 ELSE 0 END) AS c_awake,
  AVG(avg_bpm) AS avg_bpm,
  AVG(hr_std)  AS avg_hr_std
FROM sleep_epochs
WHERE device_id = ?
  AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
GROUP BY day
ORDER BY day DESC
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('ii', $device_id, $days);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
  $epoch_sec = 30; // 1 epoch = 30s
  $rows[] = [
    'day'       => $r['day'],
    'epochs'    => (int)$r['epochs'],
    'minutes'   => (int)$r['epochs'] * $epoch_sec / 60,
    'stage_min' => [
      'DEEP'  => (int)$r['c_deep']  * $epoch_sec / 60,
      'LIGHT' => (int)$r['c_light'] * $epoch_sec / 60,
      'REM'   => (int)$r['c_rem']   * $epoch_sec / 60,
      'AWAKE' => (int)$r['c_awake'] * $epoch_sec / 60,
    ],
    'avg_bpm'   => round((float)$r['avg_bpm'],2),
    'avg_hr_std'=> round((float)$r['avg_hr_std'],2),
  ];
}
echo json_encode(['ok'=>true,'rows'=>$rows]);
