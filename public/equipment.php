<?php
require __DIR__.'/../app/bootstrap.php'; require_login();
$equipmentTypes = equipment_type_options();
$vehicleTypes   = equipment_vehicle_types();
$error=''; $success='';

/** آیا جدول انبار آماد روی این دیتابیس ساخته شده؟ تا وقتی database/upgrade_v5.3.sql اجرا نشده، فرم غیرفعال می‌ماند. */
$hasStockTable=false;
try { $hasStockTable=(bool)$pdo->query("SHOW TABLES LIKE 'equipment_stock'")->fetch(); } catch (Throwable $e) { $hasStockTable=false; }

function normalize_plate(?string $v): string {return str_replace([' ','‌'],'',trim((string)$v));}
function valid_iran_plate(string $p): bool {return (bool)preg_match('/^\d{2}[\p{L}]{1}\d{3}-?\d{2}$/u',$p);}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add_stock'){
 verify_csrf();
 if(!can_manage_personnel()){http_response_code(403);exit('دسترسی غیرمجاز');}
 if(!$hasStockTable){ $error='ساختار دیتابیس نیاز به به‌روزرسانی دارد. فایل database/upgrade_v5.3.sql را اجرا کنید.'; }
 else{
  try{
   $type=(string)($_POST['equipment_type']??'');
   if(!isset($equipmentTypes[$type])) throw new RuntimeException('نوع آماد را انتخاب کنید.');
   $isVehicle=in_array($type,$vehicleTypes,true);
   $custom=trim((string)($_POST['custom']??''));
   if($type==='other' && $custom==='') throw new RuntimeException('عنوان آماد «سایر» را وارد کنید.');
   $label=$type==='other'?$custom:$equipmentTypes[$type];
   $model=trim((string)($_POST['model']??'')); $color=trim((string)($_POST['color']??'')); $notes=trim((string)($_POST['notes']??''));
   $quantity=(int)($_POST['quantity']??0);
   if($quantity<1 || $quantity>200) throw new RuntimeException('تعداد باید بین ۱ تا ۲۰۰ باشد.');

   $units=[];
   if($isVehicle){
    $plates=$_POST['plates']??[];
    if(!is_array($plates) || count($plates)!==$quantity) throw new RuntimeException('برای هر واحد باید شماره پلاک وارد شود.');
    foreach($plates as $p){
     $p=normalize_plate((string)$p);
     if($p==='') throw new RuntimeException('شماره پلاک همه واحدها را وارد کنید.');
     if(!valid_iran_plate($p)) throw new RuntimeException('پلاک را با قالب ۱۲ب۳۴۵-۶۷ وارد کنید.');
     $units[]=['serial'=>null,'plate'=>$p];
    }
    if(count(array_unique(array_column($units,'plate')))!==count($units)) throw new RuntimeException('پلاک‌های واردشده باید یکتا باشند.');
   }else{
    $serials=$_POST['serials']??[];
    if(!is_array($serials) || count($serials)!==$quantity) throw new RuntimeException('برای هر واحد باید شماره سریال وارد شود.');
    foreach($serials as $s){
     $s=trim((string)$s);
     if($s==='') throw new RuntimeException('شماره سریال همه واحدها را وارد کنید.');
     $units[]=['serial'=>$s,'plate'=>null];
    }
    if(count(array_unique(array_column($units,'serial')))!==count($units)) throw new RuntimeException('شماره‌های سریال واردشده باید یکتا باشند.');
   }

   $pdo->beginTransaction();
   $ins=$pdo->prepare('INSERT INTO equipment_stock(equipment_type,serial_number,plate,model,color,status,notes,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?)');
   foreach($units as $u){
    $ins->execute([$label,$u['serial'],$u['plate'],$model!==''?$model:null,$color!==''?$color:null,'healthy',$notes!==''?$notes:null,user()['id'],user()['id']]);
   }
   $pdo->commit();
   $success=$quantity>1 ? fa_digits((string)$quantity).' واحد «'.$label.'» با موفقیت به انبار آماد اضافه شد.' : '«'.$label.'» با موفقیت به انبار آماد اضافه شد.';
  }catch(RuntimeException $e){
   if($pdo->inTransaction()) $pdo->rollBack();
   $error=$e->getMessage();
  }catch(PDOException $e){
   if($pdo->inTransaction()) $pdo->rollBack();
   $code=(int)($e->errorInfo[1]??0); $msg=(string)($e->errorInfo[2]??'');
   if($code===1062 && str_contains($msg,'uq_equipment_stock_serial')) $error='یکی از شماره‌های سریال قبلاً در انبار آماد ثبت شده است.';
   elseif($code===1062 && str_contains($msg,'uq_equipment_stock_plate')) $error='یکی از شماره‌های پلاک قبلاً در انبار آماد ثبت شده است.';
   else $error='ذخیره اطلاعات انجام نشد.';
  }
 }
}
require __DIR__.'/../app/partials/header.php'; ?>
<section class="page-head equipment-head"><div><h1>آماد</h1><p>ثبت اقلام و تجهیزات، گزارش وضعیت آمادی و بازتحویل</p></div></section>
<div class="training-tabs">
  <a class="training-tab active" href="equipment.php">ثبت آماد</a>
  <a class="training-tab" href="equipment_status.php">گزارش وضعیت آمادی</a>
  <a class="training-tab" href="equipment_return.php">ثبت بازتحویل</a>
