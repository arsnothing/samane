<?php
require __DIR__.'/../app/bootstrap.php'; require_login();

$hasPersonStatusCol=false;
try { $hasPersonStatusCol=(bool)$pdo->query("SHOW COLUMNS FROM personnel_equipment LIKE 'status'")->fetch(); } catch (Throwable $e) { $hasPersonStatusCol=false; }

/* پاپ‌آپ پروفایل، فهرست زنده‌ی تجهیزات همان لحظه‌ی شخص را با fetch از همین نقطه می‌خواند. */
if(($_GET['action']??'')==='person_equipment'){
 header('Content-Type: application/json; charset=utf-8');
 $pid=(int)($_GET['id']??0);
 try{
  $st=$pdo->prepare('SELECT p.id,p.full_name,p.mobile,ct.category_key AS unit FROM personnel p JOIN category_types ct ON ct.id=p.category_type_id WHERE p.id=?');
  $st->execute([$pid]); $person=$st->fetch();
  if(!$person || !can_view_unit($person['unit'])){ http_response_code(404); echo json_encode(['error'=>'not_found']); exit; }
  $items=[];
  if($hasPersonStatusCol){
   $st2=$pdo->prepare('SELECT id,equipment_type,serial_number,model,color,plate,status FROM personnel_equipment WHERE personnel_id=? ORDER BY created_at DESC');
   $st2->execute([$pid]); $items=$st2->fetchAll();
  }
  echo json_encode(['person'=>['id'=>(int)$person['id'],'name'=>$person['full_name'],'mobile'=>$person['mobile']],'items'=>$items,'has_status'=>$hasPersonStatusCol],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 }catch(Throwable $e){ http_response_code(500); echo json_encode(['error'=>'failed']); }
 exit;
}

$statusOptions = equipment_status_options();
$personToggleOptions = ['healthy'=>$statusOptions['healthy'],'needs_repair'=>$statusOptions['needs_repair']];
$repairQueueOptions  = ['repaired'=>$statusOptions['repaired'],'in_repair'=>$statusOptions['in_repair'],'needs_repair'=>$statusOptions['needs_repair']];
$error=''; $success='';

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='update_person_status'){
 verify_csrf();
 if(!can_manage_personnel()){http_response_code(403);exit('دسترسی غیرمجاز');}
 if(!$hasPersonStatusCol){ $error='ساختار دیتابیس نیاز به به‌روزرسانی دارد. فایل database/upgrade_v5.3.sql را اجرا کنید.'; }
 else{
  $pid=(int)($_POST['personnel_id']??0);
  $selected=$_POST['selected']??[]; $statuses=$_POST['status']??[];
  if(!$pid || !is_array($selected) || !$selected) $error='حداقل یک تجهیز را انتخاب کنید.';
  else{
   $st=$pdo->prepare('SELECT p.id,ct.category_key AS unit FROM personnel p JOIN category_types ct ON ct.id=p.category_type_id WHERE p.id=?'); $st->execute([$pid]); $person=$st->fetch();
   if(!$person || !can_view_unit($person['unit'])) $error='این عنصر خارج از محدوده دسترسی شماست.';
   else{
    $upd=$pdo->prepare('UPDATE personnel_equipment SET status=?,updated_by=? WHERE id=? AND personnel_id=?');
    $count=0;
    foreach($selected as $eid){
     $eid=(int)$eid; $status=(string)($statuses[$eid]??'');
     if(!$eid || !isset($personToggleOptions[$status])) continue;
     $upd->execute([$status,user()['id'],$eid,$pid]); $count++;
    }
    if(!$count) $error='وضعیت معتبری برای اقلام انتخاب‌شده ثبت نشد.';
    else $success=fa_digits((string)$count).' مورد با موفقیت به‌روزرسانی شد.';
   }
  }
 }
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='update_repair_status'){
 verify_csrf();
 if(!can_manage_personnel()){http_response_code(403);exit('دسترسی غیرمجاز');}
 if(!$hasPersonStatusCol){ $error='ساختار دیتابیس نیاز به به‌روزرسانی دارد. فایل database/upgrade_v5.3.sql را اجرا کنید.'; }
 else{
  $id=(int)($_POST['id']??0); $status=(string)($_POST['status']??'');
  if(!$id || !isset($repairQueueOptions[$status])) $error='وضعیت انتخاب‌شده معتبر نیست.';
  else{ $pdo->prepare('UPDATE personnel_equipment SET status=?,updated_by=? WHERE id=?')->execute([$status,user()['id'],$id]); $success='وضعیت تعمیر به‌روزرسانی شد.'; }
 }
}

