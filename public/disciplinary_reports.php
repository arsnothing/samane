<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();

$personnelId = filter_input(INPUT_GET, 'personnel_id', FILTER_VALIDATE_INT);
if (!$personnelId) { http_response_code(404); exit('شناسه عنصر مشخص نشده است.'); }
$st=$pdo->prepare(personnel_select_sql('p').' WHERE p.id=?');
$st->execute([$personnelId]);
$person=$st->fetch();
if(!$person || !can_view_unit($person['unit'])){http_response_code(404);exit('رکورد یافت نشد.');}

$q=fa_to_en_digits(trim((string)($_GET['q']??'')));
$sortMap=['date'=>'r.created_at','person'=>"CONCAT_WS(' ',p.first_name,p.last_name)",'type'=>'r.report_type','subject'=>'s.subject_name','reason'=>'r.reason'];
$sort=$_GET['sort']??'date'; if(!isset($sortMap[$sort]))$sort='date';
$direction=strtolower((string)($_GET['direction']??'desc'))==='asc'?'asc':'desc';
$where=[];$params=[];
if($personnelId){$where[]='r.personnel_id=?';$params[]=$personnelId;}
if($q!==''){
    $like='%'.$q.'%';
    $where[]="(CONCAT_WS(' ',p.first_name,p.last_name) LIKE ? OR p.national_id LIKE ? OR s.subject_name LIKE ? OR r.reason LIKE ? OR r.description LIKE ? OR r.report_type LIKE ?)";
    array_push($params,$like,$like,$like,$like,$like,$like);
}
$sql="SELECT r.*,s.subject_name,u.full_name AS creator_name,CONCAT_WS(' ',p.first_name,p.last_name) AS personnel_name FROM disciplinary_reports r JOIN personnel p ON p.id=r.personnel_id JOIN disciplinary_report_subjects s ON s.id=r.subject_id LEFT JOIN users u ON u.id=r.created_by".($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY '.$sortMap[$sort].' '.$direction.', r.id DESC';
$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
$typeLabels=['encouragement'=>'تشویق','warning'=>'اخطار','reprimand'=>'توبیخ'];
function sort_link(string $key,string $label,string $current,string $direction,string $q,int $personnelId): string {
    $dir=($current===$key && $direction==='asc')?'desc':'asc';
    $params=['sort'=>$key,'direction'=>$dir]; if($q!=='')$params['q']=$q; if($personnelId)$params['personnel_id']=$personnelId;
    return '<a href="?'.http_build_query($params).'">'.e($label).'</a>';
}

require __DIR__.'/../app/partials/header.php'; ?>
<div class="disciplinary-report-page">
<section class="page-head disciplinary-report-head">
  <div><div class="eyebrow">گزارش پرونده انضباطی</div><h1><?=e($person['full_name'])?></h1></div>
  <div class="disciplinary-report-actions"><?php if($person): ?><?php endif; ?></div>
</section>
<div class="report-search-bar panel">
  <form method="get" class="report-search-form">
    <?php if($personnelId): ?><input type="hidden" name="personnel_id" value="<?=e((string)$personnelId)?>"><?php endif; ?>
    <input type="hidden" name="sort" value="<?=e($sort)?>"><input type="hidden" name="direction" value="<?=e($direction)?>">
    <div class="report-search-field"><span class="report-search-icon" aria-hidden="true">⌕</span><input type="search" name="q" value="<?=e($q)?>" placeholder="جستجو در نام، کد ملی، نوع پرونده، موضوع یا توضیحات..." autocomplete="off"></div>
    <button type="submit" class="btn primary">جستجو</button>
  </form>
</div>

<?php if(($person['personnel_status'] ?? '') === 'dismissed'):
    $dismissStmt=$pdo->prepare('SELECT dismissal_type,reason,dismissed_at FROM personnel_dismissals WHERE personnel_id=? LIMIT 1');
    $dismissStmt->execute([$personnelId]);
    $dismissInfo=$dismissStmt->fetch();
    $dismissTypeLabels=['deputy'=>'معاونت','unit_commander'=>'فرمانده دسته','group_commander'=>'فرمانده گروه','resignation'=>'استعفا'];
?>
<section class="panel dismissal-status-table" aria-label="اطلاعات برکناری">
  <div class="dismissal-status-badge">برکنار شده</div>
  <div class="dismissal-table-grid">
    <div class="dismissal-table-cell"><span>نوع برکناری</span><strong><?=e($dismissTypeLabels[$dismissInfo['dismissal_type']??'']??'—')?></strong></div>
    <div class="dismissal-table-cell dismissal-reason-cell"><span>دلیل برکناری</span><strong><?=e($dismissInfo['reason']??'—')?></strong></div>
    <div class="dismissal-table-cell"><span>تاریخ برکناری</span><strong><?=e(jalali_display($dismissInfo['dismissed_at']??null))?></strong></div>
  </div>
</section>
<?php endif; ?>
<div class="panel table-wrap disciplinary-report-table">
<table><thead><tr><th>ردیف</th><th><?=sort_link('date','تاریخ',$sort,$direction,$q,$personnelId)?></th><?php if(!$person): ?><th><?=sort_link('person','نام و نام خانوادگی',$sort,$direction,$q,$personnelId)?></th><?php endif; ?><th><?=sort_link('type','نوع پرونده',$sort,$direction,$q,$personnelId)?></th><th><?=sort_link('subject','موضوع',$sort,$direction,$q,$personnelId)?></th><th><?=sort_link('reason','علت',$sort,$direction,$q,$personnelId)?></th><th>توضیحات</th><th>ثبت‌کننده</th></tr></thead>
<tbody><?php $reportRow=0; foreach($rows as $row): $reportRow++; ?><tr><td data-label="ردیف"><?=fa_digits((string)$reportRow)?></td><td class="jalali-display" data-label="تاریخ"><?=e(jalali_display($row['created_at']))?></td><?php if(!$person): ?><td data-label="نام و نام خانوادگی"><?=e($row['personnel_name'])?></td><?php endif; ?><td data-label="نوع پرونده"><span class="report-type-badge"><?=e($typeLabels[$row['report_type']]??$row['report_type'])?></span></td><td data-label="موضوع"><?=e($row['subject_name'])?></td><td data-label="علت"><?=e($row['reason'])?></td><td data-label="توضیحات"><?=e($row['description']?:'—')?></td><td data-label="ثبت‌کننده"><?=e($row['creator_name']?:'—')?></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="7" class="empty">پرونده‌ای با این معیار پیدا نشد.</td></tr><?php endif;?></tbody></table>
</div>

<?php require __DIR__.'/../app/partials/footer.php'; ?>