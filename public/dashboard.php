<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();

$counts = [];
foreach (['information'=>'رسته اطلاعاتی','operations'=>'رسته عملیاتی'] as $u=>$label) {
    $st=$pdo->prepare('SELECT COUNT(*) FROM personnel p JOIN category_types ct ON ct.id=p.category_type_id WHERE ct.category_key=?');
    $st->execute([$u]);
    $counts[$u]=(int)$st->fetchColumn();
}

$reportData = [];
$st=$pdo->query("SELECT ct.category_key,ct.category_name,t.team_name,COUNT(p.id) AS cnt
FROM category_types ct JOIN teams t ON t.category_type_id=ct.id AND t.is_active=1
LEFT JOIN personnel p ON p.category_type_id=ct.id AND p.team_id=t.id
WHERE ct.is_active=1 GROUP BY ct.id,ct.category_key,ct.category_name,t.id,t.team_name
ORDER BY ct.id,t.team_number");
foreach($st->fetchAll() as $rr){$reportData[$rr['category_key']]['label']=$rr['category_name'];$reportData[$rr['category_key']]['teams'][$rr['team_name']]=(int)$rr['cnt'];}


require __DIR__ . '/../app/partials/header.php';
?>
<section class="page-head dashboard-head">
  <div>
    <h1>داشبورد</h1>
  </div>
</section>

<div class="dashboard-stats">
  <a class="stat-card stat-card-accent" href="personnel.php?unit=information"><span>رسته اطلاعاتی</span><strong><?= $counts['information'] ?></strong><small>نفر</small></a>
  <a class="stat-card" href="personnel.php?unit=operations"><span>رسته عملیاتی</span><strong><?= $counts['operations'] ?></strong><small>نفر</small></a>
  <div class="stat-card"><span>سطح دسترسی</span><strong class="role-text"><?= e(match(user()['role']) {'super_admin'=>'مدیر کل','hr_manager'=>'منابع انسانی','info_commander'=>'فرمانده اطلاعاتی','ops_commander'=>'فرمانده عملیاتی'}) ?></strong></div>
</div>

<section class="panel report-panel">
  <div class="report-panel-head">
    <div>
      <span class="eyebrow">گزارش </span>
      <h2>گزارش عملکر عناصر</h2>
    </div>
    <button class="btn primary report-toggle" type="button" id="reportToggle" aria-expanded="false">نمایش گزارش عملکرد</button>
  </div>

  <div class="report-body" id="reportBody" hidden>
    <div class="report-toolbar">
      <div class="report-filters">
        <label>رسته
          <select id="reportUnit">
            <option value="all">هر دو رسته</option>
            <option value="information">رسته اطلاعاتی</option>
            <option value="operations">رسته عملیاتی</option>
          </select>
        </label>
        <label>تیم
          <select id="reportTeam">
            <option value="all">همه تیم‌ها</option>
            <option value="تیم ۱">تیم ۱</option>
            <option value="تیم ۲">تیم ۲</option>
            <option value="تیم ۳">تیم ۳</option>
            <option value="پیاده">پیاده</option>
            <option value="موتوری">موتوری</option>
            <option value="خودرویی">خودرویی</option>
          </select>
        </label>
      </div>
      <div class="chart-switcher" role="tablist" aria-label="نوع نمودار">
        <button class="chart-mode active" type="button" data-chart="bar">دیاگرام</button>
        <button class="chart-mode" type="button" data-chart="pie">پای چارت</button>
      </div>
    </div>

    <div class="report-summary" id="reportSummary"></div>
    <div class="chart-card">
      <div class="chart-wrap"><canvas id="personnelReportChart"></canvas></div>
      <div class="sample-note">این گزارش از آمار واقعی پرسنل ثبت‌شده در دیتابیس محاسبه می‌شود.</div>
    </div>
  </div>
</section>

<script>
const reportData = <?=json_encode($reportData,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;

const reportToggle = document.getElementById('reportToggle');
const reportBody = document.getElementById('reportBody');
const unitSelect = document.getElementById('reportUnit');
const teamSelect = document.getElementById('reportTeam');
const summary = document.getElementById('reportSummary');
const chartBox = document.querySelector('.chart-wrap');
const chartCard = document.querySelector('.chart-card');
let chartMode = 'bar';
const teamColors = ['#244b3b','#5c8b74','#94b8a6','#b9d0c4','#3f6d59','#7aa18d'];

function getRows(){
  const units = unitSelect.value === 'all' ? ['information','operations'] : [unitSelect.value];
  const rows=[];
  units.forEach(unit=>{
    Object.entries(reportData[unit].teams).forEach(([team,count])=>{
      if(teamSelect.value === 'all' || teamSelect.value === team) rows.push({unit,team,count:Number(count)});
    });
  });
  return rows;
}
function labelFor(r){
  const u = r.unit === 'information' ? 'اطلاعاتی' : 'عملیاتی';
  return unitSelect.value === 'all' ? `${u} • ${r.team}` : r.team;
}
function showLoader(){
  chartCard.classList.add('is-loading');
  chartBox.innerHTML = `
    <div class="chart-loader" aria-live="polite">
      <div class="chart-loader-orbit"><span></span><span></span><span></span></div>
      <strong>در حال آماده‌سازی گزارش...</strong>
      <small>نمودار در حال بارگذاری است</small>
    </div>`;
}
function hideLoader(){
  requestAnimationFrame(()=>requestAnimationFrame(()=>chartCard.classList.remove('is-loading')));
}
function renderBar(rows){
  const max = Math.max(...rows.map(r=>r.count), 1);
  chartBox.innerHTML = `<div class="fake-bar-chart">
    ${rows.map((r,i)=>`<div class="bar-item" style="--delay:${i*70}ms;--bar-color:${teamColors[i%teamColors.length]}">
      <div class="bar-label"><span>${labelFor(r)}</span><strong>${r.count}</strong></div>
      <div class="bar-track"><div class="bar-fill" style="--target-width:${(r.count/max)*100}%;" aria-label="${r.count}"></div></div>
    </div>`).join('')}
  </div>`;
}
function renderPie(rows){
  const total = rows.reduce((sum,r)=>sum+r.count,0);
  let cur=0;
  const slices=rows.map((r,i)=>{const s=(cur/total)*100; cur+=r.count; const e=(cur/total)*100; return `${teamColors[i%teamColors.length]} ${s}% ${e}%`;}).join(',');
  chartBox.innerHTML=`<div class="fake-pie-layout">
    <div class="fake-pie" style="background:conic-gradient(${slices})" role="img" aria-label="توزیع نمونه نیروها"></div>
    <div class="fake-pie-legend">${rows.map((r,i)=>`<div style="--delay:${200+i*70}ms"><span class="pie-dot" style="background:${teamColors[i%teamColors.length]}"></span><span>${labelFor(r)}</span><strong>${r.count}</strong></div>`).join('')}</div>
  </div>`;
}
function updateSummary(rows){
  const total=rows.reduce((s,r)=>s+r.count,0);
  summary.innerHTML=`<div><span>تعداد نمایش‌داده‌شده</span><strong>${total} نفر</strong></div>
  <div><span>رسته</span><strong>${unitSelect.value==='all'?'هر دو رسته':reportData[unitSelect.value].label}</strong></div>
  <div><span>تیم</span><strong>${teamSelect.value==='all'?'همه تیم‌ها':teamSelect.value}</strong></div>`;
}
function drawReport(){
  const rows=getRows();
  updateSummary(rows);
  showLoader();
  window.clearTimeout(drawReport._timer);
  drawReport._timer=window.setTimeout(()=>{
    if(!rows.length){
      chartBox.innerHTML='<div class="chart-empty"><strong>داده‌ای برای نمایش وجود ندارد.</strong><span>یک رسته یا تیم دیگر را انتخاب کنید.</span></div>';
    } else if(chartMode==='bar') {
      renderBar(rows);
    } else {
      renderPie(rows);
    }
    hideLoader();
  }, 850);
}
reportToggle.addEventListener('click',()=>{
  const show=reportBody.hasAttribute('hidden');
  reportBody.toggleAttribute('hidden',!show);
  reportToggle.setAttribute('aria-expanded',show?'true':'false');
  reportToggle.textContent=show?'بستن گزارش نیرو':'نمایش گزارش نیرو';
  if(show) drawReport();
});
unitSelect.addEventListener('change',()=>{ if(!reportBody.hasAttribute('hidden')) drawReport(); });
teamSelect.addEventListener('change',()=>{ if(!reportBody.hasAttribute('hidden')) drawReport(); });
document.querySelectorAll('.chart-mode').forEach(btn=>btn.addEventListener('click',()=>{
  document.querySelectorAll('.chart-mode').forEach(x=>x.classList.remove('active'));
  btn.classList.add('active');
  chartMode=btn.dataset.chart;
  if(!reportBody.hasAttribute('hidden')) drawReport();
}));
</script>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
