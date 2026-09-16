<?php
require __DIR__ . '/../app/bootstrap.php'; require_login();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);$st=$pdo->prepare(personnel_select_sql('p').' WHERE p.id=?');$st->execute([$id]);$r=$st->fetch();if(!$r||!can_view_unit($r['unit'])){http_response_code(404);exit('رکورد یافت نشد.');}
$position=position_options()[$r['position_type']]??$r['position_type'];$edu=education_options()[$r['education_status']]??'—';$marital=['single'=>'مجرد','married'=>'متأهل','separated'=>'متارکه'][$r['marital_status']??'']??'—';$docs=$pdo->prepare('SELECT * FROM personnel_documents WHERE personnel_id=? ORDER BY id DESC');$docs->execute([$r['id']]);$docs=$docs->fetchAll();
$tab=$_GET['tab']??'profile';
$finance=$pdo->prepare('SELECT t.id,t.transfer_type,t.transfer_date,t.reason,t.note,i.amount,i.iban,i.beneficiary_name FROM financial_transactions t JOIN financial_transaction_items i ON i.transaction_id=t.id WHERE i.personnel_id=? ORDER BY t.transfer_date DESC,t.id DESC');$finance->execute([$r['id']]);$financeRows=$finance->fetchAll();
$orders=$pdo->prepare('SELECT * FROM personnel_orders WHERE personnel_id=? ORDER BY COALESCE(start_date,order_date,created_at) DESC,id DESC');$orders->execute([$r['id']]);$resourceOrders=$orders->fetchAll();
$equipments=$pdo->prepare('SELECT * FROM personnel_equipment WHERE personnel_id=? ORDER BY COALESCE(delivery_date,created_at) DESC,id DESC');$equipments->execute([$r['id']]);$resourceEquipments=$equipments->fetchAll();

/* ---- پرونده انضباطی (تشویق / اخطار / توبیخ) ---- */
$disciplinaryTypes=['encouragement'=>'موارد تشویق ثبت شده','warning'=>'موارد اخطار ثبت شده','reprimand'=>'موارد توبیخ ثبت شده'];
$disciplinaryGroups=array_fill_keys(array_keys($disciplinaryTypes),[]);
if($tab==='disciplinary'){
    $discColumns=[];foreach($pdo->query('SHOW COLUMNS FROM disciplinary_reports') as $col){$discColumns[strtolower($col['Field'])]=true;}
    $dateExpr=isset($discColumns['report_date'])?'COALESCE(d.report_date,DATE(d.created_at))':'DATE(d.created_at)';
    $typeExpr=isset($discColumns['subject_title'])?"COALESCE(NULLIF(d.subject_title,''),s.subject_name)":'s.subject_name';
    $dr=$pdo->prepare('SELECT d.id,d.report_type,d.reason,'.$dateExpr.' AS report_day,'.$typeExpr.' AS subject_name FROM disciplinary_reports d LEFT JOIN disciplinary_report_subjects s ON s.id=d.subject_id WHERE d.personnel_id=? ORDER BY report_day DESC,d.id DESC');
    $dr->execute([$r['id']]);
    foreach($dr->fetchAll() as $row){ $key=(string)$row['report_type']; if(isset($disciplinaryGroups[$key])) $disciplinaryGroups[$key][]=$row; }
}

/* ---- وضعیت آموزشی ---- */
$trainingRows=[]; $trainingDocs=[];
if($tab==='training'){
    $hasTrainingDate=(bool)$pdo->query("SHOW COLUMNS FROM training_records LIKE 'training_date'")->fetch();
    $dayExpr=$hasTrainingDate?'COALESCE(tr.training_date,DATE(tr.created_at))':'DATE(tr.created_at)';
    $tq=$pdo->prepare('SELECT tr.id,tr.course_name,'.$dayExpr.' AS course_day FROM training_records tr WHERE tr.personnel_id=? ORDER BY course_day DESC,tr.id DESC');
    $tq->execute([$r['id']]);
    $trainingRows=$tq->fetchAll();
    if($trainingRows){
        $ids=array_column($trainingRows,'id');
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $dq=$pdo->prepare("SELECT id,training_record_id,original_name,mime_type FROM training_documents WHERE training_record_id IN ($ph) ORDER BY id");
        $dq->execute($ids);
        foreach($dq->fetchAll() as $doc){ $trainingDocs[(int)$doc['training_record_id']][]=['id'=>(int)$doc['id'],'name'=>$doc['original_name'],'mime'=>$doc['mime_type']]; }
    }
}

