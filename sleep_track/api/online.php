<?php
// api/online.php — prefer server-side time (received_at), fallback to ts_ms

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

date_default_timezone_set('Asia/Bangkok');

function out($a,$code=200){ http_response_code($code); echo json_encode($a,JSON_UNESCAPED_UNICODE); exit; }

$steps = [];
try {
  // connect
  $steps[]='include_connect';
  require __DIR__ . '/../connect.php';
  if (!isset($conn) || !$conn) out(['ok'=>false,'error'=>'db_connect_failed','steps'=>$steps],200);

  // params
  $steps[]='read_params';
  $api_key   = isset($_GET['api_key']) ? trim($_GET['api_key']) : '';
  $threshold = isset($_GET['th']) ? max(10,(int)$_GET['th']) : 90;
  if ($api_key==='') out(['ok'=>false,'error'=>'missing_api_key','steps'=>$steps],200);

  // map device
  $steps[]='map_api_key';
  $stmt = mysqli_prepare($conn,"SELECT id FROM sleep_devices WHERE api_key=? LIMIT 1");
  if (!$stmt) out(['ok'=>false,'error'=>'db_prepare_failed_map','steps'=>$steps],200);
  mysqli_stmt_bind_param($stmt,"s",$api_key);
  if (!mysqli_stmt_execute($stmt)) { $e=mysqli_stmt_error($stmt); mysqli_stmt_close($stmt); out(['ok'=>false,'error'=>'db_execute_failed_map','detail'=>$e,'steps'=>$steps],200); }
  $res = mysqli_stmt_get_result($stmt);
  $dev = $res?mysqli_fetch_assoc($res):null;
  mysqli_stmt_close($stmt);
  if (!$dev) {
    out(['ok'=>true,'online'=>false,'reason'=>'invalid_api_key','last_at'=>null,'age_sec'=>null,'threshold_sec'=>$threshold,'now'=>date(DATE_ATOM),'steps'=>$steps],200);
  }
  $device_id = (int)$dev['id'];

  $now = time();

  // try A) received_at (server time) — ควรถูกต้องที่สุด
  $steps[]='query_received_at';
  $last_from_received = null;
  $stmtA = mysqli_prepare($conn, "SELECT MAX(received_at) AS last_at FROM sleep_epochs WHERE device_id=?");
  if ($stmtA) {
    mysqli_stmt_bind_param($stmtA,"i",$device_id);
    if (mysqli_stmt_execute($stmtA)) {
      $resA = mysqli_stmt_get_result($stmtA);
      $rowA = $resA?mysqli_fetch_assoc($resA):null;
      if ($rowA && $rowA['last_at']) $last_from_received = $rowA['last_at']; // 'YYYY-mm-dd HH:ii:ss'
    }
    mysqli_stmt_close($stmtA);
  }
  if ($last_from_received) {
    $last_ts = strtotime($last_from_received);
    $age = max(0, $now - $last_ts);
    out(['ok'=>true,'online'=>($age <= $threshold),'last_at'=>date(DATE_ATOM,$last_ts),'age_sec'=>$age,'threshold_sec'=>$threshold,'now'=>date(DATE_ATOM,$now),'steps'=>$steps],200);
  }

  // fallback B) ts_ms (ESP32 millis) — ใช้ได้แค่ "มีแถวใหม่ไหม" โดยอิงเวลาปัจจุบันของ DB
  // วิธีนี้จะคิดว่า "ออน" ถ้ามี record ที่ถูก insert เข้ามาในช่วง <= threshold วินาทีล่าสุด
  // (เพราะเราไม่มี server-side timestamp ก็ใช้เทคนิคเทียบ "มีแถวใหม่เร็ว ๆ นี้ไหม" แทน)
  $steps[]='fallback_ts_ms_only';
  // เอา id ล่าสุด แล้วดูว่ามีการ insert ภายใน threshold วิที่แล้วไหม—อาศัย count แถวช่วงสั้น ๆ
  // หมายเหตุ: ถ้า traffic บาง ๆ วิธีนี้ยังโอเคเพราะ insert แต่ละ epoch จะถี่ (ทุก 30s)
  $stmtB = mysqli_prepare($conn,
    "SELECT COUNT(*) AS c
     FROM sleep_epochs
     WHERE device_id=?
       AND id >= (
         SELECT IFNULL(MAX(id),0) FROM sleep_epochs WHERE device_id=?
       )"
  );
  // คำสั่งด้านบนจะคืน 1 เสมอถ้ามีแถวล่าสุดอยู่ ซึ่งไม่ได้บอก “เมื่อไหร่” — ดังนั้นเราจะใช้วิธีดีกว่า:
  // ดีกว่า: เพิ่มคอลัมน์ received_at ตามขั้นตอนด้านบน (แนะนำ)
  // ที่นี่เราจะทำ fallback สุด ๆ : ถ้ามีแถวใด ๆ อยู่เลย ก็ถือว่า unknown-เวลา => ให้รายงาน no_clock
  if ($stmtB) {
    mysqli_stmt_bind_param($stmtB,"ii",$device_id,$device_id);
    if (mysqli_stmt_execute($stmtB)) {
      $resB = mysqli_stmt_get_result($stmtB);
      $rowB = $resB?mysqli_fetch_assoc($resB):null;
      $has_any = $rowB && ((int)$rowB['c'] >= 1);
      if ($has_any) {
        out([
          'ok'=>true,'online'=>null, // unknown
          'reason'=>'no_server_timestamp_use_received_at_column',
          'last_at'=>null,'age_sec'=>null,'threshold_sec'=>$threshold,'now'=>date(DATE_ATOM,$now),
          'steps'=>$steps
        ],200);
      }
    }
    mysqli_stmt_close($stmtB);
  }

  // ไม่มีข้อมูลเลย
  out(['ok'=>true,'online'=>false,'reason'=>'no_data','last_at'=>null,'age_sec'=>null,'threshold_sec'=>$threshold,'now'=>date(DATE_ATOM,$now),'steps'=>$steps],200);

} catch (Throwable $e) {
  out(['ok'=>false,'error'=>'unexpected_exception','detail'=>$e->getMessage(),'steps'=>$steps],200);
}