$people=$pdo->query("SELECT p.id,p.full_name,p.national_id,p.mobile,ct.category_key AS unit FROM personnel p JOIN category_types ct ON ct.id=p.category_type_id WHERE ".active_personnel_sql('p')." ORDER BY p.full_name")->fetchAll();

$repairQueue=[];
if($hasPersonStatusCol){
 $rq=$pdo->query("SELECT pe.*, p.full_name, ct.category_key AS unit FROM personnel_equipment pe JOIN personnel p ON p.id=pe.personnel_id JOIN category_types ct ON ct.id=p.category_type_id WHERE pe.status IN ('needs_repair','in_repair') ORDER BY pe.updated_at DESC")->fetchAll();
 foreach($rq as $r){ if(can_view_unit($r['unit'])) $repairQueue[]=$r; }
}

require __DIR__.'/../app/partials/header.php'; ?>
<section class="page-head equipment-head"><div><h1>آماد</h1><p>ثبت اقلام و تجهیزات، گزارش وضعیت آمادی و بازتحویل</p></div></section>
<div class="training-tabs">
  <a class="training-tab" href="equipment.php">ثبت آماد</a>
  <a class="training-tab" href="equipment_status.php">گزارش وضعیت آمادی</a>
  <a class="training-tab active" href="equipment_return.php">ثبت بازتحویل</a>
</div>
<?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?><?php if($error):?><div class="alert danger"><?=e($error)?></div><?php endif;?>
<?php if(!$hasPersonStatusCol): ?>
<div class="alert danger">ساختار دیتابیس نیاز به به‌روزرسانی دارد. فایل database/upgrade_v5.3.sql را روی پایگاه داده اجرا کنید تا ثبت بازتحویل فعال شود.</div>
<?php else: ?>
<div class="panel">
  <div class="section-title">ثبت بازتحویل</div>
  <div class="order-person-picker wide">
    <div class="order-person-search">
      <input type="search" id="returnSearch" class="filter-control filter-search" placeholder="جستجو با کد ملی یا نام و نام خانوادگی" autocomplete="off">
    </div>
    <div class="order-person-list" id="returnPersonList">
      <?php foreach($people as $p): if(can_view_unit($p['unit'])): ?>
      <button type="button" class="op-item" data-search="<?=e(mb_strtolower($p['full_name'].' '.$p['national_id'],'UTF-8'))?>" data-id="<?=(int)$p['id']?>">
        <span class="op-name"><?=e($p['full_name'])?></span>
        <span class="op-nid"><?=e(fa_digits((string)$p['national_id']))?></span>
      </button>
      <?php endif; endforeach; ?>
    </div>
    <div class="order-person-empty" id="returnPersonEmpty" hidden>موردی با این جستجو پیدا نشد.</div>
  </div>
</div>

<div class="panel">
  <div class="section-title">صف تعمیرات</div>
  <?php if(!$repairQueue): ?>
  <p class="empty">در حال حاضر آمادی در صف تعمیر نیست.</p>
  <?php else: ?>
  <div class="repair-queue wide">
    <?php foreach($repairQueue as $r): ?>
    <div class="repair-row">
      <div class="repair-info">
        <strong><?=e($r['full_name'])?></strong>
        <span><?=e($r['equipment_type'])?><?php if($r['serial_number']||$r['plate']):?> — <?=e($r['plate']?:$r['serial_number'])?><?php endif;?></span>
      </div>
      <form method="post" data-auto-filter class="repair-status-form">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="update_repair_status">
        <input type="hidden" name="id" value="<?=(int)$r['id']?>">
        <select name="status">
          <?php foreach($repairQueueOptions as $sk=>$sv):?><option value="<?=e($sk)?>" <?=$r['status']===$sk?'selected':''?>><?=e($sv)?></option><?php endforeach;?>
        </select>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="pv-modal return-modal" id="returnModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-return-close></div>
  <section class="pv-modal-card pv-modal-card-wide" role="dialog" aria-modal="true" aria-labelledby="returnModalTitle">
    <header class="pv-modal-head">
      <div><span class="pv-modal-eyebrow">ثبت بازتحویل</span><h2 id="returnModalTitle">—</h2></div>
      <button type="button" class="pv-modal-close" data-return-close aria-label="بستن">×</button>
    </header>
    <div class="equip-return-head">
      <div class="equip-return-person"><strong id="returnPersonName">—</strong><span id="returnPersonMobile"></span></div>
      <div class="avatar-frame equip-return-avatar"><img class="avatar" id="returnPersonPhoto" onerror="this.hidden=true;this.nextElementSibling.hidden=false;"><div class="avatar placeholder" id="returnPersonPhotoPh" hidden>—</div></div>
    </div>
    <form method="post" id="returnForm">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="update_person_status">
      <input type="hidden" name="personnel_id" id="returnPersonId">
      <div id="returnItems"></div>
      <div class="pv-modal-actions equip-return-actions"><button class="btn primary" type="submit">ثبت</button><button class="btn secondary" type="button" data-return-close>انصراف</button></div>
    </form>
  </section>
