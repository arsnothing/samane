<?php
require __DIR__.'/../app/bootstrap.php'; require_login();
$base=$config['app']['base_url'];
$courseOptions=training_course_options();
$statusOptions=['trained'=>'عناصر آموزش دیده','untrained'=>'عناصر نیازمند آموزش'];
$unitOptions=['information'=>'عناصر اطلاعاتی','operations'=>'عناصر عملیاتی'];

/* ---- فیلترها (چندانتخابی) ---- */
$pickList=static function($value, array $allowed): array {
    if (!is_array($value)) $value = ($value===null || $value==='') ? [] : [$value];
    $out=[]; foreach($value as $v){ $v=(string)$v; if(isset($allowed[$v]) && !in_array($v,$out,true)) $out[]=$v; }
    return $out;
};
$courses = $pickList($_GET['course_key'] ?? [], $courseOptions);
$statuses = $pickList($_GET['status'] ?? [], $statusOptions);
$units    = $pickList($_GET['filter'] ?? [], $unitOptions);

$q=fa_to_en_digits(trim((string)($_GET['q']??'')));
$q=strtr($q,["\u{00A0}"=>' ',"\u{200C}"=>' ',"\u{200D}"=>' ',"\u{064A}"=>'ی',"\u{0649}"=>'ی',"\u{0643}"=>'ک']);
$q=preg_replace('/\s+/u',' ',$q);
$provinceId=(int)($_GET['province_id']??0);
$cityId=(int)($_GET['city_id']??0);
$categoryNumber=substr(preg_replace('/[^0-9]/','',strtr(trim((string)($_GET['category_number']??'')),['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'])),0,3);

// شماره دسته فقط وقتی معنا دارد که دقیقاً یک رسته انتخاب شده باشد.
$unitNumberEnabled = (count($units)===1);
if(!$unitNumberEnabled) $categoryNumber='';

// تا وقتی هیچ عنوان آموزشی انتخاب نشده، گزارشی نمایش داده نمی‌شود.
$hasCourse = (bool)$courses;

$provinces=$pdo->query("SELECT id,province_name FROM provinces WHERE is_active=1 AND province_code <> 'UNKNOWN' ORDER BY province_name")->fetchAll();
$cities=[]; if($provinceId){$stLoc=$pdo->prepare("SELECT c.id,c.city_name FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.province_id=? AND p.province_code='TEH' AND c.is_active=1 ORDER BY c.city_name");$stLoc->execute([$provinceId]);$cities=$stLoc->fetchAll();}

$reportRows=[]; $fullyTrained=0;
if($hasCourse){
    $allowed=allowed_units();
    $coursePh=implode(',',array_fill(0,count($courses),'?'));
    $unitPh=implode(',',array_fill(0,count($allowed),'?'));

    // پارامترهای شرط JOIN اول می‌آیند، بعد WHERE و در آخر HAVING.
    $params=$courses;
    $where=["ct.category_key IN ($unitPh)"]; $params=array_merge($params,$allowed);
    if($units){ $selPh=implode(',',array_fill(0,count($units),'?')); $where[]="ct.category_key IN ($selPh)"; $params=array_merge($params,$units); }
    if($provinceId){$where[]='p.province_id=?';$params[]=$provinceId;}
    if($cityId){$where[]='p.city_id=?';$params[]=$cityId;}
    if($categoryNumber!==''){$where[]='cn.unit_number=?';$params[]=$categoryNumber;}
    if($q!==''){$where[]='(p.full_name LIKE ? OR p.national_id LIKE ? OR p.mobile LIKE ?)';$like='%'.$q.'%';array_push($params,$like,$like,$like);}

    // «آموزش دیده» یعنی همه عناوین انتخاب‌شده را گذرانده باشد.
    $having='';
    $onlyTrained   = (count($statuses)===1 && $statuses[0]==='trained');
    $onlyUntrained = (count($statuses)===1 && $statuses[0]==='untrained');
    if($onlyTrained){ $having=' HAVING done_count = ?'; $params[]=count($courses); }
    elseif($onlyUntrained){ $having=' HAVING done_count < ?'; $params[]=count($courses); }

    $sql="SELECT p.id,p.full_name,p.national_id,ct.category_name AS unit_label,cn.unit_number,
                 COUNT(tr.id) AS done_count, GROUP_CONCAT(tr.course_key) AS done_courses
          FROM personnel p
          JOIN category_types ct ON ct.id=p.category_type_id
          LEFT JOIN category_numbers cn ON cn.id=p.category_number_id
          LEFT JOIN training_records tr ON tr.personnel_id=p.id AND tr.course_key IN ($coursePh)
          WHERE ".implode(' AND ',$where)."
          GROUP BY p.id,p.full_name,p.national_id,ct.category_name,cn.unit_number".$having."
          ORDER BY done_count DESC, p.full_name";
    $st=$pdo->prepare($sql);$st->execute($params);$reportRows=$st->fetchAll();
    foreach($reportRows as $row) if((int)$row['done_count']===count($courses)) $fullyTrained++;
}

