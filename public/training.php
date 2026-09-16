<?php
require __DIR__.'/../app/bootstrap.php'; require_login();
$courseOptions=training_course_options();

$courseKey=trim((string)($_GET['course_key']??''));
if($courseKey!=='' && !isset($courseOptions[$courseKey])) $courseKey='';

/* ---- نوار فیلتر مشترک با صفحه فهرست عناصر ---- */
$q=fa_to_en_digits(trim((string)($_GET['q']??'')));
$q=strtr($q,["\u{00A0}"=>' ',"\u{200C}"=>' ',"\u{200D}"=>' ',"\u{064A}"=>'ی',"\u{0649}"=>'ی',"\u{0643}"=>'ک']);
$q=preg_replace('/\s+/u',' ',$q);
$provinceId=(int)($_GET['province_id']??0);
$cityId=(int)($_GET['city_id']??0);
$filter=(string)($_GET['filter']??''); if(!in_array($filter,['information','operations'],true)) $filter='';
$categoryNumber=substr(preg_replace('/[^0-9]/','',strtr(trim((string)($_GET['category_number']??'')),['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'])),0,3);

$provinces=$pdo->query("SELECT id,province_name FROM provinces WHERE is_active=1 AND province_code <> 'UNKNOWN' ORDER BY province_name")->fetchAll();
$cities=[]; if($provinceId){$stLoc=$pdo->prepare("SELECT c.id,c.city_name FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.province_id=? AND p.province_code='TEH' AND c.is_active=1 ORDER BY c.city_name");$stLoc->execute([$provinceId]);$cities=$stLoc->fetchAll();}

$allowed=allowed_units();$ph=implode(',',array_fill(0,count($allowed),'?'));
$where=["ct.category_key IN ($ph)", active_personnel_sql('p')]; $params=$allowed;
if($filter!==''){$where[]='ct.category_key=?';$params[]=$filter;}
if($provinceId){$where[]='p.province_id=?';$params[]=$provinceId;}
if($cityId){$where[]='p.city_id=?';$params[]=$cityId;}
if($categoryNumber!==''){$where[]='cn.unit_number=?';$params[]=$categoryNumber;}
if($q!==''){$where[]='(p.full_name LIKE ? OR p.national_id LIKE ? OR p.mobile LIKE ?)';$like='%'.$q.'%';array_push($params,$like,$like,$like);}

$sql="SELECT p.id,p.full_name,p.national_id,ct.category_key AS unit,cn.unit_number,g.group_number AS group_no,t.team_number AS team_no
      FROM personnel p
      JOIN category_types ct ON ct.id=p.category_type_id
      LEFT JOIN category_numbers cn ON cn.id=p.category_number_id
      LEFT JOIN personnel_groups g ON g.id=p.group_id
      LEFT JOIN teams t ON t.id=p.team_id
      WHERE ".implode(' AND ',$where)." ORDER BY p.full_name";
$st=$pdo->prepare($sql);$st->execute($params);$people=$st->fetchAll();

/* دوره‌هایی که هر فرد قبلاً گذرانده، تا در لیست مشخص باشد */
$doneMap=[];
if($courseKey!==''){
    $done=$pdo->prepare('SELECT personnel_id FROM training_records WHERE course_key=?');
    $done->execute([$courseKey]);
    foreach($done->fetchAll() as $row) $doneMap[(int)$row['personnel_id']]=true;
}
require __DIR__.'/../app/partials/header.php'; ?>
<section class="page-head"><div><h1>گواهی آموزش</h1></div></section>
<div class="training-tabs"><a class="training-tab active" href="training.php">ثبت آموزش عناصر</a><a class="training-tab" href="training_report.php">گزارش آموزش عناصر</a><a class="training-tab" href="training_applicants.php">متقاضیان برگزاری آموزش</a></div>
<?php if(isset($_GET['saved'])):?><div class="alert success">دوره آموزشی برای عنصر انتخاب‌شده ثبت شد.</div><?php endif;?>
<?php if(isset($_GET['error'])):?><div class="alert danger"><?=['date'=>'تاریخ واردشده صحیح نیست.','course'=>'دوره آموزشی را انتخاب کنید.','person'=>'عنصر انتخاب‌شده معتبر نیست.','dismissed'=>'این عنصر برکنار شده است؛ ثبت آموزش برای او امکان‌پذیر نیست.','file'=>'اسناد باید PDF/JPG/PNG/WEBP و حداکثر ۸ مگابایت باشند.','schema'=>'ساختار دیتابیس نیاز به به‌روزرسانی دارد. فایل upgrade_v4.41.sql را اجرا کنید.'][$_GET['error']]??'ثبت انجام نشد.'?></div><?php endif;?>

