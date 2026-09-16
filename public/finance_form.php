<?php
require __DIR__.'/../app/bootstrap.php'; require_login();
if(!can_manage_finance()){http_response_code(403);exit('دسترسی غیرمجاز');}
$people=$pdo->query("SELECT p.id,p.full_name,p.national_id,ct.category_key AS unit,p.iban,p.iban_holder_name FROM personnel p JOIN category_types ct ON ct.id=p.category_type_id WHERE ".active_personnel_sql('p')." ORDER BY p.full_name")->fetchAll();
$selected=(int)($_GET['personnel_id']??0);$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $type=$_POST['transfer_type']??'single'; $jalaliDate=trim($_POST['transfer_date_jalali']??''); $date=null; if(($parsed=parse_jalali_input($jalaliDate))!==null) $date=$parsed; $reason=trim($_POST['reason']??''); $note=trim($_POST['note']??'');
    $selectedIds=$_POST['personnel_ids']??[]; $singleId=(int)($_POST['personnel_id']??0);
    if(!in_array($type,['single','group'],true)||$reason==='') $error='تکی/گروهی و بابت را تکمیل کنید.';
    if($type==='single') $selectedIds=$singleId?[$singleId]:[];
    $selectedIds=array_values(array_unique(array_map('intval',(array)$selectedIds)));
    if(!$error && !$date) $error='تاریخ واریز    را به صورت صحیح وارد کنید.';
    if(!$error&&count($selectedIds)===0) $error='حداقل یک نفر را انتخاب کنید.';
    $items=[];
    if(!$error){
        $place=implode(',',array_fill(0,count($selectedIds),'?'));
        $st=$pdo->prepare("SELECT p.id,p.full_name,p.iban,p.iban_holder_name,ct.category_key AS unit FROM personnel p JOIN category_types ct ON ct.id=p.category_type_id WHERE p.id IN ($place) AND ".active_personnel_sql('p')); $st->execute($selectedIds);
        $map=[]; foreach($st->fetchAll() as $p) $map[(int)$p['id']]=$p;
        if(count($map)!==count($selectedIds)) $error='یکی از افراد انتخاب‌ شده پیدا نشد.';
        foreach($selectedIds as $pid){
            if($error) break;
            $amount=(float)digits_only($_POST['amount'][$pid]??'0');
            $ibanDigits=digits_only($_POST['iban_digits'][$pid]??'');
            $iban=$ibanDigits!=='' ? 'IR'.$ibanDigits : strtoupper(preg_replace('/\s+/', '',trim($_POST['iban'][$pid]??($map[$pid]['iban']??''))));
            // نام صاحب حساب از پرونده خود عنصر برداشته می‌شود و در فرم پرسیده نمی‌شود.
            $holder=trim((string)($map[$pid]['iban_holder_name']??'')) ?: trim((string)($map[$pid]['full_name']??''));
            if(!isset($map[$pid]) || $amount<=0 || !preg_match('/^IR\d{24}$/',$iban)){ $error='برای همه افراد، مبلغ معتبر و شماره شبای ۲۴ رقمی را وارد کنید.'; break; }
            if(!can_view_unit($map[$pid]['unit'])){$error='یکی از افراد انتخاب‌ شده خارج از محدوده دسترسی شماست.';break;}
            $items[]=[$pid,$amount,$iban,$holder];
        }
    }
    if(!$error){
        try{
            $pdo->beginTransaction();
            $st=$pdo->prepare('INSERT INTO financial_transactions(transfer_type,transfer_date,reason,note,created_by,updated_by) VALUES(?,?,?,?,?,?)');
            $st->execute([$type,$date,$reason,$note,user()['id'],user()['id']]);
            $tid=(int)$pdo->lastInsertId();
            $st=$pdo->prepare('INSERT INTO financial_transaction_items(transaction_id,personnel_id,amount,iban,beneficiary_name,item_note) VALUES(?,?,?,?,?,?)');
            foreach($items as $it) $st->execute([$tid,$it[0],$it[1],$it[2],$it[3],null]);
            $pdo->commit(); redirect('finance_form.php?success=1');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error='ثبت واریز انجام نشد.';}
    }
}
require __DIR__.'/../app/partials/header.php';
?>
<section class="page-head"><div><h1>ثبت واریز</h1></div></section>
<?php if(isset($_GET['success'])):?><div class="alert success">واریز با موفقیت ثبت شد.</div><?php endif;?>
<?php if($error):?><div class="alert danger"><?=e($error)?></div><?php endif;?>
<form method="post" class="panel form-grid" id="financeForm">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<label>تکی/گروهی<select name="transfer_type" id="transfer_type"><option value="single">واریز تکی</option><option value="group">واریز گروهی</option></select></label>
<label>تاریخ واریز   <input name="transfer_date_jalali" class="jalali" inputmode="numeric" maxlength="10" placeholder="۱۴۰۷/۰۷/۰۷" value="<?=e($_POST['transfer_date_jalali']??'')?>" autocomplete="off" required></label>
<div class="section-title wide">انتخاب  فرد / افراد </div>
<div id="singleBox" class="wide"><label>فرد<select name="personnel_id" id="personnel_id" required><option value="" disabled hidden selected>انتخاب فرد</option><?php foreach($people as $p):?><option value="<?=$p['id']?>" <?=$selected===$p['id']?'selected':''?>><?=e($p['full_name'])?> — <?=e(unit_label($p['unit']))?> — <?=e($p['national_id'])?></option><?php endforeach;?></select></label></div>
<div id="groupBox" class="wide" style="display:none"><label>افراد<select name="personnel_ids[]" id="personnel_ids" multiple size="8"><?php foreach($people as $p):?><option value="<?=$p['id']?>"><?=e($p['full_name'])?> — <?=e(unit_label($p['unit']))?> — <?=e($p['national_id'])?></option><?php endforeach;?></select><small>برای واریز گروهی چند نفر را انتخاب کنید.</small></label></div>
<div id="paymentItems" class="wide"></div>
<label class="wide">بابت <input name="reason" required value="<?=e($_POST['reason']??'')?>"></label>
<label class="wide">توضیحات<textarea name="note" rows="3"><?=e($_POST['note']??'')?></textarea></label>
<div class="actions wide"><button class="btn primary" type="submit">ثبت</button><a class="btn secondary" href="dashboard.php">انصراف</a></div>
</form>
<script>
const people=<?=json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>$p['full_name'],'iban'=>$p['iban']??'','holder'=>$p['iban_holder_name']??'','unit'=>unit_label($p['unit'])],$people),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const typeEl=document.getElementById('transfer_type'),singleBox=document.getElementById('singleBox'),groupBox=document.getElementById('groupBox'),singleEl=document.getElementById('personnel_id'),groupEl=document.getElementById('personnel_ids'),items=document.getElementById('paymentItems');
function personById(id){return people.find(p=>p.id===Number(id));}
function row(p){const digits=(p.iban||'').replace(/^IR/i,'');return `<div class="payment-row"><div><strong>${p.name}</strong><small>${p.unit}</small></div><input required type="text" inputmode="numeric" pattern="[0-9۰-۹]+" name="amount[${p.id}]" placeholder="مبلغ به ریال"><div class="iban-input"><span class="iban-prefix">IR</span><input required maxlength="24" name="iban_digits[${p.id}]" inputmode="numeric" pattern="[0-9۰-۹]{24}" value="${digits}" placeholder="۲۴ رقم"><input type="hidden" name="iban[${p.id}]" value="${p.iban||'IR'}"></div></div>`;}
function sync(){const group=typeEl.value==='group';singleBox.style.display=group?'none':'block';groupBox.style.display=group?'block':'none';singleEl.disabled=group;groupEl.disabled=!group;items.innerHTML='';const ids=group?[...groupEl.selectedOptions].map(o=>o.value):[singleEl.value].filter(Boolean);ids.forEach(id=>{const p=personById(id);if(p)items.insertAdjacentHTML('beforeend',row(p));});}
document.addEventListener('input',e=>{
  if(e.target.matches('input[name^="iban_digits"]')){
    const digits=(e.target.value||'').replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/\D/g,'').slice(0,24);
    e.target.value=digits.replace(/[0-9]/g,d=>'۰۱۲۳۴۵۶۷۸۹'[+d]);   // نمایش فارسی
    const hidden=e.target.parentElement.querySelector('input[name^="iban["]');
    if(hidden) hidden.value='IR'+digits;                            // مقدار ارسالی لاتین
  }
});
typeEl.addEventListener('change',sync);singleEl.addEventListener('change',sync);groupEl.addEventListener('change',sync);sync();
</script>
<?php require __DIR__.'/../app/partials/footer.php';?>