/* ---- متن روی دکمه هر فیلتر ---- */
$courseLabel = !$courses ? 'انتخاب عنوان یا عناوین آموزشی'
    : (count($courses)===1 ? $courseOptions[$courses[0]] : fa_digits((string)count($courses)).' عنوان آموزشی');
$statusLabel = (count($statuses)!==1) ? 'انتخاب وضعیت آموزشی عناصر' : $statusOptions[$statuses[0]];
$unitLabel   = (count($units)!==1)   ? 'نوع رسته' : $unitOptions[$units[0]];

require __DIR__.'/../app/partials/header.php'; ?>
<section class="page-head"><div><h1>گزارش آموزش عناصر</h1></div></section>
<div class="training-tabs">
  <a class="training-tab" href="training.php">ثبت آموزش عناصر</a>
  <a class="training-tab active" href="training_report.php">گزارش آموزش عناصر</a>
  <a class="training-tab" href="training_applicants.php">متقاضیان برگزاری آموزش</a>
  <button class="training-tab-action" type="button" onclick="window.print()"<?= $hasCourse?'':' disabled' ?>>دریافت PDF</button>
</div>

<form class="training-filters" method="get" id="reportFilters" data-auto-filter>
  <div class="filter-bar training-course-row">
    <button type="button" class="filter-modal-trigger training-course-filter<?= $courses?' has-value':'' ?>" data-pv-open="courseModal">
      <span class="filter-modal-value"><?= e($courseLabel) ?></span><span class="filter-modal-arrow">⌄</span>
    </button>
    <button type="button" class="filter-modal-trigger training-status-filter<?= count($statuses)===1?' has-value':'' ?>" data-pv-open="statusModal">
      <span class="filter-modal-value"><?= e($statusLabel) ?></span><span class="filter-modal-arrow">⌄</span>
    </button>
  </div>
  <div class="filter-bar training-report-filters">
    <div class="smart-filter" data-placeholder="استان">
      <button type="button" class="smart-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span class="smart-filter-value">استان</span><span class="smart-filter-arrow">⌄</span></button>
      <div class="smart-filter-menu"><input type="search" class="smart-filter-search" placeholder="جست‌وجوی استان" autocomplete="off"><div class="smart-filter-options" role="listbox"></div></div>
      <select name="province_id" class="smart-filter-native" aria-label="استان"><option value="" hidden <?= !$provinceId?'selected':'' ?>>استان</option><?php foreach($provinces as $p):?><option value="<?=$p['id']?>" <?= $provinceId===(int)$p['id']?'selected':'' ?>><?=e($p['province_name'])?></option><?php endforeach;?></select>
    </div>
    <div class="smart-filter" data-placeholder="شهرستان">
      <button type="button" class="smart-filter-trigger" aria-haspopup="listbox" aria-expanded="false" <?= !$provinceId?'disabled':'' ?>><span class="smart-filter-value">شهرستان</span><span class="smart-filter-arrow">⌄</span></button>
      <div class="smart-filter-menu"><input type="search" class="smart-filter-search" placeholder="جست‌وجوی شهرستان" autocomplete="off" <?= !$provinceId?'disabled':'' ?>><div class="smart-filter-options" role="listbox"></div></div>
      <select name="city_id" class="smart-filter-native" aria-label="شهرستان" <?= !$provinceId?'disabled':'' ?>><option value="" hidden <?= !$cityId?'selected':'' ?>>شهرستان</option><?php foreach($cities as $c):?><option value="<?=$c['id']?>" <?= $cityId===(int)$c['id']?'selected':'' ?>><?=e($c['city_name'])?></option><?php endforeach;?></select>
    </div>
    <button type="button" class="filter-modal-trigger<?= count($units)===1?' has-value':'' ?>" data-pv-open="unitModal">
      <span class="filter-modal-value"><?= e($unitLabel) ?></span><span class="filter-modal-arrow">⌄</span>
    </button>
    <input type="text" name="category_number" id="categoryNumberInput" class="filter-control filter-number" inputmode="numeric" maxlength="3" autocomplete="off" placeholder="شماره دسته" value="<?= e($categoryNumber) ?>" aria-label="شماره دسته" <?= $unitNumberEnabled?'':'disabled' ?> title="<?= $unitNumberEnabled?'شماره دسته':'برای وارد کردن شماره دسته، فقط یک نوع رسته را انتخاب کنید' ?>">
    <input type="search" name="q" class="filter-control filter-search" placeholder="جستجو نام و نام خانوادگی، کد ملی یا موبایل" value="<?= e($_GET['q']??'') ?>" aria-label="جستجو">
    <button class="filter-btn" type="submit">جستجو</button>
  </div>