</div>
<script>
(function(){
 var STATUS_OPTIONS=<?=json_encode($personToggleOptions,JSON_UNESCAPED_UNICODE)?>;
 var search=document.getElementById('returnSearch');
 var list=document.getElementById('returnPersonList');
 var empty=document.getElementById('returnPersonEmpty');
 var items=list?[...list.querySelectorAll('.op-item')]:[];
 var modal=document.getElementById('returnModal');
 if(modal && modal.parentElement!==document.body) document.body.appendChild(modal);

 var faFold=function(v){return String(v==null?'':v).replace(/[۰-۹٠-٩]/g,function(d){var i='۰۱۲۳۴۵۶۷۸۹'.indexOf(d);return i>-1?String(i):String('٠١٢٣٤٥٦٧٨٩'.indexOf(d));}).toLocaleLowerCase('fa-IR');};

 function filterList(){
  var q=faFold((search.value||'').trim()); var shown=0;
  items.forEach(function(el){ var hit=!q||faFold(el.dataset.search||'').indexOf(q)!==-1; el.hidden=!hit; if(hit) shown++; });
  if(empty) empty.hidden = shown!==0;
 }
 search&&search.addEventListener('input',filterList);

 function unitLabel(it){ return it.plate?('پلاک: '+it.plate):(it.serial_number?('سریال: '+it.serial_number):'—'); }

 function renderItems(items){
  var box=document.getElementById('returnItems');
  if(!items.length){ box.innerHTML='<p class="empty">تجهیزی برای این عنصر ثبت نشده است.</p>'; return; }
  var html='';
  items.forEach(function(it){
   var def=(it.status==='needs_repair'||it.status==='in_repair')?'needs_repair':'healthy';
   var opts='';
   Object.keys(STATUS_OPTIONS).forEach(function(k){ opts+='<option value="'+k+'"'+(k===def?' selected':'')+'>'+STATUS_OPTIONS[k]+'</option>'; });
   html+='<div class="equip-return-row">'+
     '<label class="equip-return-check"><input type="checkbox" name="selected[]" value="'+it.id+'"></label>'+
     '<div class="equip-return-info"><strong>'+esc(it.equipment_type)+'</strong><span>'+esc(unitLabel(it))+(it.model?(' — '+esc(it.model)):'')+'</span></div>'+
     '<div class="equip-return-status"><select name="status['+it.id+']">'+opts+'</select></div>'+
   '</div>';
  });
  box.innerHTML=html;
 }
 function esc(v){ return String(v==null?'':v).replace(/[<>&"]/g,function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c];}); }

 function openModal(id){
  fetch('equipment_return.php?action=person_equipment&id='+encodeURIComponent(id),{headers:{'Accept':'application/json'}})
   .then(function(r){return r.json();})
   .then(function(data){
    if(data.error) return;
    document.getElementById('returnModalTitle').textContent=data.person.name;
    document.getElementById('returnPersonName').textContent=data.person.name;
    document.getElementById('returnPersonMobile').textContent=data.person.mobile||'—';
    document.getElementById('returnPersonId').value=data.person.id;
    document.getElementById('returnPersonPhoto').src='personnel_file.php?type=photo&id='+encodeURIComponent(data.person.id)+'&v='+Date.now();
    document.getElementById('returnPersonPhoto').hidden=false;
    document.getElementById('returnPersonPhotoPh').hidden=true;
    renderItems(data.items||[]);
    modal.classList.add('open'); modal.setAttribute('aria-hidden','false'); document.body.classList.add('modal-open');
   });
 }
 function closeModal(){ modal.classList.remove('open'); modal.setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open'); }
 list&&list.addEventListener('click',function(e){ var btn=e.target.closest('.op-item'); if(!btn) return; openModal(btn.dataset.id); });
 modal&&modal.querySelectorAll('[data-return-close]').forEach(function(el){ el.addEventListener('click',closeModal); });
 document.addEventListener('keydown',function(e){ if(e.key==='Escape' && modal && modal.classList.contains('open')) closeModal(); });
})();
</script>
<?php endif; ?>
<script src="<?= e(asset_url('assets/filter-bar.js')) ?>" defer></script>
<?php require __DIR__.'/../app/partials/footer.php'; ?>