/* ---- احکام ---- */
$orderRows=[]; $orderMemberMap=[]; $orderDocMap=[];
if($tab==='orders'){
    $orderCols=[]; foreach($pdo->query('SHOW COLUMNS FROM personnel_orders') as $col){$orderCols[strtolower($col['Field'])]=true;}
    $extra=isset($orderCols['duration_days'])
        ? ',o.duration_days,o.weapon_status,o.weapon_serial,o.purpose,o.vehicle_type,o.destination,o.is_renewable'
        : '';
    if(isset($orderCols['order_number'])) $extra.=',o.order_number';
    if(isset($orderCols['weapon_type'])) $extra.=',o.weapon_type';
    // احکامی که این عنصر «سرحکم» آن است + احکام ماموریتی که در فهرست همراهانشان قرار دارد.
    $memberOrderIds=[];
    try{
        $mo=$pdo->prepare('SELECT order_id FROM personnel_order_members WHERE personnel_id=?');
        $mo->execute([$r['id']]);
        $memberOrderIds=array_map('intval',array_column($mo->fetchAll(),'order_id'));
    }catch(Throwable $e){ /* جدول همراهان حکم هنوز ساخته نشده است */ }

    $sqlWhere='o.personnel_id=?'; $sqlParams=[$r['id']];
    if($memberOrderIds){
        $mph=implode(',',array_fill(0,count($memberOrderIds),'?'));
        $sqlWhere.=' OR o.id IN ('.$mph.')';
        $sqlParams=array_merge($sqlParams,$memberOrderIds);
    }
    $oq=$pdo->prepare('SELECT o.id,o.personnel_id,o.order_type,o.order_date,o.end_date,o.title,o.description,o.responsibility_position'.$extra.',hp.full_name AS holder_name,hp.organizational_code AS holder_code FROM personnel_orders o LEFT JOIN personnel hp ON hp.id=o.personnel_id WHERE ('.$sqlWhere.') ORDER BY COALESCE(o.order_date,o.start_date,DATE(o.created_at)) DESC,o.id DESC');
    $oq->execute($sqlParams);
    $orderRows=$oq->fetchAll();
    $memberOrderIds=array_flip($memberOrderIds);
    if($orderRows){
        $ids=array_column($orderRows,'id'); $ph=implode(',',array_fill(0,count($ids),'?'));
        try{
            $mq=$pdo->prepare("SELECT m.order_id,p.full_name FROM personnel_order_members m JOIN personnel p ON p.id=m.personnel_id WHERE m.order_id IN ($ph) ORDER BY p.full_name");
            $mq->execute($ids);
            foreach($mq->fetchAll() as $m){ $orderMemberMap[(int)$m['order_id']][]=$m['full_name']; }
        }catch(Throwable $e){ /* جدول اعضای زیرحکم هنوز ساخته نشده است */ }
        try{
            $dq=$pdo->prepare("SELECT id,order_id,original_name,mime_type FROM personnel_order_documents WHERE order_id IN ($ph) ORDER BY id");
            $dq->execute($ids);
            foreach($dq->fetchAll() as $d){ $orderDocMap[(int)$d['order_id']][]=$d; }
        }catch(Throwable $e){ /* جدول عکس حکم هنوز ساخته نشده است (upgrade_v4.60.sql) */ }
    }
}
$vehicleLabels=['car'=>'خودرو','motorcycle'=>'موتور سیکلت'];
require __DIR__.'/../app/partials/header.php'; ?>
<?php
$printTitles = ['profile'=>'اطلاعات فردی','disciplinary'=>'گزارش پرونده انضباطی','finance'=>'گزارش وضعیت مالی','training'=>'گزارش وضعیت آموزشی','orders'=>'گزارش احکام','equipment'=>'گزارش تجهیزات تحویلی'];
$printTitle = ($printTitles[$tab] ?? 'پرونده عنصر') . ' — ' . ($r['full_name'] ?? '');
require __DIR__.'/../app/partials/print_frame.php';
?>
<div class="profile-page-shell">
<?php $isDismissed = (($r['personnel_status'] ?? 'active') === 'dismissed'); ?>
<section class="profile-content-wrap<?= $isDismissed ? ' is-dismissed-profile' : '' ?>">
<div class="profile-head panel">
<div class="profile-head-info">
<h2><?=e($r['full_name'])?></h2>
<?php
// ترتیب: سمت، رسته، شماره دسته، گروه، تیم — موارد خالی اصلاً نمایش داده نمی‌شوند (بدون خط تیره).
$metaItems = [$position, ($r['unit']==='information'?'اطلاعاتی':($r['unit']==='operations'?'عملیاتی':unit_label($r['unit'])))];
if (trim((string)($r['unit_number'] ?? '')) !== '') $metaItems[] = 'شماره دسته '.fa_digits((string)$r['unit_number']);
if (trim((string)($r['commander_number'] ?? '')) !== '') $metaItems[] = 'شماره قائد '.fa_digits((string)$r['commander_number']);
if (($r['group_no'] ?? null) !== null)             $metaItems[] = 'گروه '.fa_digits((string)(int)$r['group_no']);
if (($r['team_no'] ?? null) !== null)              $metaItems[] = ($r['team_type'] ?: 'تیم '.fa_digits((string)(int)$r['team_no']));
$metaItems = array_values(array_filter(array_map('trim', $metaItems), static fn($v) => $v !== ''));
?>
<div class="profile-meta-text"><?=e(implode(' · ', $metaItems))?></div>
<?php if(trim((string)($r['organizational_code'] ?? '')) !== ''): ?>
<div class="profile-meta-text code">کد سازمانی <?=e(fa_digits((string)$r['organizational_code']))?></div>
<?php endif; ?>
</div>
<div class="avatar-wrap"><div class="avatar-frame"><img class="avatar" src="personnel_file.php?type=photo&amp;id=<?=(int)$r['id']?>&amp;v=<?=e(substr(md5((string)($r['profile_photo_path']??'')),0,8))?>" onerror="this.hidden=true;this.nextElementSibling.hidden=false;"><div class="avatar placeholder" hidden></div></div></div>
</div>
<div class="tabs"><a class="tab <?=$tab==='profile'?'active':''?>" href="personnel_view.php?id=<?=$r['id']?>&tab=profile">اطلاعات فردی</a><a class="tab <?=$tab==='disciplinary'?'active':''?>" href="personnel_view.php?id=<?=$r['id']?>&tab=disciplinary">پرونده انضباطی</a><a class="tab <?=$tab==='finance'?'active':''?>" href="personnel_view.php?id=<?=$r['id']?>&tab=finance">وضعیت مالی</a><a class="tab <?=$tab==='training'?'active':''?>" href="personnel_view.php?id=<?=$r['id']?>&tab=training">وضعیت آموزشی</a><a class="tab <?=$tab==='orders'?'active':''?>" href="personnel_view.php?id=<?=$r['id']?>&tab=orders">سوابق احکام</a><a class="tab <?=$tab==='equipment'?'active':''?>" href="personnel_view.php?id=<?=$r['id']?>&tab=equipment">تجهیزات</a><?php if(in_array($tab,['profile','finance','training','orders','disciplinary','equipment'],true)): ?><button type="button" class="tab-action print" onclick="window.print()">دریافت PDF</button><?php endif; ?><?php if(can_manage_personnel() && !$isDismissed): ?><?php if($tab==='profile'): ?><a class="tab-action edit" href="personnel_form.php?id=<?=(int)$r['id']?>">ویرایش پروفایل</a><?php elseif($tab==='disciplinary'): ?><button type="button" class="tab-action danger" data-pv-open="dismissModal">برکناری</button><?php endif; ?><?php endif; ?></div>
<?php if($tab==='equipment'): ?>
<div class="panel table-wrap equip-table"><table>
  <thead><tr><th class="col-row">ردیف</th><th class="col-kind">تجهیز</th><th class="col-serial">شماره سریال</th><th class="col-date">تاریخ تحویل</th><th class="col-date">تاریخ بازتحویل</th><th class="col-view">مشاهده</th></tr></thead>
  <tbody>
  <?php $equipRow=0; foreach($resourceEquipments as $eq): $equipRow++; ?>
    <tr>
      <td class="col-row"><?=fa_digits((string)$equipRow)?></td>
      <td class="col-kind"><strong><?=e($eq['equipment_type'])?></strong></td>
      <td class="col-serial"><?=e($eq['serial_number']?:'—')?></td>
      <td class="col-date"><?=jalali_display($eq['delivery_date'])?></td>
      <td class="col-date"><?=$eq['return_date']?jalali_display($eq['return_date']):'—'?></td>
      <td class="col-view"><button type="button" class="cert-btn equip-view" data-equip="<?=$equipRow-1?>">مشاهده</button></td>
    </tr>
  <?php endforeach; if(!$resourceEquipments): ?><tr><td colspan="6" class="empty">تجهیزی برای این عنصر ثبت نشده است.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php elseif($tab==='orders_legacy'): ?>