</form>

<?php $printTitle='گزارش وضعیت آموزشی'; require __DIR__.'/../app/partials/print_frame.php'; ?>
<div class="panel training-report-panel">
<?php if(!$hasCourse): ?>
  <div class="personnel-empty"><strong>گزارشی نمایش داده نشده است.</strong><span>برای دیدن گزارش، ابتدا عنوان یا عناوین آموزشی را از بالای صفحه انتخاب کنید.</span></div>
<?php elseif(!$reportRows): ?>
  <div class="personnel-empty"><strong>موردی پیدا نشد.</strong><span>فیلترها یا عبارت جست‌وجو را تغییر بدهید.</span></div>
<?php else: ?>
 <div class="report-summary-line">
   <span>عناوین: <strong><?=fa_digits((string)count($courses))?></strong></span>
   <span>عناصر: <strong><?=fa_digits((string)count($reportRows))?></strong></span>
   <span>آموزش‌دیده کامل: <strong><?=fa_digits((string)$fullyTrained)?></strong></span>
   <span>نیازمند آموزش: <strong><?=fa_digits((string)(count($reportRows)-$fullyTrained))?></strong></span>
 </div>
 <div class="training-report-wrap print-list"><table class="training-report course-report">
  <thead><tr><th>ردیف</th><th>نام</th><th>کد ملی</th><th>رسته</th><?php foreach($courses as $ck):?><th><?=e($courseOptions[$ck])?></th><?php endforeach;?></tr></thead>
  <tbody id="reportBody">
  <?php $reportRow=0; foreach($reportRows as $p): $reportRow++; $done=array_filter(explode(',',(string)$p['done_courses'])); ?>
   <tr>
     <td><?=fa_digits((string)$reportRow)?></td>
     <td><strong><?=e($p['full_name'])?></strong></td>
     <td><?=e(fa_digits((string)$p['national_id']))?></td>
     <td><?=e($p['unit_label'])?><?= $p['unit_number']!==null ? ' '.fa_digits((string)$p['unit_number']) : '' ?></td>
     <?php foreach($courses as $ck): $attended=in_array($ck,$done,true); ?>
       <td class="training-status-cell"><span class="status-chip <?= $attended?'completed':'not_attended' ?>"><?= $attended?'آموزش دیده':'نیاز به آموزش' ?></span></td>
     <?php endforeach; ?>
   </tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
</div>

<div class="pv-modal" id="courseModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-pv-close></div>
  <section class="pv-modal-card" role="dialog" aria-modal="true" aria-labelledby="courseModalTitle">
    <header class="pv-modal-head">
      <div><h2 id="courseModalTitle">انتخاب عنوان یا عناوین آموزشی</h2></div>
      <button type="button" class="pv-modal-close" data-pv-close data-filter-cancel aria-label="بستن">×</button>
    </header>
    <div class="pick-list" data-pick-group>
      <label class="pick-item pick-all"><input type="checkbox" data-pick-all><span>انتخاب همه آموزش‌ها</span></label>
      <div class="pick-scroll">
        <?php foreach($courseOptions as $k=>$v): ?>
        <label class="pick-item"><input type="checkbox" name="course_key[]" value="<?=e($k)?>" form="reportFilters" <?= in_array($k,$courses,true)?'checked':'' ?>><span><?=e($v)?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="pv-modal-actions">
      <button type="button" class="btn pv-btn-success" data-filter-apply>تایید</button>
      <button type="button" class="btn secondary" data-pv-close data-filter-cancel>انصراف</button>
    </div>
  </section>
</div>

