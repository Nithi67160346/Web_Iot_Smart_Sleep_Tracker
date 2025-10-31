<?php
// api/session_summary.php
// สรุปผลการนอนของ "Session ล่าสุดที่ปิดแล้ว" หรือจะระบุ session_id ก็ได้
// GET: api_key=..., session_id (optional)
// JSON: { ok, session: {...}, summary: {...}, stages: {...}, timeline: [...] }

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

function out($a,$code=200){ http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

require __DIR__ . '/../connect.php';
if (!isset($conn) || !$conn) out(['ok'=>false,'error'=>'db_connect_failed'],200);

$api_key = isset($_GET['api_key']) ? trim($_GET['api_key']) : '';
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($api_key==='') out(['ok'=>false,'error'=>'missing_api_key'],200);

// 1) map device
$stmt = mysqli_prepare($conn, "SELECT id FROM sleep_devices WHERE api_key=? LIMIT 1");
if (!$stmt) out(['ok'=>false,'error'=>'db_prepare_failed_map'],200);
mysqli_stmt_bind_param($stmt,"s",$api_key);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$dev = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);
if (!$dev) out(['ok'=>false,'error'=>'invalid_api_key'],200);
$device_id = (int)$dev['id'];

// 2) หา session เป้าหมาย (ล่าสุดที่จบแล้ว) ถ้าไม่ระบุ session_id
if ($session_id<=0) {
  $sql = "SELECT id, device_id, started_at, ended_at
          FROM sleep_sessions
          WHERE device_id=? AND ended_at IS NOT NULL
          ORDER BY ended_at DESC
          LIMIT 1";
  $stmt = mysqli_prepare($conn,$sql);
  if (!$stmt) out(['ok'=>false,'error'=>'db_prepare_failed_session'],200);
  mysqli_stmt_bind_param($stmt,"i",$device_id);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $ses = $res ? mysqli_fetch_assoc($res) : null;
  mysqli_stmt_close($stmt);
} else {
  $sql = "SELECT id, device_id, started_at, ended_at
          FROM sleep_sessions
          WHERE id=? AND device_id=? LIMIT 1";
  $stmt = mysqli_prepare($conn,$sql);
  if (!$stmt) out(['ok'=>false,'error'=>'db_prepare_failed_session'],200);
  mysqli_stmt_bind_param($stmt,"ii",$session_id,$device_id);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $ses = $res ? mysqli_fetch_assoc($res) : null;
  mysqli_stmt_close($stmt);
}

if (!$ses) out(['ok'=>false,'error'=>'no_finished_session'],200);
if ($ses['ended_at']===null) out(['ok'=>false,'error'=>'session_not_finished','session_id'=>$ses['id']],200);

$start = $ses['started_at'];
$end   = $ses['ended_at'];

// 3) ดึง epoch ทั้งหมดในช่วงเวลา (ยึด received_at เป็นหลัก; ถ้าไม่มี ให้ใช้ created_at)
// ตรวจว่ามี received_at ไหม
$has_received = false;
$chk = mysqli_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME='sleep_epochs'
                              AND COLUMN_NAME='received_at' LIMIT 1");
if ($chk && mysqli_fetch_assoc($chk)) $has_received = true;

$tscol = $has_received ? 'received_at' : 'created_at';

$sql = "SELECT epoch_idx, $tscol AS at_time, stage, avg_bpm, hr_std, avg_motion_g0, beats, signal_ok
        FROM sleep_epochs
        WHERE device_id=?
          AND $tscol BETWEEN ? AND ?
        ORDER BY $tscol ASC, epoch_idx ASC";
$stmt = mysqli_prepare($conn,$sql);
if (!$stmt) out(['ok'=>false,'error'=>'db_prepare_failed_epochs'],200);
mysqli_stmt_bind_param($stmt,"iss",$device_id,$start,$end);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$rows = [];
if ($res) while($r = mysqli_fetch_assoc($res)) $rows[] = $r;
mysqli_stmt_close($stmt);

if (!$rows) out(['ok'=>false,'error'=>'no_epochs_in_range','session'=>$ses],200);

// 4) คำนวณตัวชี้วัด
$EPOCH_SEC = 30; // 30 วินาทีต่อ epoch
$total_epochs = count($rows);
$stage_cnt = ['DEEP'=>0,'LIGHT'=>0,'REM'=>0,'AWAKE'=>0];
$sum_bpm = 0.0; $n_bpm = 0;
$sum_hrsd = 0.0; $n_hrsd = 0;