<?php elseif($tab==='disciplinary'): ?>
<?php if(isset($_GET['dismissed'])):?><div class="alert success">عنصر با موفقیت برکنار شد.</div><?php endif;?>
<?php if(isset($_GET['disc_saved'])):?><div class="alert success">پرونده <?=e($disciplinaryTypes[$_GET['disc_saved']]??'')?> ثبت شد.</div><?php endif;?>
<?php if(!empty($_GET['disc_auto'])):?><div class="alert danger">به ازای هر ۳ اخطار، <?=fa_digits((string)(int)$_GET['disc_auto'])?> توبیخ به‌صورت خودکار ثبت شد.</div><?php endif;?>
<?php if(isset($_GET['auto_dismissed'])):?><div class="alert danger">با ثبت سه توبیخ، این عنصر به‌صورت خودکار برکنار شد.</div><?php endif;?>
<?php if(isset($_GET['disc_error'])):?><div class="alert danger"><?=['date'=>'تاریخ واردشده صحیح نیست.','type'=>'نوع پرونده معتبر نیست.','schema'=>'ساختار دیتابیس نیاز به به‌روزرسانی دارد. فایل upgrade_v4.41.sql را اجرا کنید.','save'=>'ثبت پرونده انجام نشد.'][$_GET['disc_error']]??'بابت و نوع پرونده را تکمیل کنید.'?></div><?php endif;?>
<?php if(isset($_GET['dismiss_error'])):?><div class="alert danger"><?=['date'=>'تاریخ برکناری صحیح نیست.','file'=>'اسناد برکناری باید PDF/JPG/PNG/WEBP و حداکثر ۸ مگابایت باشند.','schema'=>'ساختار دیتابیس نیاز به به‌روزرسانی دارد. فایل upgrade_v4.41.sql را اجرا کنید.'][$_GET['dismiss_error']]??'علت برکناری را وارد کنید.'?></div><?php endif;?>
<?php foreach($disciplinaryTypes as $typeKey=>$typeLabel): $typeRows=$disciplinaryGroups[$typeKey]; ?>
<section class="panel disc-panel disc-<?=$typeKey?>">
  <div class="disc-panel-head">
    <div class="disc-panel-title"><span class="disc-dot"></span><h3><?=e($typeLabel)?></h3><span class="disc-count"><?=fa_digits((string)count($typeRows))?></span></div>
    <?php if(can_manage_personnel() && !$isDismissed):?><button type="button" class="disc-add-btn" data-pv-open="discModal" data-disc-type="<?=$typeKey?>" data-disc-title="<?=e($typeLabel)?>" aria-label="ثبت <?=e($typeLabel)?>">+</button><?php endif;?>
  </div>
  <div class="table-wrap disc-table"><table>
    <thead><tr><th class="col-row">ردیف</th><th class="col-date">تاریخ</th><th class="col-reason">بابت</th><th class="col-type">نوع <?=e($typeLabel)?></th></tr></thead>
    <tbody>
      <?php $discRow=0; foreach($typeRows as $d): $discRow++; ?>
      <tr><td class="col-row"><?=fa_digits((string)$discRow)?></td><td class="col-date"><?=jalali_display($d['report_day'])?></td><td class="col-reason"><?=e($d['reason']?:'—')?></td><td class="col-type"><?=e($d['subject_name']?:'—')?></td></tr>
      <?php endforeach; if(!$typeRows):?><tr><td colspan="4" class="empty">پرونده <?=e($typeLabel)?> ثبت نشده است.</td></tr><?php endif;?>
    </tbody>
  </table></div>
