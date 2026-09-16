<?php
require __DIR__.'/../app/bootstrap.php'; require_login();
$where=[]; $params=[];
$allowed=allowed_units();
if(count($allowed)<2){$place=implode(',',array_fill(0,count($allowed),'?'));$where[]="ct.category_key IN ($place)";$params=array_merge($params,$allowed);}
$q=fa_to_en_digits(trim($_GET['q']??'')); if($q!==''){ $where[]='(p.full_name LIKE ? OR p.national_id LIKE ? OR i.iban LIKE ? OR i.beneficiary_name LIKE ? OR t.reason LIKE ?)'; $like="%$q%"; array_push($params,$like,$like,$like,$like,$like); }
$sql='SELECT t.id,t.transfer_type,t.transfer_date,t.reason,t.note,t.created_at,p.id AS personnel_id,p.full_name,p.national_id,ct.category_key AS unit,i.amount,i.iban,i.beneficiary_name FROM financial_transactions t JOIN financial_transaction_items i ON i.transaction_id=t.id JOIN personnel p ON p.id=i.personnel_id JOIN category_types ct ON ct.id=p.category_type_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY t.transfer_date DESC,t.id DESC,i.id ASC';
$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
$total=0; foreach($rows as $r)$total+=(float)$r['amount'];
require __DIR__.'/../app/partials/header.php'; ?>
<?php $printTitle='گزارش وضعیت مالی عناصر'; $printLandscape=true; require __DIR__.'/../app/partials/print_frame.php'; ?>
<section class="page-head"><div><h1>مدیریت مالی</h1></div><div class="report-actions"><button class="btn secondary" type="button" onclick="window.print()">دریافت PDF</button><?php if(can_manage_finance()):?><a class="btn primary" href="finance_form.php">+ ثبت واریز</a><?php endif;?></div></section>
<form class="filters" method="get"><input name="q" placeholder="جستجو نام، کد ملی، شبا یا بابت" value="<?=e($q)?>"><button class="btn secondary">جستجو</button></form>
<div class="cards compact-cards"><div class="stat-card"><span>تعداد ردیف‌های واریز</span><strong><?=number_format(count($rows))?></strong></div><div class="stat-card"><span>مجموع مبالغ</span><strong><?=e(format_amount($total))?></strong></div></div>
<div class="panel table-wrap print-list finance-table finance-table-wide"><table><thead><tr><th class="col-row">ردیف</th><th class="col-person">فرد</th><th class="col-amount">مبلغ واریزی</th><th class="col-date">تاریخ</th><th class="col-reason">بابت</th><th class="col-kind">تکی/گروهی</th><th class="col-iban">شماره شبا</th><th class="col-holder">به نام</th></tr></thead><tbody><?php $financeRow=0; foreach($rows as $r): $financeRow++; ?><tr><td class="col-row"><?=fa_digits((string)$financeRow)?></td><td class="col-person"><a href="personnel_view.php?id=<?=(int)$r['personnel_id']?>"><strong><?=e($r['full_name'])?></strong></a></td><td class="col-amount"><strong><?=e(format_amount($r['amount']))?></strong></td><td class="col-date"><?=jalali_display($r['transfer_date'])?></td><td class="col-reason"><?=e($r['reason']?:'—')?></td><td class="col-kind"><span class="kind-chip <?=$r['transfer_type']==='single'?'single':'group'?>"><?=$r['transfer_type']==='single'?'تکی':'گروهی'?></span></td><td class="col-iban"><?=e($r['iban']?:'—')?></td><td class="col-holder"><?=e($r['beneficiary_name']?:'—')?></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="8" class="empty">هیچ سابقه واریزی پیدا نشد.</td></tr><?php endif;?></tbody></table></div>
<?php require __DIR__.'/../app/partials/footer.php'; ?>


