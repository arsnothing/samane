<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(404); exit('رکورد یافت نشد.'); }
$st = $pdo->prepare(personnel_select_sql('p').' WHERE p.id=?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r || !can_view_unit($r['unit'])) { http_response_code(404); exit('رکورد یافت نشد.'); }

$personnelId = (int)$id;
$name = 'disciplinary.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!can_manage_personnel()) { http_response_code(403); exit('دسترسی غیرمجاز'); }
    block_if_dismissed($r, 'ثبت پرونده انضباطی');
    $reportType = trim((string)($_POST['report_type'] ?? ''));
    $subjectId = filter_var($_POST['subject_id'] ?? null, FILTER_VALIDATE_INT);
    $reason = trim((string)($_POST['reason'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $allowedTypes = ['encouragement','warning','reprimand'];
    if (!in_array($reportType, $allowedTypes, true) || !$subjectId || $reason === '') {
        $error = 'نوع پرونده، موضوع و علت الزامی است.';
    } else {
        $check = $pdo->prepare('SELECT id FROM disciplinary_report_subjects WHERE id=? AND is_active=1 LIMIT 1');
        $check->execute([$subjectId]);
        if (!$check->fetchColumn()) {
            $error = 'موضوع انتخاب‌شده معتبر نیست.';
        } else {
            $ins = $pdo->prepare('INSERT INTO disciplinary_reports(personnel_id,report_type,subject_id,reason,description,created_by) VALUES(?,?,?,?,?,?)');
            $ins->execute([$id,$reportType,$subjectId,$reason,$description !== '' ? $description : null,user()['id'] ?? null]);
            apply_disciplinary_rules((int)$id, user()['id'] ?? null);
            redirect('disciplinary.php?id='.(int)$id);
        }
    }
}

$subjects = $pdo->query('SELECT id,subject_key,subject_name FROM disciplinary_report_subjects WHERE is_active=1 ORDER BY sort_order,id')->fetchAll();
$summary = [
    'encouragement' => array_fill_keys(array_column($subjects, 'id'), 0),
    'warning' => array_fill_keys(array_column($subjects, 'id'), 0),
    'reprimand' => array_fill_keys(array_column($subjects, 'id'), 0),
];
$st = $pdo->prepare('SELECT report_type,subject_id,COUNT(*) AS total FROM disciplinary_reports WHERE personnel_id=? GROUP BY report_type,subject_id');
$st->execute([$id]);
foreach ($st->fetchAll() as $row) { $summary[$row['report_type']][(int)$row['subject_id']] = (int)$row['total']; }
$typeLabels = ['encouragement'=>'تشویق','warning'=>'اخطار','reprimand'=>'توبیخ'];
$typeTotals = [];
foreach ($typeLabels as $key=>$label) { $typeTotals[$key] = array_sum($summary[$key]); }

require __DIR__.'/../app/partials/header.php'; ?>
<section class="page-head disciplinary-hero">
  <div>
    <div class="eyebrow">پرونده انضباطی</div>
    <h1><?=e($r['full_name'])?></h1>
    <p><?=e(position_options()[$r['position_type']] ?? $r['position_type'])?> · <?=e(unit_label($r['unit']))?></p>
  </div>
  <div class="disciplinary-actions">
    <?php if (can_manage_personnel()): ?><a class="btn primary disciplinary-register-btn" href="disciplinary_form.php?id=<?=((int)$id)?>">ثبت</a><?php endif; ?>
    <a class="btn disciplinary-report-btn" href="disciplinary_reports.php?personnel_id=<?=(int)$id?>">گزارش پرونده انضباطی</a>
    <button type="button" class="btn dismiss-btn" data-dismiss-open>برکناری عنصر</button>
  </div>
</section>

<?php if($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<?php if(isset($_GET['dismiss_error'])): ?><div class="alert error">علت برکناری را وارد کنید.</div><?php endif; ?>
<?php if(isset($_GET['dismissed'])): ?><div class="alert success">عنصر با موفقیت برکنار شد.</div><?php endif; ?>

<div class="disciplinary-stats">
<?php foreach ($typeLabels as $typeKey=>$title): $total=$typeTotals[$typeKey]; ?>
  <section class="panel disciplinary-card">
    <div class="disciplinary-card-head">
      <div><span class="disciplinary-label">پرونده</span><h2><?=e($title)?></h2></div>
      <strong class="disciplinary-total"><?=fa_digits((string)$total)?></strong>
    </div>
    <div class="disciplinary-list">
      <?php foreach ($subjects as $subject): $count=$summary[$typeKey][(int)$subject['id']] ?? 0; ?>
        <div class="disciplinary-row"><span><?=e($subject['subject_name'])?></span><strong><?=fa_digits((string)$count)?></strong></div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>

<section class="panel disciplinary-chart-panel">
  <div class="section-title">نمودار توزیع پرونده‌ها</div>
  <p class="disciplinary-note">سهم هر موضوع از پرونده‌های ثبت‌شده برای این عنصر.</p>
  <div class="disciplinary-pies">
  <?php foreach ($typeLabels as $typeKey=>$title):
      $total=max(1,$typeTotals[$typeKey]); $offset=0; $stops=[];
      foreach($subjects as $i=>$subject){$pct=(($summary[$typeKey][(int)$subject['id']]??0)/$total)*100;$next=$offset+$pct; $stops[]=$offset.'% '.$next.'%';$offset=$next;}
      $colors=['#244b3b','#6f9b88','#b7d0c3'];
      $segments=[];$offset=0;
      foreach($subjects as $i=>$subject){$pct=(($summary[$typeKey][(int)$subject['id']]??0)/$total)*100;$next=$offset+$pct;$segments[]=$colors[$i%3].' '.$offset.'% '.$next.'%';$offset=$next;}
      $gradient = $offset > 0 ? 'conic-gradient('.implode(',',$segments).')' : 'conic-gradient(#dfe9e3 0 100%)';
  ?>
    <div class="disciplinary-pie-card">
      <h3><?=e($title)?></h3>
      <div class="pie-wrap"><div class="disciplinary-pie" style="background:<?=$gradient?>"></div></div>
      <div class="pie-legend">
        <?php foreach ($subjects as $i=>$subject): ?>
          <div><span class="pie-dot <?=['one','two','three'][$i%3]?>"></span><span><?=e($subject['subject_name'])?></span><strong><?=fa_digits((string)($summary[$typeKey][(int)$subject['id']]??0))?></strong></div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</section>


<div class="dismiss-modal" data-dismiss-modal aria-hidden="true">
  <div class="dismiss-modal-backdrop" data-dismiss-close></div>
  <section class="dismiss-modal-card" role="dialog" aria-modal="true" aria-labelledby="dismissTitle">
    <div class="dismiss-modal-head"><div><span class="eyebrow">تغییر وضعیت عنصر</span><h2 id="dismissTitle">برکناری عنصر</h2></div><button type="button" class="dismiss-modal-close" data-dismiss-close aria-label="بستن">×</button></div>
    <form method="post" action="personnel_dismiss.php" class="dismiss-form">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="personnel_id" value="<?=((int)($id ?? $personnelId ?? 0))?>">
      <input type="hidden" name="return_to" value="<?=e($name==='disciplinary.php'?'disciplinary.php?id='.(int)$id:'disciplinary_reports.php?personnel_id='.(int)$personnelId)?>">
      <label class="dismiss-reason-label"><span>نوع برکناری</span><select name="dismissal_type" required><option value="" selected disabled>انتخاب نوع برکناری</option><option value="deputy">معاونت</option><option value="unit_commander">فرمانده دسته</option><option value="group_commander">فرمانده گروه</option><option value="resignation">استعفا</option></select></label>
      <label class="dismiss-reason-label"><span>علت برکناری</span><input type="text" name="reason" maxlength="500" required placeholder="علت برکناری را وارد کنید"></label>
      <div class="dismiss-modal-actions"><button type="submit" class="btn danger dismiss-submit">ثبت</button><button type="button" class="btn secondary" data-dismiss-close>انصراف</button></div>
    </form>
  </section>
</div>
<script>
(function(){const modal=document.querySelector('[data-dismiss-modal]'); if(!modal)return; const open=()=>{modal.classList.add('open');modal.setAttribute('aria-hidden','false');document.body.classList.add('modal-open');setTimeout(()=>modal.querySelector('input[name="reason"]')?.focus(),40)};const close=()=>{modal.classList.remove('open');modal.setAttribute('aria-hidden','true');document.body.classList.remove('modal-open')};document.querySelectorAll('[data-dismiss-open]').forEach(b=>b.addEventListener('click',open));document.querySelectorAll('[data-dismiss-close]').forEach(b=>b.addEventListener('click',close));document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('open'))close()});})();
</script>
<?php require __DIR__.'/../app/partials/footer.php'; ?>