</section>
<?php endforeach; ?>
<?php elseif($tab==='orders'): ?>
<div class="order-cards">
  <?php foreach($orderRows as $o): $isMission=$o['order_type']==='mission';
    $isCompanion = (int)($o['personnel_id'] ?? 0) !== (int)$r['id'];
    $cardTitle = $isMission
      ? (trim((string)($o['purpose']??''))?:(trim((string)($o['title']??''))?:'حکم ماموریتی'))
      : (trim((string)($o['responsibility_position']??''))?:(trim((string)($o['title']??''))?:'حکم مسئولیتی'));
  ?>
  <button type="button" class="order-card" data-order="<?=(int)$o['id']?>">
    <span class="order-card-head">
      <span class="order-card-title"><?=e($cardTitle)?></span>
      <span class="order-card-date"><?=jalali_display($o['order_date'])?></span>
    </span>
    <span class="order-card-foot">
      <span class="order-chip <?= $isMission?'mission':'responsibility' ?>"><?= $isMission?'ماموریتی':'مسئولیتی' ?></span>
      <?php if($isMission && ($o['order_number']??'')!==''): ?><span class="order-card-no">شماره حکم <?=e(fa_digits((string)$o['order_number']))?></span><?php endif; ?>
      <?php if($isCompanion): ?><span class="order-chip companion">همراه حکم</span><?php endif; ?>
    </span>
  </button>
  <?php /* در خروجی PDF، جزئیات هر حکم به‌جای پاپ‌آپ چاپ می‌شود. */
    $oDocs=$orderDocMap[(int)$o['id']]??[]; $oMembers=$orderMemberMap[(int)$o['id']]??[]; ?>
  <div class="print-only order-print-detail">
    <div class="opd-title"><strong><?=e($cardTitle)?></strong><span><?= $isMission?'ماموریتی':'مسئولیتی' ?><?= ($o['order_number']??'')!=='' ? ' · شماره حکم '.e(fa_digits((string)$o['order_number'])) : '' ?></span></div>
    <div class="opd-row"><span>سرحکم: <b><?=e($isCompanion ? ($o['holder_name'] ?? '—') : $r['full_name'])?></b></span><span>کد سازمانی: <b><?=e(fa_digits((string)($isCompanion ? ($o['holder_code'] ?? '') : ($r['organizational_code']??''))) ?: '—')?></b></span><span>عکس حکم: <b><?= $oDocs?'دارد':'ندارد' ?></b></span></div>
    <?php if($isCompanion): ?><div class="opd-row opd-row-wide"><span><b>این عنصر در فهرست همراهان این حکم قرار دارد و سرحکم، شخص دیگری است.</b></span></div><?php endif; ?>
    <div class="opd-row"><span>تاریخ صدور: <b><?=jalali_display($o['order_date'])?:'—'?></b></span><span>تاریخ انقضا: <b><?= $o['end_date']?jalali_display($o['end_date']):'—' ?></b></span><span>مدت (روز): <b><?= !empty($o['duration_days'])?fa_digits((string)$o['duration_days']):'—' ?></b></span></div>
    <?php if($isMission): ?>
    <div class="opd-row"><span>وضعیت سلاح: <b><?= ($o['weapon_status']??'')==='with'?'با اسلحه':((($o['weapon_status']??'')==='without')?'بدون اسلحه':'—') ?></b></span><span>نوع اسلحه: <b><?=e((($o['weapon_status']??'')==='with' ? ($o['weapon_type']??'') : '')?:'—')?></b></span><span>شماره سریال: <b><?=e((($o['weapon_status']??'')==='with' ? fa_digits((string)($o['weapon_serial']??'')) : '')?:'—')?></b></span></div>
    <div class="opd-row"><span>با وسیله نقلیه: <b><?= !empty($o['vehicle_type'])?'بله':'—' ?></b></span><span>نوع وسیله نقلیه: <b><?=e(!empty($o['vehicle_type'])?($vehicleLabels[$o['vehicle_type']]??$o['vehicle_type']):'—')?></b></span><span>به مقصد: <b><?=e(($o['destination']??'')?:'—')?></b></span></div>
    <div class="opd-row opd-row-wide"><span>همراهان: <b><?= $oMembers ? e(implode('، ',$oMembers)) : '—' ?></b></span></div>
    <?php endif; ?>
    <div class="opd-row opd-row-wide"><span>توضیحات: <b><?=e(($o['description']??'')?:'—')?></b></span></div>
  </div>
  <?php endforeach; if(!$orderRows): ?>
  <div class="personnel-empty"><strong>حکمی ثبت نشده است.</strong><span>برای این عنصر هنوز هیچ حکمی در سامانه ثبت نشده است.</span></div>
  <?php endif; ?>