$timeline = []; // สำหรับ latency/WASO
foreach ($rows as $i=>$r) {
  $st = strtoupper($r['stage']);
  if (!isset($stage_cnt[$st])) $stage_cnt[$st]=0;
  $stage_cnt[$st]++;

  if ((int)$r['signal_ok']===1 && is_numeric($r['avg_bpm'])) { $sum_bpm += (float)$r['avg_bpm']; $n_bpm++; }
  if (is_numeric($r['hr_std'])) { $sum_hrsd += (float)$r['hr_std']; $n_hrsd++; }

  $timeline[] = [
    'i' => $i,
    'stage' => $st,
    'at' => $r['at_time']
  ];
}

$mins_total = $total_epochs * $EPOCH_SEC / 60.0;
$mins_deep  = $stage_cnt['DEEP']  * $EPOCH_SEC / 60.0;
$mins_light = $stage_cnt['LIGHT'] * $EPOCH_SEC / 60.0;
$mins_rem   = $stage_cnt['REM']   * $EPOCH_SEC / 60.0;
$mins_awake = $stage_cnt['AWAKE'] * $EPOCH_SEC / 60.0;

$TIB = $mins_total;                         // time in bed = ทั้งช่วง session
$TST = $mins_deep + $mins_light + $mins_rem;// total sleep time = ไม่รวม AWAKE
$eff = $TIB>0 ? round(100.0*$TST/$TIB,1) : 0.0;

$avg_bpm  = $n_bpm>0 ? round($sum_bpm/$n_bpm, 2) : null;
$avg_hrsd = $n_hrsd>0 ? round($sum_hrsd/$n_hrsd, 2) : null;

// Sleep latency (นาที): จากต้น session จนถึง epoch แรกที่ไม่ใช่ AWAKE
$latency_epochs = null;
foreach ($timeline as $t) {
  if ($t['stage']!=='AWAKE') { $latency_epochs = $t['i']; break; }
}
$latency_min = $latency_epochs!==null ? round(($latency_epochs*$EPOCH_SEC)/60.0, 1) : null;

// WASO (Wake After Sleep Onset): นาทีที่ตื่นหลังจากหลับครั้งแรก
$waso_epochs = 0;
if ($latency_epochs!==null) {
  for ($i=$latency_epochs; $i<count($timeline); $i++) {
    if ($timeline[$i]['stage']==='AWAKE') $waso_epochs++;
  }
}
$waso_min = round(($waso_epochs*$EPOCH_SEC)/60.0, 1);

// สัดส่วนแต่ละ stage
$pc = function($m,$t){ return $t>0 ? round(100.0*$m/$t,1) : 0.0; };
$stage_minutes = [
  'DEEP'  => round($mins_deep,1),
  'LIGHT' => round($mins_light,1),
  'REM'   => round($mins_rem,1),
  'AWAKE' => round($mins_awake,1),
];
$stage_percent = [
  'DEEP'  => $pc($mins_deep,$TST),
  'LIGHT' => $pc($mins_light,$TST),
  'REM'   => $pc($mins_rem,$TST),
  'AWAKE' => $pc($mins_awake,$TIB), // AWAKE คิดเทียบ TIB
];

// (Optional) sleep score ง่าย ๆ 0..100
// - Efficiency (50%), Deep% (25%), REM% (25%)
$sleep_score = round(
  (min(100,$eff) * 0.5) +
  (min(30, $stage_percent['DEEP']) / 30.0 * 25.0) +
  (min(25, $stage_percent['REM'])  / 25.0 * 25.0)
, 0);

// 5) ตอบกลับ
out([
  'ok'=>true,
  'session'=>[
    'id' => (int)$ses['id'],
    'started_at' => $ses['started_at'],
    'ended_at'   => $ses['ended_at'],
    'epoch_seconds' => $EPOCH_SEC
  ],
  'summary'=>[
    'time_in_bed_min' => round($TIB,1),
    'total_sleep_time_min' => round($TST,1),
    'sleep_efficiency_pct' => $eff,
    'sleep_latency_min' => $latency_min,
    'waso_min' => $waso_min,
    'avg_bpm' => $avg_bpm,
    'avg_hr_std' => $avg_hrsd,
    'sleep_score' => $sleep_score
  ],
  'stages'=>[
    'minutes' => $stage_minutes,
    'percent' => $stage_percent
  ],
  'counts'=>[
    'total_epochs' => $total_epochs,
    'by_stage' => $stage_cnt
  ],
  // สามารถใช้หน้าเว็บวาดกราฟช่วงได้ถ้าต้องการ
  // 'timeline'=>$timeline
],200);
