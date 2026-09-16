<?php
require __DIR__.'/../app/bootstrap.php'; require_login();
$courseOptions=training_course_options();
$error=''; $success='';

/* ساختار دیتابیس: جدول متقاضیان و ستون شماره قائد (فایل database/upgrade_v6.1.sql) */
$hasApplicantsTable=false;
try { $hasApplicantsTable=(bool)$pdo->query("SHOW TABLES LIKE 'training_applicants'")->fetch(); } catch (Throwable $e) { $hasApplicantsTable=false; }
$hasCommanderColumn=false;
try { $hasCommanderColumn=(bool)$pdo->query("SHOW COLUMNS FROM personnel LIKE 'commander_number'")->fetch(); } catch (Throwable $e) { $hasCommanderColumn=false; }

/* ---- ثبت/حذف نشان «نیاز به بازآموزی» برای یک عنصر در دوره انتخابی ---- */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='toggle_retraining'){
 verify_csrf();
 if(!can_manage_personnel()){http_response_code(403);exit('دسترسی غیرمجاز');}
 if(!$hasApplicantsTable){ $error='ساختار دیتابیس نیاز به به‌روزرسانی دارد. فایل database/upgrade_v6.1.sql را اجرا کنید.'; }
 else{
  $course=(string)($_POST['course_key']??'');
  $personId=(int)($_POST['personnel_id']??0);
  if(!isset($courseOptions[$course]) || $personId<1) $error='دوره آموزشی یا عنصر انتخاب‌شده معتبر نیست.';
  else{
   try{
    $chk=$pdo->prepare('SELECT id FROM training_applicants WHERE course_key=? AND personnel_id=? LIMIT 1');
    $chk->execute([$course,$personId]);
    if($chk->fetch()){
     $pdo->prepare('DELETE FROM training_applicants WHERE course_key=? AND personnel_id=?')->execute([$course,$personId]);
     $success='نشان «نیاز به بازآموزی» برای این عنصر حذف شد.';
    }else{
     $pdo->prepare("INSERT INTO training_applicants(course_key,personnel_id,applicant_type,created_by) VALUES(?,?, 'retraining', ?)")->execute([$course,$personId,user()['id']]);
     $success='این عنصر برای دوره «'.$courseOptions[$course].'» نیاز به بازآموزی دارد.';
    }
   }catch(PDOException $e){ $error='ذخیره اطلاعات انجام نشد.'; }
  }
 }
}

/* ---- فیلترها ---- */
$courseKey=trim((string)($_GET['course_key']??''));
if($courseKey!=='' && !isset($courseOptions[$courseKey])) $courseKey='';

$q=fa_to_en_digits(trim((string)($_GET['q']??'')));
$q=strtr($q,["\u{00A0}"=>' ',"\u{200C}"=>' ',"\u{200D}"=>' ',"\u{064A}"=>'ی',"\u{0649}"=>'ی',"\u{0643}"=>'ک']);
$q=preg_replace('/\s+/u',' ',$q);

$provinceId=(int)($_GET['province_id']??0);
$categoryNumber=substr(preg_replace('/[^0-9]/','',fa_to_en_digits(trim((string)($_GET['category_number']??'')))),0,3);
$commanderNumber=substr(preg_replace('/[^0-9]/','',fa_to_en_digits(trim((string)($_GET['commander_number']??'')))),0,30);
$statusFilter=(string)($_GET['status']??'');
if(!in_array($statusFilter,['completed','not_passed','retraining'],true)) $statusFilter='';

$provinces=$pdo->query("SELECT id,province_name FROM provinces WHERE is_active=1 AND province_code <> 'UNKNOWN' ORDER BY province_name")->fetchAll();

$allowed=allowed_units();$ph=implode(',',array_fill(0,count($allowed),'?'));
$where=["ct.category_key IN ($ph)", active_personnel_sql('p')]; $params=$allowed;
if($provinceId){$where[]='p.province_id=?';$params[]=$provinceId;}
if($categoryNumber!==''){$where[]='cn.unit_number=?';$params[]=$categoryNumber;}
if($hasCommanderColumn && $commanderNumber!==''){$where[]='p.commander_number LIKE ?';$params[]='%'.$commanderNumber.'%';}
if($q!==''){$where[]='(p.full_name LIKE ? OR p.mobile LIKE ?)';$like='%'.$q.'%';array_push($params,$like,$like);}

/* وضعیت هر عنصر نسبت به دوره انتخابی:
   سبز = قبلاً دوره را گذرانده · زرد = نشان بازآموزی دارد · قرمز = دوره را نگذرانده */
$sql="SELECT p.id,p.full_name,p.mobile,ct.category_key AS unit,cn.unit_number,pr.province_name"
    .($hasCommanderColumn?",p.commander_number":'')
    ." FROM personnel p
      JOIN category_types ct ON ct.id=p.category_type_id
      LEFT JOIN category_numbers cn ON cn.id=p.category_number_id
      JOIN provinces pr ON pr.id=p.province_id
      WHERE ".implode(' AND ',$where)." ORDER BY p.full_name";