</div>
<?php elseif($tab==='training'): ?>
<div class="panel table-wrap train-table"><table>
  <thead><tr><th class="col-row">ردیف</th><th class="col-date">تاریخ</th><th class="col-course">عنوان دوره</th><th class="col-cert">گواهی</th></tr></thead>
  <tbody>
    <?php $trainRow=0; foreach($trainingRows as $t): $trainRow++; $tDocs=$trainingDocs[(int)$t['id']]??[]; ?>
    <tr>
      <td class="col-row"><?=fa_digits((string)$trainRow)?></td>
      <td class="col-date"><?=jalali_display($t['course_day'])?></td>
      <td class="col-course"><?=e($t['course_name'])?></td>
      <td class="col-cert"><?php if($tDocs): ?><button type="button" class="cert-btn" data-pv-open="certModal" data-course-name="<?=e($t['course_name'])?>" data-cert-docs="<?=e(json_encode($tDocs,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>">مشاهده گواهی</button><?php else: ?><button type="button" class="cert-btn" disabled>گواهی ندارد</button><?php endif; ?></td>
    </tr>
    <?php endforeach; if(!$trainingRows):?><tr><td colspan="4" class="empty">دوره‌ای ثبت نشده است.</td></tr><?php endif;?>
  </tbody>
</table></div>
<?php elseif($tab==='finance'): ?>
<div class="panel table-wrap finance-table"><table><thead><tr><th class="col-row">ردیف</th><th class="col-amount">مبلغ واریزی</th><th class="col-date">تاریخ</th><th class="col-reason">بابت</th><th class="col-kind">تکی/گروهی</th></tr></thead><tbody><?php $financeRow=0; foreach($financeRows as $f): $financeRow++; ?><tr><td class="col-row"><?=fa_digits((string)$financeRow)?></td><td class="col-amount"><strong><?=e(format_amount($f['amount']))?></strong></td><td class="col-date"><?=jalali_display($f['transfer_date'])?></td><td class="col-reason"><?=e($f['reason']?:'—')?></td><td class="col-kind"><span class="kind-chip <?=$f['transfer_type']==='single'?'single':'group'?>"><?=($f['transfer_type']==='single'?'تکی':'گروهی')?></span></td></tr><?php endforeach;if(!$financeRows):?><tr><td colspan="5" class="empty">سابقه مالی ثبت نشده است.</td></tr><?php endif;?></tbody></table></div>
<?php else: ?>
<div class="detail-card">
<div><span>کد ملی</span><strong><?=e($r['national_id']?:'—')?></strong></div>
<div><span>نام پدر</span><strong><?=e($r['father_name']?:'—')?></strong></div>
<div><span>تاریخ تولد</span><strong><?=jalali_display($r['birth_date'])?></strong></div>
<div><span>تحصیلات</span><strong><?=e($edu)?></strong></div>
<div><span>وضعیت تأهل</span><strong><?=e($marital)?></strong></div>
<div><span>عنوان شغلی</span><strong><?=e($r['secondary_job']?:'—')?></strong></div>
<div><span>شماره تماس همراه</span><strong><?=e($r['mobile']?:'—')?></strong></div>
<div><span>شماره تماس اضطراری</span><strong><?=e($r['emergency_phone']?:'—')?></strong></div>
<div><span>شماره شبا</span><strong><?=e($r['iban']?:'—')?></strong></div>
<div class="wide"><span>آدرس محل سکونت</span><strong><?=nl2br(e($r['residence_address']?:'—'))?></strong></div>
<div class="wide"><span>آدرس محل کار</span><strong><?=nl2br(e($r['secondary_job_address']?:'—'))?></strong></div>
</div>
<div class="panel"><div class="section-title">مستندات پرونده</div><div class="doc-list"><?php foreach($docs as $d):?><a class="doc-item" href="personnel_file.php?type=document&id=<?=(int)$d['id']?>" target="_blank"><span>📄</span><div><strong><?=e($d['original_name'])?></strong><small><?=e(document_type_options()[$d['document_type']]??'سایر')?> · <?=number_format($d['file_size']/1024,0)?> KB</small></div></a><?php endforeach;if(!$docs):?><p class="muted">مدرکی ثبت نشده است.</p><?php endif;?></div></div>
<?php endif; ?>
</div>
<?php if($isDismissed): ?><div class="dismissed-profile-stamp" aria-hidden="true"><img src="assets/dismissed-stamp.png" alt="برکنار شد" width="1672" height="941" decoding="sync" fetchpriority="high"></div><?php endif; ?>
</section>
<?php if($tab==='disciplinary' && can_manage_personnel() && !$isDismissed): ?>
<div class="pv-modal" id="dismissModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-pv-close></div>
  <section class="pv-modal-card" role="dialog" aria-modal="true" aria-labelledby="dismissModalTitle">
    <header class="pv-modal-head">
      <div><span class="pv-modal-eyebrow">تغییر وضعیت عنصر</span><h2 id="dismissModalTitle">برکناری عنصر</h2></div>
      <button type="button" class="pv-modal-close" data-pv-close aria-label="بستن">×</button>
    </header>
    <form method="post" action="personnel_dismiss.php" enctype="multipart/form-data" class="pv-modal-form">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="personnel_id" value="<?=(int)$r['id']?>">
      <input type="hidden" name="return_to" value="personnel_view.php?id=<?=(int)$r['id']?>&tab=disciplinary">
      <label class="pv-field"><span>تاریخ برکناری</span><input name="dismissal_date_jalali" class="jalali" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="۱۴۰۷/۰۷/۰۷"></label>
      <label class="pv-field pv-field-wide"><span>علت برکناری</span><input name="reason" maxlength="500" required placeholder="علت برکناری را وارد کنید"></label>
      <div class="document-upload-card pv-upload-card pv-field-wide">
        <div class="document-upload-icon">▣</div>
        <div><strong>اسناد برکناری</strong><small>حداکثر ۵ فایل، هرکدام تا ۸MB</small></div>
        <label class="file-picker"><span data-file-label="انتخاب فایل">انتخاب فایل</span><input type="file" name="dismissal_documents[]" multiple accept="application/pdf,image/jpeg,image/png,image/webp"></label>
      </div>
      <div class="pv-modal-actions">
        <button type="submit" class="btn pv-btn-danger">ثبت</button>
        <button type="button" class="btn secondary" data-pv-close>انصراف</button>
      </div>
    </form>
  </section>
