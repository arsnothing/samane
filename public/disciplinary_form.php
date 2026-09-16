<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(404); exit('رکورد یافت نشد.'); }
$st = $pdo->prepare(personnel_select_sql('p').' WHERE p.id=?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r || !can_view_unit($r['unit'])) { http_response_code(404); exit('رکورد یافت نشد.'); }
if (!can_manage_personnel()) { http_response_code(403); exit('دسترسی غیرمجاز'); }
block_if_dismissed($r, 'ثبت پرونده انضباطی');
$error='';
$reportType=trim((string)($_POST['report_type']??''));
$subjectId=filter_var($_POST['subject_id']??null,FILTER_VALIDATE_INT);
$reason=trim((string)($_POST['reason']??''));
$description=trim((string)($_POST['description']??''));
$subjects=$pdo->query('SELECT id,subject_key,subject_name FROM disciplinary_report_subjects WHERE is_active=1 ORDER BY sort_order,id')->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 $allowed=['encouragement','warning','reprimand'];
 if(!in_array($reportType,$allowed,true)||!$subjectId||$reason===''){$error='نوع پرونده، موضوع و علت الزامی است.';}
 else{
  $check=$pdo->prepare('SELECT id FROM disciplinary_report_subjects WHERE id=? AND is_active=1 LIMIT 1');$check->execute([$subjectId]);
  if(!$check->fetchColumn()){$error='موضوع انتخاب‌شده معتبر نیست.';}
  else{
   $ins=$pdo->prepare('INSERT INTO disciplinary_reports(personnel_id,report_type,subject_id,reason,description,created_by) VALUES(?,?,?,?,?,?)');
   $ins->execute([$id,$reportType,$subjectId,$reason,$description!==''?$description:null,user()['id']??null]);
   apply_disciplinary_rules((int)$id, user()['id']??null);
   redirect('disciplinary.php?id='.(int)$id);
  }
 }
}
$typeLabels=['encouragement'=>'تشویق','warning'=>'اخطار','reprimand'=>'توبیخ'];
require __DIR__.'/../app/partials/header.php';
?>
<section class="page-head disciplinary-form-head">
  <div><div class="eyebrow">ثبت پرونده انضباطی</div><h1><?=e($r['full_name'])?></h1></div>
</section>
<?php if($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<section class="panel disciplinary-form-page-card">
  <div class="disciplinary-form-page-head"><div><span class="disciplinary-label">پرونده جدید</span><h2>اطلاعات پرونده</h2></div></div>
  <form method="post" class="disciplinary-page-form" id="disciplinaryPageForm">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <div class="form-grid-2">
      <label>نوع پرونده
        <select name="report_type" id="disciplinaryType" required>
          <option value="" disabled hidden <?= $reportType===''?'selected':'' ?>>انتخاب نوع پرونده</option>
          <?php foreach($typeLabels as $k=>$v): ?><option value="<?=e($k)?>" <?= $reportType===$k?'selected':'' ?>><?=e($v)?></option><?php endforeach; ?>
        </select>
      </label>
      <label>موضوع
        <select name="subject_id" id="disciplinarySubject" required <?= $reportType===''?'disabled':'' ?>>
          <option value="" disabled hidden <?= !$subjectId?'selected':'' ?>><?= $reportType===''?'ابتدا نوع پرونده را انتخاب کنید':'انتخاب موضوع' ?></option>
          <?php foreach($subjects as $subject): ?><option value="<?=e((string)$subject['id'])?>" data-key="<?=e($subject['subject_key'])?>" <?= $subjectId==(int)$subject['id']?'selected':'' ?>><?=e($subject['subject_name'])?></option><?php endforeach; ?>
        </select>
      </label>
    </div>
    <label>علت<input type="text" name="reason" maxlength="255" required value="<?=e($reason)?>" placeholder="علت ثبت پرونده را وارد کنید"></label>
    <label>توضیحات<textarea name="description" rows="8" maxlength="3000" placeholder="توضیحات تکمیلی را وارد کنید"><?=e($description)?></textarea></label>
    <div class="disciplinary-page-actions"><a class="btn secondary" href="disciplinary.php?id=<?=(int)$id?>">انصراف</a><button class="btn primary disciplinary-submit-btn" type="submit">ثبت</button></div>
  </form>
</section>
<script>
(()=>{
 const type=document.getElementById('disciplinaryType'), subject=document.getElementById('disciplinarySubject');
 const subjects=<?=json_encode($subjects,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
 const selected=<?=json_encode($subjectId?:null)?>;
 function loadSubjects(){
  const has=!!type.value; subject.innerHTML='';
  const p=document.createElement('option'); p.value=''; p.disabled=true; p.hidden=true; p.selected=true; p.textContent=has?'انتخاب موضوع':'ابتدا نوع پرونده را انتخاب کنید'; subject.appendChild(p);
  subject.disabled=!has;
  if(has){ subjects.forEach(s=>{const o=document.createElement('option');o.value=s.id;o.textContent=s.subject_name;subject.appendChild(o);}); if(selected) subject.value=String(selected); }
 }
 type.addEventListener('change', loadSubjects);
 if(!type.value) subject.disabled=true;
})();
</script>
<?php require __DIR__.'/../app/partials/footer.php'; ?>