<form class="training-filters" method="get" id="trainingFilters" data-auto-filter>
  <div class="filter-bar training-course-row">
  <div class="smart-filter training-course-filter" id="trainingCourseSmart" data-placeholder="دوره آموزشی">
    <button type="button" class="smart-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span class="smart-filter-value">دوره آموزشی</span><span class="smart-filter-arrow">⌄</span></button>
    <div class="smart-filter-menu"><input type="search" class="smart-filter-search" placeholder="جست‌وجوی دوره" autocomplete="off"><div class="smart-filter-options" role="listbox"></div></div>
    <select name="course_key" class="smart-filter-native" id="trainingCourseSelect" aria-label="دوره آموزشی"><option value="" hidden <?= $courseKey===''?'selected':'' ?>>انتخاب دوره آموزشی</option><?php foreach($courseOptions as $k=>$v):?><option value="<?=e($k)?>" <?= $courseKey===$k?'selected':'' ?>><?=e($v)?></option><?php endforeach;?></select>
  </div>
  </div>
  <div class="filter-bar training-people-row">
  <div class="smart-filter" id="trainingProvinceSmart" data-placeholder="استان">
    <button type="button" class="smart-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span class="smart-filter-value">استان</span><span class="smart-filter-arrow">⌄</span></button>
    <div class="smart-filter-menu"><input type="search" class="smart-filter-search" placeholder="جست‌وجوی استان" autocomplete="off"><div class="smart-filter-options" role="listbox"></div></div>
    <select name="province_id" class="smart-filter-native" aria-label="استان"><option value="" hidden <?= !$provinceId?'selected':'' ?>>استان</option><?php foreach($provinces as $p):?><option value="<?=$p['id']?>" <?= $provinceId===(int)$p['id']?'selected':'' ?>><?=e($p['province_name'])?></option><?php endforeach;?></select>
  </div>
  <div class="smart-filter" id="trainingCitySmart" data-placeholder="شهرستان">
    <button type="button" class="smart-filter-trigger" aria-haspopup="listbox" aria-expanded="false" <?= !$provinceId?'disabled':'' ?>><span class="smart-filter-value">شهرستان</span><span class="smart-filter-arrow">⌄</span></button>
    <div class="smart-filter-menu"><input type="search" class="smart-filter-search" placeholder="جست‌وجوی شهرستان" autocomplete="off" <?= !$provinceId?'disabled':'' ?>><div class="smart-filter-options" role="listbox"></div></div>
    <select name="city_id" class="smart-filter-native" aria-label="شهرستان" <?= !$provinceId?'disabled':'' ?>><option value="" hidden <?= !$cityId?'selected':'' ?>>شهرستان</option><?php foreach($cities as $c):?><option value="<?=$c['id']?>" <?= $cityId===(int)$c['id']?'selected':'' ?>><?=e($c['city_name'])?></option><?php endforeach;?></select>
  </div>
  <div class="smart-filter" id="trainingCategorySmart" data-placeholder="نوع رسته">
    <button type="button" class="smart-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span class="smart-filter-value">نوع رسته</span><span class="smart-filter-arrow">⌄</span></button>
    <div class="smart-filter-menu"><div class="smart-filter-options" role="listbox"></div></div>
    <select name="filter" class="smart-filter-native" aria-label="نوع رسته"><option value="" hidden <?= $filter===''?'selected':'' ?>>نوع رسته</option><option value="information" <?= $filter==='information'?'selected':'' ?>>عناصر اطلاعاتی</option><option value="operations" <?= $filter==='operations'?'selected':'' ?>>عناصر عملیاتی</option></select>
  </div>
  <input type="text" name="category_number" class="filter-control filter-number" inputmode="numeric" maxlength="3" autocomplete="off" placeholder="شماره دسته" value="<?= e($categoryNumber) ?>" aria-label="شماره دسته">
  <input type="search" name="q" class="filter-control filter-search" placeholder="جستجو نام و نام خانوادگی، کد ملی یا موبایل" value="<?= e($_GET['q']??'') ?>" aria-label="جستجو">
    <button class="filter-btn" type="submit">جستجو</button>
  </div>
</form>