$st=$pdo->prepare($sql);$st->execute($params);$people=$st->fetchAll();

/* وضعیت دوره با کوئری جدا (خوانا و بدون وابستگی به ترتیب بایند) گرفته می‌شود. */
$doneMap=[]; $retrainMap=[];
if($courseKey!==''){
 $done=$pdo->prepare('SELECT DISTINCT personnel_id FROM training_records WHERE course_key=?');
 $done->execute([$courseKey]);
 foreach($done->fetchAll() as $row) $doneMap[(int)$row['personnel_id']]=true;
 if($hasApplicantsTable){
  $rt=$pdo->prepare('SELECT personnel_id FROM training_applicants WHERE course_key=?');
  $rt->execute([$courseKey]);
  foreach($rt->fetchAll() as $row) $retrainMap[(int)$row['personnel_id']]=true;
 }
}

$sections=[
 'not_passed'=>['title'=>'دوره را نگذرانده‌اند','row'=>'is-notpassed','chip'=>'c-notpassed','chip_text'=>'نگذرانده'],
 'retraining'=>['title'=>'نیاز به بازآموزی دارند','row'=>'is-retraining','chip'=>'c-retrain','chip_text'=>'بازآموزی'],
 'completed'=>['title'=>'دوره را گذرانده‌اند','row'=>'is-done','chip'=>'c-done','chip_text'=>'گذرانده'],
];
$grouped=['not_passed'=>[],'retraining'=>[],'completed'=>[]];
foreach($people as $p){
 if($courseKey==='') continue;
 $pid=(int)$p['id'];
 $s = isset($retrainMap[$pid]) ? 'retraining' : (isset($doneMap[$pid]) ? 'completed' : 'not_passed');
 $grouped[$s][]=$p;
}

/* پارامترهای فعلی برای لینک دکمه‌های وضعیت و حفظ فیلترها بعد از ثبت */
function applicants_query(array $override=[]): string {
 $params=array_merge($_GET,$override);
 return http_build_query(array_filter($params,fn($v)=>$v!==''&&$v!==null));
}
require __DIR__.'/../app/partials/header.php'; ?>
<section class="page-head equipment-head"><div><h1>گواهی آموزش</h1><p>متقاضیان برگزاری آموزش و وضعیت دوره‌های عناصر</p></div></section>
<div class="training-tabs">
  <a class="training-tab" href="training.php">ثبت آموزش عناصر</a>
  <a class="training-tab" href="training_report.php">گزارش آموزش عناصر</a>
  <a class="training-tab active" href="training_applicants.php">متقاضیان برگزاری آموزش</a>
</div>
<?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?>
<?php if($error):?><div class="alert danger"><?=e($error)?></div><?php endif;?>
<?php if(!$hasApplicantsTable): ?><div class="alert danger">ساختار دیتابیس نیاز به به‌روزرسانی دارد. فایل database/upgrade_v6.1.sql را روی پایگاه داده اجرا کنید تا نشان «نیاز به بازآموزی» فعال شود.</div><?php endif; ?>

<form class="panel applicants-filter-panel" method="get">
  <div class="filter-bar">
    <select name="course_key" aria-label="دوره آموزشی">
      <option value="" <?= $courseKey===''?'selected':'' ?>>انتخاب دوره آموزشی</option>
      <?php foreach($courseOptions as $k=>$v):?><option value="<?=e($k)?>" <?= $courseKey===$k?'selected':'' ?>><?=e($v)?></option><?php endforeach;?>
    </select>
    <select name="province_id" aria-label="استان محل خدمت">
      <option value="" <?= !$provinceId?'selected':'' ?>>استان محل خدمت</option>
      <?php foreach($provinces as $pr):?><option value="<?=$pr['id']?>" <?= $provinceId===(int)$pr['id']?'selected':'' ?>><?=e($pr['province_name'])?></option><?php endforeach;?>
    </select>
    <input type="text" name="category_number" class="filter-control filter-number" inputmode="numeric" maxlength="3" autocomplete="off" placeholder="شماره دسته" value="<?= e($categoryNumber) ?>" aria-label="شماره دسته">
    <input type="text" name="commander_number" class="filter-control filter-commander" inputmode="numeric" maxlength="30" autocomplete="off" placeholder="شماره قائد" value="<?= e($commanderNumber) ?>" aria-label="شماره قائد" <?= $hasCommanderColumn?'':'disabled' ?>>
    <input type="search" name="q" class="filter-control filter-search" placeholder="جستجو نام، نام خانوادگی یا موبایل" value="<?= e($_GET['q']??'') ?>" aria-label="جستجو">
    <button class="filter-btn" type="submit">جستجو</button>
  </div>
  <?php if($courseKey!==''): ?>
  <div class="status-toggle-group" role="group" aria-label="فیلتر وضعیت دوره">
    <a class="status-toggle<?= $statusFilter===''?' active':'' ?>" href="training_applicants.php?<?=e(applicants_query(['status'=>'']))?>">همه</a>
    <a class="status-toggle<?= $statusFilter==='not_passed'?' active':'' ?>" href="training_applicants.php?<?=e(applicants_query(['status'=>'not_passed']))?>"><span class="toggle-dot dot-red"></span>دوره را نگذرانده‌اند</a>
    <a class="status-toggle<?= $statusFilter==='retraining'?' active':'' ?>" href="training_applicants.php?<?=e(applicants_query(['status'=>'retraining']))?>"<?= $hasApplicantsTable?'':' aria-disabled="true"' ?>><span class="toggle-dot dot-yellow"></span>نیاز به بازآموزی دارند</a>
    <a class="status-toggle<?= $statusFilter==='completed'?' active':'' ?>" href="training_applicants.php?<?=e(applicants_query(['status'=>'completed']))?>"><span class="toggle-dot dot-green"></span>دوره را گذرانده‌اند</a>
  </div>
  <?php endif; ?>