<div class="pv-modal" id="statusModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-pv-close></div>
  <section class="pv-modal-card" role="dialog" aria-modal="true" aria-labelledby="statusModalTitle">
    <header class="pv-modal-head">
      <div><h2 id="statusModalTitle">انتخاب وضعیت آموزشی عناصر</h2></div>
      <button type="button" class="pv-modal-close" data-pv-close data-filter-cancel aria-label="بستن">×</button>
    </header>
    <div class="pick-list" data-pick-group>
      <div class="pick-scroll">
        <?php foreach($statusOptions as $k=>$v): ?>
        <label class="pick-item"><input type="checkbox" name="status[]" value="<?=e($k)?>" form="reportFilters" <?= in_array($k,$statuses,true)?'checked':'' ?>><span><?=e($v)?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="pv-modal-actions">
      <button type="button" class="btn pv-btn-success" data-filter-apply>تایید</button>
      <button type="button" class="btn secondary" data-pv-close data-filter-cancel>انصراف</button>
    </div>
  </section>
</div>

<div class="pv-modal" id="unitModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-pv-close></div>
  <section class="pv-modal-card" role="dialog" aria-modal="true" aria-labelledby="unitModalTitle">
    <header class="pv-modal-head">
      <div><h2 id="unitModalTitle">نوع رسته</h2></div>
      <button type="button" class="pv-modal-close" data-pv-close data-filter-cancel aria-label="بستن">×</button>
    </header>
    <div class="pick-list" data-pick-group>
      <div class="pick-scroll">
        <?php foreach($unitOptions as $k=>$v): ?>
        <label class="pick-item"><input type="checkbox" name="filter[]" value="<?=e($k)?>" form="reportFilters" data-unit-pick <?= in_array($k,$units,true)?'checked':'' ?>><span><?=e($v)?></span></label>
        <?php endforeach; ?>
      </div>
      <p class="pick-note">با انتخاب هر دو رسته، فیلتر شماره دسته غیرفعال می‌شود.</p>
    </div>
    <div class="pv-modal-actions">
      <button type="button" class="btn pv-btn-success" data-filter-apply>تایید</button>
      <button type="button" class="btn secondary" data-pv-close data-filter-cancel>انصراف</button>
    </div>
  </section>
</div>

<script src="<?= e(asset_url('assets/filter-bar.js')) ?>" defer></script>
<script src="<?= e(asset_url('assets/pv-modal.js')) ?>" defer></script>
<script>
(function(){
 var form=document.getElementById('reportFilters');
 var numberInput=document.getElementById('categoryNumberInput');

 // وضعیت اولیه تیک‌ها، تا با «انصراف» به همان حالت برگردد.
 var snapshot=new Map();
 document.querySelectorAll('.pv-modal input[type=checkbox]').forEach(function(cb){ snapshot.set(cb,cb.checked); });

 function syncPickAll(group){
  var all=group.querySelector('[data-pick-all]'); if(!all) return;
  var boxes=group.querySelectorAll('.pick-scroll input[type=checkbox]');
  var checked=0; boxes.forEach(function(b){ if(b.checked) checked++; });
  all.checked = checked===boxes.length && boxes.length>0;
  all.indeterminate = checked>0 && checked<boxes.length;
 }

 document.querySelectorAll('[data-pick-group]').forEach(function(group){
  var all=group.querySelector('[data-pick-all]');
  var boxes=group.querySelectorAll('.pick-scroll input[type=checkbox]');
  if(all) all.addEventListener('change',function(){ boxes.forEach(function(b){ b.checked=all.checked; }); all.indeterminate=false; });
  boxes.forEach(function(b){ b.addEventListener('change',function(){ syncPickAll(group); syncUnitNumber(); }); });
  syncPickAll(group);
 });

 // شماره دسته فقط با یک رسته فعال است.
 function syncUnitNumber(){
  if(!numberInput) return;
  var picked=document.querySelectorAll('[data-unit-pick]:checked').length;
  var enabled = picked===1;
  numberInput.disabled=!enabled;
  if(!enabled) numberInput.value='';
  numberInput.title = enabled ? '' : 'برای وارد کردن شماره دسته، فقط یک نوع رسته را انتخاب کنید';
 }
 syncUnitNumber();

 document.querySelectorAll('[data-filter-apply]').forEach(function(btn){
  btn.addEventListener('click',function(){ form.requestSubmit ? form.requestSubmit() : form.submit(); });
 });
 document.querySelectorAll('[data-filter-cancel]').forEach(function(btn){
  btn.addEventListener('click',function(){
   snapshot.forEach(function(v,cb){ cb.checked=v; });
   document.querySelectorAll('[data-pick-group]').forEach(syncPickAll);
   syncUnitNumber();
  });
 });

 if(numberInput){numberInput.addEventListener('input',function(){this.value=this.value.replace(/[۰-۹]/g,function(d){return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d));}).replace(/[^0-9]/g,'').slice(0,3).replace(/[0-9]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[+d];});});}
})();
</script>

<?php require __DIR__.'/../app/partials/footer.php'; ?>