<div class="panel training-list-panel">
  <div class="training-list-head">
    <div><h2>لیست عناصر</h2><p><?= fa_digits((string)count($people)) ?> عنصر<?= $courseKey!=='' ? ' · دوره: '.e($courseOptions[$courseKey]) : '' ?></p></div>
  </div>
  <?php if($people): ?>
  <div class="training-member-list">
    <?php foreach($people as $index=>$p): $done=isset($doneMap[(int)$p['id']]); ?>
    <div class="training-member-row">
      <span class="training-member-index"><?=fa_digits((string)($index+1))?></span>
      <div class="training-member-main">
        <a class="training-member-name" href="personnel_view.php?id=<?=(int)$p['id']?>&tab=training"><?=e($p['full_name'])?></a>
        <small><?=e(fa_digits((string)$p['national_id']))?> · <?=e(unit_label($p['unit']))?><?= $p['unit_number']!==null ? ' · شماره دسته '.fa_digits((string)$p['unit_number']) : '' ?></small>
      </div>
      <?php if($done): ?><span class="training-done-chip">ثبت‌شده</span><?php endif; ?>
      <?php if(can_manage_personnel()): ?>
      <button type="button" class="training-add-btn" data-pv-open="trainingModal" data-person-id="<?=(int)$p['id']?>" data-person-name="<?=e($p['full_name'])?>" aria-label="ثبت دوره برای <?=e($p['full_name'])?>">+</button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="personnel-empty"><strong>موردی پیدا نشد.</strong><span>فیلترها یا عبارت جست‌وجو را تغییر بدهید.</span></div>
  <?php endif; ?>
</div>

<?php if(can_manage_personnel()): ?>
<div class="pv-modal" id="trainingModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-pv-close></div>
  <section class="pv-modal-card" role="dialog" aria-modal="true" aria-labelledby="trainingModalTitle">
    <header class="pv-modal-head">
      <div><span class="pv-modal-eyebrow">ثبت دوره آموزشی</span><h2 id="trainingModalTitle" data-person-name-target>ثبت دوره</h2></div>
      <button type="button" class="pv-modal-close" data-pv-close aria-label="بستن">×</button>
    </header>
    <form method="post" action="training_store.php" enctype="multipart/form-data" class="pv-modal-form">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="personnel_id" value="" data-person-id-target>
      <input type="hidden" name="course_key" value="<?=e($courseKey)?>">
      <input type="hidden" name="return_query" value="<?=e(http_build_query(array_filter(['course_key'=>$courseKey,'q'=>$q,'province_id'=>$provinceId?:'','city_id'=>$cityId?:'','filter'=>$filter,'category_number'=>$categoryNumber],fn($v)=>$v!==''&&$v!==null)))?>">
      <label class="pv-field"><span>تاریخ</span><input name="training_date_jalali" class="jalali" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="۱۴۰۷/۰۷/۰۷"></label>
      <label class="pv-field pv-field-wide"><span>دوره آموزشی</span><input type="text" value="<?= $courseKey!=='' ? e($courseOptions[$courseKey]) : '' ?>" placeholder="ابتدا از بالای صفحه دوره را انتخاب کنید" readonly></label>
      <div class="document-upload-card pv-upload-card pv-field-wide">
        <div class="document-upload-icon">▤</div>
        <div><strong>اسناد دوره</strong><small>حداکثر ۵ فایل، هرکدام تا ۸MB</small></div>
        <label class="file-picker"><span data-file-label="انتخاب فایل">انتخاب فایل</span><input type="file" name="training_documents[]" multiple accept="application/pdf,image/jpeg,image/png,image/webp"></label>
      </div>
      <div class="pv-modal-actions">
        <button type="submit" class="btn pv-btn-success"<?= $courseKey===''?' disabled':'' ?>>ثبت</button>
        <button type="button" class="btn secondary" data-pv-close>انصراف</button>
      </div>
    </form>
  </section>
</div>
<?php endif; ?>

<script src="<?= e(asset_url('assets/filter-bar.js')) ?>" defer></script>
<script src="<?= e(asset_url('assets/pv-modal.js')) ?>" defer></script>
<script>
(function(){
 var categoryNumber=document.querySelector('[name="category_number"]');
 if(categoryNumber){categoryNumber.addEventListener('input',function(){this.value=this.value.replace(/[۰-۹]/g,function(d){return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d));}).replace(/[^0-9]/g,'').slice(0,3).replace(/[0-9]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[+d];});});}
})();
</script>
<?php require __DIR__.'/../app/partials/footer.php'; ?>
