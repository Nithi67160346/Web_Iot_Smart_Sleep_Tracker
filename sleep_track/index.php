<?php
// index.php
$api_key = $_GET['api_key'] ?? '2a92418414318ba8ae818c3dc925fa99';
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>SleepTracker Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    /* -------- Theme variables: ปรับสีได้ที่นี่ทีเดียวทั้งหน้า -------- */
    :root{
      --bg: #0b1220;
      --card: #121a2b;
      --card-border: #1a2742;
      --text: #eaf2ff;          /* สีตัวอักษรหลัก */
      --text-dim: #c9dbff;      /* สีข้อความรอง */
      --text-strong: #ffffff;   /* สีเน้น/หัวข้อ/ตัวหนา */
      --grid: #1a2742;          /* สีเส้นกริด/เส้นตาราง */
    }

    html, body{ background:var(--bg); color:var(--text); }
    .card{ background:var(--card); border:1px solid var(--card-border); color:var(--text); }

    /* Stage tag */
    .stage-pill{ padding:3px 10px; border-radius:999px; font-weight:700; }
    .stage-AWAKE{ background:#ffb14a; color:#1b1207; }
    .stage-LIGHT{ background:#6fb7ff; color:#071423; }
    .stage-DEEP{  background:#8f6bff; color:#100a27; }
    .stage-REM{   background:#b7eb6e; color:#0f1a08; }

    /* — ตาราง: โทนอ่านง่าย — */
    table thead th{ color: var(--text-strong); }
    table tbody td{ color: var(--text); }
    table{ border-color: var(--grid) !important; }
    .table>:not(caption)>*>*{ border-bottom-color: var(--grid); }

    /* ข้อความรอง */
    .subtle, .text-secondary, small, .small{ color: var(--text-dim) !important; }

    /* หัวข้อ/ตัวหนาให้สว่าง */
    h1,h2,h3,h4,h5,h6,b,strong{ color: var(--text-strong); }

    /* สถานะออนไลน์ */
    .badge-pill{ border-radius:999px; padding:.35rem .6rem; font-weight:700; }
    .badge-online{ background:#22c55e; color:#06130b; }
    .badge-offline{ background:#ef4444; color:#190808; }
    .badge-unknown{ background:#f59e0b; color:#1b1207; }

    .kv{ font-size:.9rem; }
    .kv b{ color: var(--text-strong); }
    .mono{ font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace; }

    /* ปุ่มเพจเนชัน */
    .pager .btn{ min-width:90px; }
    .pager .btn[disabled]{ opacity:.5; cursor:not-allowed; }

    /* Night summary ให้สว่างอ่านง่าย */
    #nightSummary{ color: var(--text); }
    #nightSummary b{ color: var(--text-strong); }
    #nightSummary .badge.bg-primary{ color:#fff; }
  </style>
</head>
<body>
<div class="container py-4">
  <h3 class="mb-4">SleepTracker Dashboard</h3>

  <div class="row g-3">
    <!-- Device / Controls -->
    <div class="col-md-4">
      <div class="card p-3">
        <h5>Device</h5>
        <div class="mb-2 small">API Key</div>
        <input id="apiKey" class="form-control form-control-sm" value="<?=htmlspecialchars($api_key)?>">

        <!-- ONLINE STATUS -->
        <div class="mt-3">
          <div class="d-flex justify-content-between align-items-center">
            <div class="small mb-1">สถานะการเชื่อมต่อ</div>
            <div class="d-inline-flex align-items-center gap-2">
              <label for="thSec" class="small subtle mb-0">Threshold</label>
              <input id="thSec" type="number" min="10" step="5" value="90" class="form-control form-control-sm" style="width:80px">
            </div>
          </div>

          <div id="onlineBox" class="d-flex align-items-center gap-2">
            <span id="onlineBadge" class="badge-pill badge-unknown">กำลังตรวจสอบ...</span>
            <span id="onlineMeta" class="small subtle"></span>
          </div>

          <div id="onlineDetails" class="mt-2 kv">
            <div><b>last_at:</b> <span id="odLast">-</span></div>
            <div><b>age_sec:</b> <span id="odAge">-</span></div>
            <div><b>threshold_sec:</b> <span id="odTh">90</span></div>
            <div class="mt-1"><b>last epoch:</b> <span id="odEpoch">-</span></div>
            <div class="mt-2 small subtle">ถ้าโหลดสถานะไม่ได้ ให้ลองเปิด <span class="mono">api/online.php</span> ตรง ๆ เพื่อตรวจ</div>
          </div>
        </div>

        <div class="d-grid gap-2 mt-3">
          <button id="btnReload" class="btn btn-primary btn-sm">Reload</button>
          <button id="btnStart" class="btn btn-success btn-sm">เริ่มนับเวลาเข้านอน</button>
          <button id="btnEnd" class="btn btn-outline-warning btn-sm">จบนอน</button>
        </div>
        <div id="sessionState" class="small mt-2 text-info"></div>
        <hr>
        <div id="summary" class="small"></div>
      </div>
    </div>

    <!-- Charts -->
    <div class="col-md-8">
      <div class="card p-3">
        <h5>Hypnogram (Stage) & Avg BPM</h5>
        <div class="subtle">แสดงจากข้อมูลล่าสุดที่ดึงได้</div>
        <canvas id="hypnogram" height="120"></canvas>
        <canvas id="hrChart" class="mt-3" height="140"></canvas>
      </div>
    </div>
  </div>

  <!-- Daily / Weekly summaries -->
  <div class="row g-3 mt-3">
    <div class="col-md-6">
      <div class="card p-3">
        <h5>สรุปการนอน (รายวัน)</h5>
        <div class="small text-secondary mb-2">ย้อนหลัง 7 วัน</div>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>วัน</th><th>รวม (นาที)</th><th>DEEP</th><th>LIGHT</th><th>REM</th><th>AWAKE</th><th>Avg BPM</th>
              </tr>
            </thead>
            <tbody id="dailyRows"></tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card p-3">
        <h5>สรุปการนอน (รายสัปดาห์)</h5>
        <div class="small text-secondary mb-2">ย้อนหลัง 4 สัปดาห์</div>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>สัปดาห์</th><th>รวม (นาที)</th><th>DEEP</th><th>LIGHT</th><th>REM</th><th>AWAKE</th><th>Avg BPM</th>
              </tr>
            </thead>
            <tbody id="weeklyRows"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- สรุปคืนล่าสุด -->
  <div class="card p-3 mt-3">
    <h5>สรุปคืนล่าสุด</h5>
    <div id="nightSummary" class="small">
      <span class="text-secondary">ยังไม่มีข้อมูล (กด “จบนอน” เพื่อคำนวณ)</span>
    </div>
  </div>

  <!-- Latest epochs (pagination) -->
  <div class="card p-3 mt-3">
    <div class="d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Latest Epochs</h5>
      <div class="d-flex align-items-center gap-2">
        <label for="pageSize" class="small subtle mb-0">ต่อหน้า</label>
        <select id="pageSize" class="form-select form-select-sm" style="width:90px">
          <option value="25">25</option>
          <option value="50" selected>50</option>
          <option value="100">100</option>
        </select>
      </div>
    </div>

    <div class="table-responsive mt-2">
      <table class="table table-sm">
        <thead>
          <tr>
            <th>#</th><th>epoch_idx</th><th>ts_ms</th><th>stage</th>
            <th>avg_bpm</th><th>hr_std</th><th>motion</th><th>beats</th><th>sig</th>
          </tr>
        </thead>
        <tbody id="rows"></tbody>
      </table>
    </div>

    <!-- เปลี่ยนเพจเนชัน: เพิ่ม First / Last -->
    <div class="d-flex justify-content-between align-items-center mt-2 pager">
      <div class="d-flex gap-2">
        <button id="pageFirst" class="btn btn-outline-light btn-sm">« First</button>
        <button id="pagePrev"  class="btn btn-outline-light btn-sm">‹ Prev</button>
      </div>
      <div class="small">
        Page <span id="pageNum">1</span> / <span id="pageCount">1</span>
      </div>
      <div class="d-flex gap-2">
        <button id="pageNext"  class="btn btn-outline-light btn-sm">Next ›</button>
        <button id="pageLast"  class="btn btn-outline-light btn-sm">Last »</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>
<script>
const API = (key,th)=>({
  epochs:         `api/epochs.php?api_key=${encodeURIComponent(key)}&limit=300`,
  summary:        `api/summary.php?api_key=${encodeURIComponent(key)}`,
  sessionStart:   `api/session_start.php?api_key=${encodeURIComponent(key)}`,
  sessionEnd:     `api/session_end.php?api_key=${encodeURIComponent(key)}`,
  summaryDaily:   `api/summary_daily.php?api_key=${encodeURIComponent(key)}&days=7`,
  summaryWeekly:  `api/summary_weekly.php?api_key=${encodeURIComponent(key)}&weeks=4`,
  online:         `api/online.php?api_key=${encodeURIComponent(key)}&th=${encodeURIComponent(th)}`
});

const stageToLevel = { DEEP:0, LIGHT:1, REM:2, AWAKE:3 };
const levelToStage = ['DEEP','LIGHT','REM','AWAKE'];

let hypChart, hrChart;
function fmt(n, d=2){ const x=Number(n||0); return isFinite(x)?x.toFixed(d):'0.00'; }
function getKeyTh(){ return { key: document.getElementById('apiKey').value.trim(), th: Math.max(10, parseInt(document.getElementById('thSec').value||'90',10)) }; }

/* สีกราฟให้อ่านจาก CSS variables เพื่อเข้าธีม */
function themeColor(name){
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || '#cfe0ff';
}
const CHART_TEXT = themeColor('--text-dim');
const CHART_GRID = themeColor('--grid');

/* ---------- ONLINE STATUS ---------- */
async function refreshOnline(latestEpochRow=null) {
  const {key, th} = getKeyTh();
  const badge = document.getElementById('onlineBadge');
  const meta  = document.getElementById('onlineMeta');
  const odLast = document.getElementById('odLast');
  const odAge  = document.getElementById('odAge');
  const odTh   = document.getElementById('odTh');
  const odEpoch= document.getElementById('odEpoch');

  badge.className = 'badge-pill badge-unknown';
  badge.textContent = 'กำลังตรวจสอบ...';
  meta.textContent = '';
  odLast.textContent = '-'; odAge.textContent='-'; odTh.textContent = String(th);
  odEpoch.textContent = latestEpochRow ? renderEpochInfo(latestEpochRow) : '-';

  try {
    const r = await fetch(API(key,th).online, { cache: 'no-store' });
    if (!r.ok) {
      const txt = await r.text().catch(()=> '');
      badge.className = 'badge-pill badge-unknown';
      badge.textContent = 'ไม่ทราบสถานะ';
      meta.textContent = `HTTP ${r.status} ${r.statusText}${txt ? ' | ' + txt.slice(0,120) : ''}`;
      return;
    }

    let resp;
    const clone = r.clone();
    try { resp = await r.json(); }
    catch { const txt = await clone.text().catch(()=> ''); badge.className='badge-pill badge-unknown'; badge.textContent='ไม่ทราบสถานะ'; meta.textContent=`ไม่ใช่ JSON | ${txt.slice(0,120)}`; return; }

    if (!resp || !resp.ok) {
      badge.className = 'badge-pill badge-unknown';
      badge.textContent = 'ไม่ทราบสถานะ';
      meta.textContent = resp && resp.error ? String(resp.error) : '';
      return;
    }

    if (resp.online) {
      badge.className = 'badge-pill badge-online';
      badge.textContent = 'ออนไลน์';
      meta.textContent = resp.last_at ? `ล่าสุด ${resp.age_sec}s ที่แล้ว` : '';
    } else {
      badge.className = 'badge-pill badge-offline';
      badge.textContent = 'ออฟไลน์';
      if (resp.reason === 'no_data') meta.textContent = 'ยังไม่เคยส่งข้อมูล';
      else if (resp.reason === 'invalid_api_key') meta.textContent = 'API key ไม่ถูกต้อง';
      else if (resp.reason === 'bad_datetime_format') meta.textContent = 'รูปแบบเวลาในฐานข้อมูลไม่ถูกต้อง';
      else meta.textContent = resp.last_at ? `ล่าสุด ${resp.age_sec}s ที่แล้ว` : '';
    }

    odLast.textContent = resp && resp.last_at ? resp.last_at : '-';
    odAge.textContent  = resp && (resp.age_sec!==null && resp.age_sec!==undefined) ? resp.age_sec : '-';
    odTh.textContent   = th;
  } catch (e) {
    badge.className = 'badge-pill badge-unknown';
    badge.textContent = 'ไม่ทราบสถานะ';
    meta.textContent = 'fetch ล้มเหลว (network)';
  }
}
function renderEpochInfo(r){
  return `#${r.epoch_idx} | ${r.stage} | BPM:${fmt(r.avg_bpm,2)} SD:${fmt(r.hr_std,2)} beats:${r.beats}`;
}

/* ---------- STATE สำหรับ Latest Epochs (pagination) ---------- */
let latestRows = [];
let pageSize = 50;
let currentPage = 1;
let totalPages = 1; // ใช้กับปุ่ม Last

function setPageSizeFromSelect(){
  const sel = document.getElementById('pageSize');
  pageSize = Math.max(1, parseInt(sel.value||'50',10));
}

function renderLatestPage(page){
  if (!Array.isArray(latestRows)) latestRows = [];
  setPageSizeFromSelect();

  const pageCount = Math.max(1, Math.ceil(latestRows.length / pageSize));
  totalPages = pageCount;
  currentPage = Math.min(Math.max(1, page), pageCount);

  const tbody = document.getElementById('rows');
  tbody.innerHTML = '';

  const start = (currentPage-1)*pageSize;
  const end   = Math.min(start + pageSize, latestRows.length);
  for (let i=start;i<end;i++){
    const r = latestRows[i];
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${i+1}</td>
      <td>${r.epoch_idx}</td>
      <td>${r.ts_ms}</td>
      <td><span class="stage-pill stage-${r.stage}">${r.stage}</span></td>
      <td>${fmt(r.avg_bpm,2)}</td>
      <td>${fmt(r.hr_std,2)}</td>
      <td>${Number(r.avg_motion_g0||0).toFixed(4)}</td>
      <td>${r.beats}</td>
      <td>${r.signal_ok}</td>`;
    tbody.appendChild(tr);
  }

  document.getElementById('pageNum').textContent = String(currentPage);
  document.getElementById('pageCount').textContent = String(pageCount);

  const atFirst = currentPage <= 1;
  const atLast  = currentPage >= pageCount;

  document.getElementById('pageFirst').disabled = atFirst;
  document.getElementById('pagePrev').disabled  = atFirst;
  document.getElementById('pageNext').disabled  = atLast;
  document.getElementById('pageLast').disabled  = atLast;
}

/* ---------- LOAD SUMMARY / EPOCHS ---------- */
async function loadAll() {
  const {key, th} = getKeyTh();
  const ep = await fetch(API(key,th).epochs, { cache: 'no-store' }).then(r=>r.json()).catch(_=>({ok:false}));
  const sm = await fetch(API(key,th).summary, { cache: 'no-store' }).then(r=>r.json()).catch(_=>({ok:false}));

  if (sm.ok) {
    const sc = sm.stage_counts || {};
    const theSes = sm.session || {};
    const activeTxt = theSes.active ? `Session กำลังทำงาน (#${theSes.id||'-'}) ตั้งแต่ ${theSes.started_at||'-'}` : 'ไม่มี Session ที่กำลังทำงาน';
    document.getElementById('sessionState').textContent = activeTxt;

    document.getElementById('summary').innerHTML =
      `<div>Avg BPM: <b>${fmt(sm.avg_bpm,2)}</b></div>
       <div>HR Std: <b>${fmt(sm.avg_hr_std,2)}</b></div>
       <div class="mt-2">Count — DEEP: ${sc.DEEP||0}, LIGHT: ${sc.LIGHT||0}, REM: ${sc.REM||0}, AWAKE: ${sc.AWAKE||0}</div>
       <div class="text-secondary mt-1">Sample: ${sm.sample}</div>`;
  } else {
    document.getElementById('summary').innerHTML = `<span class="text-warning">No summary</span>`;
    document.getElementById('sessionState').textContent = '';
  }

  // อัปเดตรายการ + วาดกราฟ
  if (!ep.ok || !Array.isArray(ep.rows)) { latestRows = []; renderLatestPage(1); refreshOnline(null); return; }
  latestRows = ep.rows.slice(); // เก็บทั้งหมดไว้สำหรับเพจเนชัน
  renderLatestPage(1);

  const labels = ep.rows.map((r,i)=>i);
  const levels = ep.rows.map(r=> stageToLevel[r.stage] ?? 1 );
  const hrvals = ep.rows.map(r=> Number(r.avg_bpm||0) );

  if (hypChart) hypChart.destroy();
  hypnogram.style.color = CHART_TEXT;
  hypChart = new Chart(document.getElementById('hypnogram'), {
    type: 'line',
    data: { labels, datasets: [{ data: levels, stepped: true, borderWidth: 2, pointRadius: 0, label:'Stage' }] },
    options: {
      plugins:{ legend:{ display:false } },
      scales:{
        x:{ grid:{ color: CHART_GRID }, ticks:{ color: CHART_TEXT } },
        y:{ min:0, max:3, grid:{ color: CHART_GRID }, ticks:{ color: CHART_TEXT, callback:v=>levelToStage[v]??'' } }
      }
    }
  });

  if (hrChart) hrChart.destroy();
  hrChart = new Chart(document.getElementById('hrChart'), {
    type: 'line',
    data: { labels, datasets: [{ data: hrvals, borderWidth: 2, pointRadius: 0, label:'Avg BPM' }] },
    options: {
      scales:{
        x:{ grid:{ color: CHART_GRID }, ticks:{ color: CHART_TEXT } },
        y:{ grid:{ color: CHART_GRID }, ticks:{ color: CHART_TEXT } }
      },
      plugins:{ legend:{ labels:{ color: CHART_TEXT } } }
    }
  });

  const lastRow = ep.rows.length ? ep.rows[ep.rows.length-1] : null;
  refreshOnline(lastRow);
}

/* ---------- Daily / Weekly ---------- */
async function loadDailyWeekly() {
  const {key, th} = getKeyTh();
  const d = await fetch(API(key,th).summaryDaily, { cache: 'no-store' }).then(r=>r.json()).catch(()=>({ok:false}));
  const w = await fetch(API(key,th).summaryWeekly, { cache: 'no-store' }).then(r=>r.json()).catch(()=>({ok:false}));

  const dT = document.getElementById('dailyRows'); dT.innerHTML = '';
  if (d.ok && Array.isArray(d.rows)) {
    d.rows.forEach(r=>{
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${r.day}</td>
        <td>${r.minutes}</td>
        <td>${r.stage_min.DEEP}</td>
        <td>${r.stage_min.LIGHT}</td>
        <td>${r.stage_min.REM}</td>
        <td>${r.stage_min.AWAKE}</td>
        <td>${fmt(r.avg_bpm,2)}</td>`;
      dT.appendChild(tr);
    });
  }

  const wT = document.getElementById('weeklyRows'); wT.innerHTML = '';
  if (w.ok && Array.isArray(w.rows)) {
    w.rows.forEach(r=>{
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${r.week_range[0]} → ${r.week_range[1]}</td>
        <td>${r.minutes}</td>
        <td>${r.stage_min.DEEP}</td>
        <td>${r.stage_min.LIGHT}</td>
        <td>${r.stage_min.REM}</td>
        <td>${r.stage_min.AWAKE}</td>
        <td>${fmt(r.avg_bpm,2)}</td>`;
      wT.appendChild(tr);
    });
  }
}

/* ---------- สรุปคืนล่าสุด ---------- */
async function loadNightSummary(sessionId=null){
  const key = document.getElementById('apiKey').value.trim();
  const url = sessionId
    ? `api/session_summary.php?api_key=${encodeURIComponent(key)}&session_id=${encodeURIComponent(sessionId)}`
    : `api/session_summary.php?api_key=${encodeURIComponent(key)}`;

  const box = document.getElementById('nightSummary');
  box.innerHTML = `<span class="text-secondary">กำลังคำนวณ...</span>`;

  try{
    const resp = await fetch(url, { cache:'no-store' }).then(r=>r.json());
    if(!resp || !resp.ok){
      box.innerHTML = `<span class="text-warning">ยังไม่มีสรุป (เหตุผล: ${resp && resp.error ? resp.error : 'unknown'})</span>`;
      return;
    }
    const s = resp.summary, st = resp.stages;
    box.innerHTML = `
      <div>ช่วงเวลา: <b>${resp.session.started_at}</b> → <b>${resp.session.ended_at}</b></div>
      <div class="mt-2">Time in Bed: <b>${s.time_in_bed_min} นาที</b> | TST: <b>${s.total_sleep_time_min} นาที</b> | Efficiency: <b>${s.sleep_efficiency_pct}%</b></div>
      <div>Latency: <b>${s.sleep_latency_min ?? '-' }</b> นาที | WASO: <b>${s.waso_min}</b> นาที</div>
      <div>Avg BPM: <b>${s.avg_bpm ?? '-'}</b> | Avg HR SD: <b>${s.avg_hr_std ?? '-'}</b></div>
      <div class="mt-2">Stages (นาที): DEEP <b>${st.minutes.DEEP}</b>, LIGHT <b>${st.minutes.LIGHT}</b>, REM <b>${st.minutes.REM}</b>, AWAKE <b>${st.minutes.AWAKE}</b></div>
      <div>Stages (% ของ TST): DEEP <b>${st.percent.DEEP}%</b>, LIGHT <b>${st.percent.LIGHT}%</b>, REM <b>${st.percent.REM}%</b></div>
      <div class="mt-2">Sleep Score: <span class="badge bg-primary">${s.sleep_score}</span></div>`;
  }catch(e){
    box.innerHTML = `<span class="text-danger">โหลดสรุปไม่สำเร็จ</span>`;
  }
}

/* ---------- Session controls ---------- */
async function startSession() {
  const {key, th} = getKeyTh();
  const resp = await fetch(API(key,th).sessionStart).then(r=>r.json()).catch(()=>null);
  document.getElementById('sessionState').textContent =
    resp && resp.ok ? `เริ่มนอนแล้ว (Session #${resp.session_id})` : 'เริ่มนอนไม่สำเร็จ';
  document.getElementById('nightSummary').innerHTML = `<span class="text-secondary">กำลังนอนอยู่... (จบแล้วจะคำนวณให้)</span>`;
  loadAll(); loadDailyWeekly();
}
async function endSession() {
  const {key, th} = getKeyTh();
  const resp = await fetch(API(key,th).sessionEnd).then(r=>r.json()).catch(()=>null);
  document.getElementById('sessionState').textContent =
    resp && resp.ok ? 'จบนอนแล้ว' : 'จบนอนไม่สำเร็จ';
  if (resp && resp.ok && resp.session_id) await loadNightSummary(resp.session_id);
  else await loadNightSummary(null);
  loadAll(); loadDailyWeekly();
}

/* ---------- Events ---------- */
document.getElementById('btnReload').addEventListener('click', ()=>{ loadAll(); loadDailyWeekly(); });
document.getElementById('btnStart').addEventListener('click', startSession);
document.getElementById('btnEnd').addEventListener('click', endSession);
document.getElementById('thSec').addEventListener('change', ()=>{ refreshOnline(); });

document.getElementById('pageFirst').addEventListener('click', ()=> renderLatestPage(1));
document.getElementById('pagePrev').addEventListener('click',  ()=> renderLatestPage(currentPage-1));
document.getElementById('pageNext').addEventListener('click',  ()=> renderLatestPage(currentPage+1));
document.getElementById('pageLast').addEventListener('click',  ()=> renderLatestPage(totalPages));
document.getElementById('pageSize').addEventListener('change', ()=> renderLatestPage(1));

/* ---------- Initial & auto refresh ---------- */
loadAll(); loadDailyWeekly(); refreshOnline(); loadNightSummary(null);
setInterval(()=>{ loadAll(); loadDailyWeekly(); }, 30000);
setInterval(()=>{ refreshOnline(); }, 8000);
</script>
</body>
</html>