</div>
<div class="pv-modal" id="discModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-pv-close></div>
  <section class="pv-modal-card" role="dialog" aria-modal="true" aria-labelledby="discModalTitle">
    <header class="pv-modal-head">
      <div><span class="pv-modal-eyebrow">پرونده انضباطی</span><h2 id="discModalTitle" data-disc-title-target>ثبت پرونده</h2></div>
      <button type="button" class="pv-modal-close" data-pv-close aria-label="بستن">×</button>
    </header>
    <form method="post" action="disciplinary_store.php" class="pv-modal-form">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="personnel_id" value="<?=(int)$r['id']?>">
      <input type="hidden" name="report_type" value="" data-disc-type-target>
      <label class="pv-field"><span>تاریخ</span><input name="report_date_jalali" class="jalali" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="۱۴۰۷/۰۷/۰۷"></label>
      <label class="pv-field pv-field-wide"><span>بابت</span><input name="reason" maxlength="255" required placeholder="بابت را وارد کنید"></label>
      <label class="pv-field pv-field-wide"><span data-disc-subject-label>نوع</span><input name="subject_title" maxlength="120" required placeholder="نوع را وارد کنید" data-disc-subject-input></label>
      <div class="pv-modal-actions">
        <button type="submit" class="btn pv-btn-success">ثبت</button>
        <button type="button" class="btn secondary" data-pv-close>انصراف</button>
      </div>
    </form>
  </section>
</div>

<?php endif; ?>
<?php if($tab==='training'): ?>
<div class="pv-modal" id="certModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-pv-close></div>
  <section class="pv-modal-card pv-modal-card-wide" role="dialog" aria-modal="true" aria-labelledby="certModalTitle">
    <header class="pv-modal-head">
      <div><span class="pv-modal-eyebrow">گواهی دوره</span><h2 id="certModalTitle" data-course-name-target>گواهی</h2></div>
      <button type="button" class="pv-modal-close" data-pv-close aria-label="بستن">×</button>
    </header>
    <div class="cert-viewer" data-cert-viewer></div>
    <div class="pv-modal-actions"><button type="button" class="btn secondary" data-pv-close>بستن</button></div>
  </section>
</div>
<?php endif; ?>
<script src="<?= e(asset_url('assets/pv-modal.js')) ?>" defer></script>
<?php if($tab==='orders'): ?>
<div class="pv-modal order-modal" id="orderModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-order-close></div>
  <section class="pv-modal-card pv-modal-card-wide" role="dialog" aria-modal="true" aria-labelledby="orderModalTitle">
    <header class="pv-modal-head">
      <div><span class="pv-modal-eyebrow" id="orderModalKind">مشخصات حکم</span><h2 id="orderModalTitle">—</h2></div>
      <button type="button" class="pv-modal-close" data-order-close aria-label="بستن">×</button>
    </header>
    <div class="order-detail" id="orderDetail"></div>
  </section>
</div>
<div class="pv-modal order-members-modal" id="orderMembersModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-members-close></div>
  <section class="pv-modal-card" role="dialog" aria-modal="true" aria-labelledby="orderMembersTitle">
    <header class="pv-modal-head">
      <div><span class="pv-modal-eyebrow">حکم ماموریتی</span><h2 id="orderMembersTitle">همراهان</h2></div>
      <button type="button" class="pv-modal-close" data-members-close aria-label="بستن">×</button>
    </header>
    <div class="order-members-body" id="orderMembersBody"></div>
  </section>