</form>

<div class="panel applicants-panel">
  <div class="training-list-head">
    <div><h2>لیست عناصر</h2><p><?= fa_digits((string)count($people)) ?> عنصر<?= $courseKey!=='' ? ' · دوره: '.e($courseOptions[$courseKey]) : '' ?></p></div>
  </div>
  <?php if($courseKey===''): ?>
    <div class="personnel-empty"><strong>ابتدا دوره آموزشی را انتخاب کنید.</strong><span>پس از انتخاب دوره، عناصر در سه بخش «نگذرانده»، «نیاز به بازآموزی» و «گذرانده» نمایش داده می‌شوند.</span></div>
  <?php elseif(!$people): ?>
    <div class="personnel-empty"><strong>موردی پیدا نشد.</strong><span>فیلترها یا عبارت جست‌وجو را تغییر بدهید.</span></div>
  <?php else: ?>
    <?php foreach($sections as $key=>$sec): ?>
      <?php if($statusFilter!=='' && $statusFilter!==$key) continue; $rows=$grouped[$key]; if(!$rows && $statusFilter!=='') continue; ?>
      <section class="applicant-section <?= $sec['row'] ?>">
        <div class="applicant-section-head">
          <h3><?= e($sec['title']) ?></h3>
          <span class="applicant-count"><?= fa_digits((string)count($rows)) ?> عنصر</span>
        </div>
        <?php if($rows): ?>
          <?php foreach($rows as $index=>$p): ?>
          <div class="applicant-row <?= $sec['row'] ?>">
            <span class="applicant-index"><?= fa_digits((string)($index+1)) ?></span>
            <div class="applicant-main">
              <a class="applicant-name" href="personnel_view.php?id=<?=(int)$p['id']?>&tab=training"><?=e($p['full_name'])?></a>
              <small><?=e($p['mobile'])?> · <?=e(unit_label($p['unit']))?><?= $p['unit_number']!==null ? ' · شماره دسته '.fa_digits((string)$p['unit_number']) : '' ?><?= $hasCommanderColumn && trim((string)($p['commander_number']??''))!=='' ? ' · شماره قائد '.fa_digits((string)$p['commander_number']) : '' ?></small>
            </div>
            <span class="applicant-chip <?= $sec['chip'] ?>"><?= e($sec['chip_text']) ?></span>
            <?php if(can_manage_personnel() && $hasApplicantsTable): ?>
              <?php if($key==='completed'): ?>
              <form method="post" class="applicant-action">
                <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                <input type="hidden" name="action" value="toggle_retraining">
                <input type="hidden" name="course_key" value="<?=e($courseKey)?>">
                <input type="hidden" name="personnel_id" value="<?=(int)$p['id']?>">
                <button type="submit" class="btn secondary">نیاز به بازآموزی</button>
              </form>
              <?php elseif($key==='retraining'): ?>
              <form method="post" class="applicant-action">
                <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                <input type="hidden" name="action" value="toggle_retraining">
                <input type="hidden" name="course_key" value="<?=e($courseKey)?>">
                <input type="hidden" name="personnel_id" value="<?=(int)$p['id']?>">
                <button type="submit" class="btn secondary">حذف نشان بازآموزی</button>
              </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="applicant-row applicant-row-empty">عنصری در این بخش نیست.</div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script src="<?= e(asset_url('assets/filter-bar.js')) ?>" defer></script>
<script>
(function(){
 /* ارقام کادرهای شماره دسته و شماره قائد فارسی و فقط عددی بمانند. */
 document.querySelectorAll('[name="category_number"],[name="commander_number"]').forEach(function(el){
  el.addEventListener('input',function(){
   this.value=this.value.replace(/[۰-۹]/g,function(d){return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d));}).replace(/[^0-9]/g,'').replace(/[0-9]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[+d];});
  });
 });
})();
</script>
<?php require __DIR__.'/../app/partials/footer.php'; ?>