</div>
<?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?><?php if($error):?><div class="alert danger"><?=e($error)?></div><?php endif;?>
<?php if(can_manage_personnel()): ?>
<?php if(!$hasStockTable): ?>
<div class="alert danger">ساختار دیتابیس نیاز به به‌روزرسانی دارد. فایل database/upgrade_v5.3.sql را روی پایگاه داده اجرا کنید تا ثبت آماد فعال شود.</div>
<?php else: ?>
<div class="panel equipment-panel">
  <div class="section-title">ثبت آماد جدید</div>
  <p class="muted">یک نوع آماد را انتخاب کنید تا فرم ثبت واحد(های) جدید باز شود.</p>
  <div class="form-grid">
    <label class="wide equipment-type-field">نوع آماد
      <select id="stockTypeSelect" data-placeholder="انتخاب نوع آماد">
        <option value="" disabled selected hidden>انتخاب نوع آماد</option>
        <?php foreach($equipmentTypes as $k=>$v):?><option value="<?=e($k)?>"><?=e($v)?></option><?php endforeach;?>
      </select>
    </label>
  </div>
</div>
<div class="pv-modal stock-modal" id="stockModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-stock-close></div>
  <section class="pv-modal-card" role="dialog" aria-modal="true" aria-labelledby="stockModalTitle">
    <header class="pv-modal-head">
      <div><span class="pv-modal-eyebrow">ثبت آماد جدید</span><h2 id="stockModalTitle">—</h2></div>
      <button type="button" class="pv-modal-close" data-stock-close aria-label="بستن">×</button>
    </header>
    <form method="post" class="pv-modal-form" id="stockForm">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="add_stock">
      <input type="hidden" name="equipment_type" id="stockTypeInput">
      <label id="stockCustomField" class="wide" hidden>عنوان آماد<input name="custom" placeholder="عنوان آماد را وارد کنید"></label>
      <label>مدل<input name="model" placeholder="مدل (اختیاری)"></label>
      <label id="stockColorField" hidden>رنگ<input name="color" placeholder="رنگ (اختیاری)"></label>
      <label>تعداد<input name="quantity" id="stockQuantity" type="number" min="1" max="200" value="1" inputmode="numeric" required></label>
      <div class="wide" id="stockUnits"></div>
      <label class="wide">توضیحات<textarea name="notes" rows="2" placeholder="توضیحات (اختیاری)"></textarea></label>
      <div class="pv-modal-actions wide"><button class="btn primary" type="submit">ثبت</button><button class="btn secondary" type="button" data-stock-close>انصراف</button></div>
    </form>
  </section>
</div>
<script>
(function(){
 var TYPES=<?=json_encode($equipmentTypes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
 var VEHICLES=<?=json_encode($vehicleTypes,JSON_UNESCAPED_UNICODE)?>;
 var select=document.getElementById('stockTypeSelect');
 var modal=document.getElementById('stockModal');
 if(!select||!modal) return;
 if(modal.parentElement!==document.body) document.body.appendChild(modal);
 var typeInput=document.getElementById('stockTypeInput');
 var titleEl=document.getElementById('stockModalTitle');
 var customField=document.getElementById('stockCustomField');
 var colorField=document.getElementById('stockColorField');
 var qty=document.getElementById('stockQuantity');
 var units=document.getElementById('stockUnits');
 var form=document.getElementById('stockForm');
 var currentType='';

 function renderUnits(){
  var n=Math.max(1,Math.min(200,parseInt(qty.value,10)||1));
  var isVehicle=VEHICLES.indexOf(currentType)!==-1;
  var label=isVehicle?'شماره پلاک':'شماره سریال';
  var name=isVehicle?'plates':'serials';
  var placeholder=isVehicle?'۱۲ب۳۴۵-۶۷':'شماره سریال واحد';
  var html='';
  for(var i=1;i<=n;i++){
   html+='<label>'+label+' #'+i+'<input name="'+name+'[]" required placeholder="'+placeholder+'"></label>';
  }
  units.innerHTML='<div class="form-grid">'+html+'</div>';
 }

 function open(type){
  currentType=type;
  var isVehicle=VEHICLES.indexOf(type)!==-1;
  typeInput.value=type;
  titleEl.textContent=TYPES[type]||'—';
  customField.hidden=(type!=='other');
  customField.querySelector('input').required=(type==='other');
  colorField.hidden=!isVehicle;
  qty.value='1';
  renderUnits();
  modal.classList.add('open'); modal.setAttribute('aria-hidden','false'); document.body.classList.add('modal-open');
 }
 function close(){
  modal.classList.remove('open'); modal.setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open');
  select.value=''; select.dispatchEvent(new Event('change',{bubbles:true}));
  form.reset();
 }
 select.addEventListener('change',function(){ if(select.value) open(select.value); });
 qty.addEventListener('input',renderUnits);
 modal.querySelectorAll('[data-stock-close]').forEach(function(el){ el.addEventListener('click',close); });
 document.addEventListener('keydown',function(e){ if(e.key==='Escape' && modal.classList.contains('open')) close(); });
})();
</script>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__.'/../app/partials/footer.php'; ?>