</div>
<script>
window.PERSON_ORDERS = <?= json_encode(array_map(static function($o) use ($orderMemberMap,$orderDocMap,$vehicleLabels,$r){
    $isMission = $o['order_type']==='mission';
    $docs = $orderDocMap[(int)$o['id']] ?? [];
    return [
      'id'        => (int)$o['id'],
      'mission'   => $isMission,
      'kind'      => $isMission ? 'حکم ماموریتی' : 'حکم مسئولیتی',
      'title'     => $isMission
                      ? ((string)(trim((string)($o['purpose']??'')) ?: (trim((string)($o['title']??'')) ?: 'حکم ماموریتی')))
                      : ((string)(trim((string)($o['responsibility_position']??'')) ?: (trim((string)($o['title']??'')) ?: 'حکم مسئولیتی'))),
      'number'    => fa_digits((string)($o['order_number'] ?? '')),
      // اگر این عنصر فقط «همراه» حکم باشد، سرحکم و کد سازمانیِ شخصِ صاحب حکم نمایش داده می‌شود.
      'companion' => (int)($o['personnel_id'] ?? 0) !== (int)$r['id'],
      'holder'    => (string)(((int)($o['personnel_id'] ?? 0) !== (int)$r['id']) ? ($o['holder_name'] ?? '') : ($r['full_name'] ?? '')),
      'orgCode'   => fa_digits((string)(((int)($o['personnel_id'] ?? 0) !== (int)$r['id']) ? ($o['holder_code'] ?? '') : ($r['organizational_code'] ?? ''))),
      'issue'     => $o['order_date'] ? jalali_display($o['order_date']) : '',
      'expiry'    => $o['end_date'] ? jalali_display($o['end_date']) : '',
      'days'      => !empty($o['duration_days']) ? fa_digits((string)$o['duration_days']) : '',
      'weapon'    => (string)($o['weapon_status'] ?? ''),
      'weaponType'=> (string)($o['weapon_type'] ?? ''),
      'serial'    => fa_digits((string)($o['weapon_serial'] ?? '')),
      'vehicle'   => isset($o['vehicle_type']) && $o['vehicle_type'] ? (string)($vehicleLabels[$o['vehicle_type']] ?? $o['vehicle_type']) : '',
      'dest'      => (string)($o['destination'] ?? ''),
      'note'      => (string)($o['description'] ?? ''),
      'members'   => array_values($orderMemberMap[(int)$o['id']] ?? []),
      'photo'     => $docs ? ['id'=>(int)$docs[0]['id'],'name'=>(string)$docs[0]['original_name'],'mime'=>(string)$docs[0]['mime_type']] : null,
    ];
}, $orderRows), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
</script>
<script>
(function(){
  var ORDERS = window.PERSON_ORDERS || [];
  var modal   = document.getElementById('orderModal');
  var mModal  = document.getElementById('orderMembersModal');
  if(!modal) return;
  var detail  = document.getElementById('orderDetail');
  var titleEl = document.getElementById('orderModalTitle');
  var kindEl  = document.getElementById('orderModalKind');
  var mBody   = document.getElementById('orderMembersBody');
  var EMPTY   = '—';
  function faDigits(v){ return String(v).replace(/[0-9]/g,function(d){ return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); }

  function esc(v){ return String(v==null?'':v).replace(/[<>&"]/g,function(c){
    return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]; }); }

  function row(cells){
    return '<div class="order-row">'+cells.map(function(c){
      return '<div class="order-cell"><span>'+esc(c[0])+'</span><strong'+(c[1]?'':' class="is-empty"')+'>'+
             esc(c[1]||EMPTY)+'</strong></div>'; }).join('')+'</div>';
  }

  function render(o){
    kindEl.textContent  = o.kind;
    titleEl.textContent = o.title || o.kind;

    var html = '';

    /* ۱) سرحکم، کد سازمانی و عکس حکم */
    html += '<div class="order-row order-row-head">'+
              '<div class="order-cell"><span>سرحکم</span><strong>'+esc(o.holder||EMPTY)+'</strong></div>'+
              '<div class="order-cell"><span>کد سازمانی</span><strong'+(o.orgCode?'':' class="is-empty"')+'>'+esc(o.orgCode||EMPTY)+'</strong></div>'+
              '<div class="order-cell order-cell-action">'+
                (o.photo
                  ? '<button type="button" class="order-btn" data-order-photo="'+o.photo.id+'">مشاهده عکس حکم</button>'
                  : '<button type="button" class="order-btn" disabled>عکس حکم ندارد</button>')+
              '</div>'+
            '</div>';

    /* ۲) تاریخ‌ها */
    html += row([['تاریخ صدور',o.issue],['تاریخ انقضا',o.expiry],['مدت (روز)',o.days]]);

    if(o.mission){
      /* ۳) سلاح */
      html += row([
        ['وضعیت سلاح', o.weapon==='with' ? 'با اسلحه' : (o.weapon==='without' ? 'بدون اسلحه' : '')],
        ['نوع اسلحه',  o.weapon==='with' ? o.weaponType : ''],
        ['شماره سریال',o.weapon==='with' ? o.serial     : '']
      ]);
      /* ۴) وسیله نقلیه و مقصد */
      html += row([
        ['با وسیله نقلیه', o.vehicle ? 'بله' : ''],
        ['نوع وسیله نقلیه', o.vehicle],
        ['به مقصد', o.dest]
      ]);
      /* ۵) همراهان */
      html += '<div class="order-row order-row-single">'+
                '<button type="button" class="order-btn order-btn-wide" data-order-members="'+o.id+'"'+
                (o.members.length?'':' disabled')+'>'+
                (o.members.length ? 'همراهان ('+faDigits(o.members.length)+' نفر)' : 'همراهی ثبت نشده است')+
                '</button></div>';
    }

    /* ۶) توضیحات — برای همراهان، یادداشت وضعیت هم اینجا می‌آید */
    var COMPANION_NOTE='این عنصر در فهرست همراهان این حکم قرار دارد و سرحکم، شخص دیگری است'+
      (o.holder ? (' («'+o.holder+'»)') : '')+'.';
    html += '<div class="order-row order-row-single">'+
              '<div class="order-cell order-cell-wide"><span>توضیحات</span>'+
              (o.companion ? '<strong class="order-note-companion">'+esc(COMPANION_NOTE)+'</strong>' : '')+
              '<strong'+(o.note?'':' class="is-empty"')+'>'+esc(o.note||EMPTY)+'</strong></div></div>';

    detail.innerHTML = html;
    detail.dataset.orderId = o.id;
  }

  function openModal(el){ el.classList.add('open'); el.setAttribute('aria-hidden','false'); document.body.classList.add('modal-open'); }
  function closeModal(el){ el.classList.remove('open'); el.setAttribute('aria-hidden','true');
    if(!document.querySelector('.pv-modal.open')) document.body.classList.remove('modal-open'); }

  document.querySelectorAll('.order-card').forEach(function(card){
    card.addEventListener('click',function(){
      var id=parseInt(card.dataset.order,10);
      var o=ORDERS.filter(function(x){ return x.id===id; })[0];
      if(!o) return;
      render(o); openModal(modal);
    });
  });

  detail.addEventListener('click',function(ev){
    var pBtn=ev.target.closest('[data-order-photo]');
    if(pBtn && !pBtn.disabled){ window.open('personnel_file.php?type=order&id='+pBtn.dataset.orderPhoto,'_blank','noopener'); return; }
    var mBtn=ev.target.closest('[data-order-members]');
    if(mBtn && !mBtn.disabled){
      var id=parseInt(mBtn.dataset.orderMembers,10);
      var o=ORDERS.filter(function(x){ return x.id===id; })[0];
      mBody.innerHTML = (o && o.members.length)
        ? '<ol class="order-members-list">'+o.members.map(function(n){ return '<li>'+esc(n)+'</li>'; }).join('')+'</ol>'
        : '<div class="cert-empty">همراهی ثبت نشده است.</div>';
      openModal(mModal);
    }
  });

  document.querySelectorAll('[data-order-close]').forEach(function(b){ b.addEventListener('click',function(){ closeModal(modal); }); });
  document.querySelectorAll('[data-members-close]').forEach(function(b){ b.addEventListener('click',function(){ closeModal(mModal); }); });
  document.addEventListener('keydown',function(ev){
    if(ev.key!=='Escape') return;
    if(mModal.classList.contains('open')) closeModal(mModal);
    else if(modal.classList.contains('open')) closeModal(modal);
  });
})();
</script>
<?php endif; ?>
<?php if($tab==='equipment'): ?>
<div class="pv-modal equip-modal" id="equipModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-equip-close></div>
  <section class="pv-modal-card" role="dialog" aria-modal="true" aria-labelledby="equipModalTitle">
    <header class="pv-modal-head">
      <div><span class="pv-modal-eyebrow">مشخصات تجهیز</span><h2 id="equipModalTitle">—</h2></div>
      <button type="button" class="pv-modal-close" data-equip-close aria-label="بستن">×</button>
    </header>
    <div class="equip-detail" id="equipDetail"></div>
  </section>
</div>
<script>
window.EQUIPMENTS = <?= json_encode(array_map(static function($eq){
    return [
      'type'     => (string)$eq['equipment_type'],
      'serial'   => (string)($eq['serial_number'] ?? ''),
      'model'    => (string)($eq['model'] ?? ''),
      'color'    => (string)($eq['color'] ?? ''),
      'plate'    => (string)($eq['plate'] ?? ''),
      'delivery' => $eq['delivery_date'] ? jalali_display($eq['delivery_date']) : '',
      'return'   => $eq['return_date'] ? jalali_display($eq['return_date']) : '',
      'notes'    => (string)($eq['notes'] ?? ''),
    ];
}, $resourceEquipments), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
</script>
<script>
(function(){
  var items = window.EQUIPMENTS || [];
  var modal = document.getElementById('equipModal');
  if (!modal) return;
  if (modal.parentElement !== document.body) document.body.appendChild(modal);

  function esc(v){ return String(v==null?'':v).replace(/[<>&"]/g,function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c];}); }
  function row(label,value){ return '<div class="equip-line"><span>'+esc(label)+'</span><strong>'+(value?esc(value):'—')+'</strong></div>'; }

  // کادرها بسته به نوع تجهیز فرق می‌کنند: خودرو/موتور پلاک و رنگ دارد، سلاح سریال.
  var VEHICLE = ['خودرو','موتور سیکلت','موتورسیکلت'];
  var WEAPON  = ['سلاح','شوکر','افشانه'];
  function fieldsFor(it){
    var html = row('نوع تجهیز', it.type);
    if (VEHICLE.indexOf(it.type) !== -1) {
      html += row('مدل', it.model) + row('رنگ', it.color) + row('شماره پلاک', it.plate);
      if (it.serial) html += row('شماره سریال', it.serial);
    } else if (WEAPON.indexOf(it.type) !== -1) {
      html += row('شماره سریال', it.serial);
      if (it.model) html += row('مدل', it.model);
    } else {
      if (it.serial) html += row('شماره سریال', it.serial);
      if (it.model)  html += row('مدل', it.model);
      if (it.color)  html += row('رنگ', it.color);
      if (it.plate)  html += row('شماره پلاک', it.plate);
      if (!it.serial && !it.model && !it.color && !it.plate) html += row('شماره سریال', '');
    }
    html += row('تاریخ تحویل', it.delivery);
    html += row('تاریخ بازتحویل', it.return);
    html += row('وضعیت', it.return ? 'بازگردانده شده' : 'در اختیار عنصر');
    if (it.notes) html += row('توضیحات', it.notes);
    return html;
  }
  function open(i){
    var it = items[i]; if(!it) return;
    document.getElementById('equipModalTitle').textContent = it.type;
    document.getElementById('equipDetail').innerHTML = fieldsFor(it);
    modal.classList.add('open'); modal.setAttribute('aria-hidden','false'); document.body.classList.add('modal-open');
  }
  function close(){ modal.classList.remove('open'); modal.setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open'); }
  document.querySelectorAll('.equip-view').forEach(function(btn){
    btn.addEventListener('click', function(){ open(parseInt(btn.dataset.equip,10)); });
  });
  modal.querySelectorAll('[data-equip-close]').forEach(function(el){ el.addEventListener('click', close); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });
})();
</script>
<?php endif; ?>
<?php require __DIR__.'/../app/partials/footer.php'; ?>
