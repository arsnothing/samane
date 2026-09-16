<?php
require __DIR__.'/../app/bootstrap.php'; require_login();
$equipmentTypes = equipment_type_options();
$statusOptions  = equipment_status_options();
$error=''; $success='';

$hasStockTable=false; $hasPersonStatusCol=false;
try { $hasStockTable=(bool)$pdo->query("SHOW TABLES LIKE 'equipment_stock'")->fetch(); } catch (Throwable $e) { $hasStockTable=false; }
try { $hasPersonStatusCol=(bool)$pdo->query("SHOW COLUMNS FROM personnel_equipment LIKE 'status'")->fetch(); } catch (Throwable $e) { $hasPersonStatusCol=false; }

$type = (string)($_POST['type'] ?? $_GET['type'] ?? '');

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='update_stock_status'){
 verify_csrf();
 if(!can_manage_personnel()){http_response_code(403);exit('دسترسی غیرمجاز');}
 if(!$hasStockTable){ $error='ساختار دیتابیس نیاز به به‌روزرسانی دارد. فایل database/upgrade_v5.3.sql را اجرا کنید.'; }
 else{
  $id=(int)($_POST['id']??0); $status=(string)($_POST['status']??'');
  if(!$id || !isset($statusOptions[$status])) $error='وضعیت انتخاب‌شده معتبر نیست.';
  else{
   $st=$pdo->prepare('UPDATE equipment_stock SET status=?,updated_by=? WHERE id=?');
   $st->execute([$status,user()['id'],$id]);
   $success='وضعیت با موفقیت به‌روزرسانی شد.';
  }
 }
}

$rows=[];
if($type!==''){
 $standardLabels = array_values(array_diff_key($equipmentTypes, ['other'=>'']));
 if($type==='other'){
  $whereType='equipment_type NOT IN ('.implode(',',array_fill(0,count($standardLabels),'?')).')';
  $paramsType=$standardLabels;
  $currentLabel='سایر';
 }elseif(isset($equipmentTypes[$type])){
  $whereType='equipment_type = ?';
  $paramsType=[$equipmentTypes[$type]];
  $currentLabel=$equipmentTypes[$type];
 }else{ $whereType=null; $paramsType=[]; $currentLabel=''; }

 if($whereType){
  if($hasStockTable){
   $st=$pdo->prepare("SELECT * FROM equipment_stock WHERE $whereType ORDER BY created_at DESC");
   $st->execute($paramsType);
   foreach($st->fetchAll() as $r){
    $rows[]=[
     'source'=>'stock','id'=>(int)$r['id'],'label'=>$r['equipment_type'],
     'model'=>$r['model'],'unit_no'=>$r['plate']?:$r['serial_number'],'unit_kind'=>$r['plate']?'پلاک':'سریال',
     'status'=>$r['status']?:'healthy','notes'=>$r['notes'],'assigned_to'=>null,'created_at'=>$r['created_at'],
    ];
   }
  }
  if($hasPersonStatusCol){
   $st=$pdo->prepare("SELECT pe.*, p.full_name FROM personnel_equipment pe JOIN personnel p ON p.id=pe.personnel_id WHERE pe.$whereType ORDER BY pe.created_at DESC");
   $st->execute($paramsType);
   foreach($st->fetchAll() as $r){
    $rows[]=[
     'source'=>'assigned','id'=>(int)$r['id'],'label'=>$r['equipment_type'],
     'model'=>$r['model'],'unit_no'=>$r['plate']?:$r['serial_number'],'unit_kind'=>$r['plate']?'پلاک':'سریال',
     'status'=>$r['status']?:'healthy','notes'=>$r['notes'],'assigned_to'=>$r['full_name'],'created_at'=>$r['created_at'],
    ];
   }
  }
  usort($rows, fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));
 }
}
require __DIR__.'/../app/partials/header.php'; ?>
<section class="page-head equipment-head"><div><h1>آماد</h1><p>ثبت اقلام و تجهیزات، گزارش وضعیت آمادی و بازتحویل</p></div></section>
<div class="training-tabs">
  <a class="training-tab" href="equipment.php">ثبت آماد</a>
  <a class="training-tab active" href="equipment_status.php">گزارش وضعیت آمادی</a>
  <a class="training-tab" href="equipment_return.php">ثبت بازتحویل</a>
</div>
<?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?><?php if($error):?><div class="alert danger"><?=e($error)?></div><?php endif;?>
<div class="panel">
  <div class="section-title">گزارش وضعیت آمادی</div>
  <form method="get" class="form-grid" data-auto-filter>
    <label class="wide">نوع آماد
      <select name="type" data-placeholder="انتخاب نوع آماد">
        <option value="">انتخاب نوع آماد</option>
        <?php foreach($equipmentTypes as $k=>$v):?><option value="<?=e($k)?>" <?=$type===$k?'selected':''?>><?=e($v)?></option><?php endforeach;?>
      </select>
    </label>
  </form>
</div>
<?php if($type===''): ?>
<div class="panel"><p class="empty">یک نوع آماد را از بالا انتخاب کنید تا فهرست وضعیت آن نمایش داده شود.</p></div>
<?php else: ?>
<div class="equip-status-grid wide">
<?php foreach($rows as $row): ?>
  <div class="equip-status-card">
    <div class="equip-status-left">
      <?php if($row['source']==='stock'): ?>
      <form method="post" data-auto-filter class="equip-status-form">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="update_stock_status">
        <input type="hidden" name="type" value="<?=e($type)?>">
        <input type="hidden" name="id" value="<?=$row['id']?>">
        <select name="status" <?=can_manage_personnel()?'':'disabled'?>>
          <?php foreach($statusOptions as $sk=>$sv):?><option value="<?=e($sk)?>" <?=$row['status']===$sk?'selected':''?>><?=e($sv)?></option><?php endforeach;?>
        </select>
      </form>
      <?php else: ?>
      <span class="status-chip <?=equipment_status_chip_class($row['status'])?>"><?=e($statusOptions[$row['status']]??$row['status'])?></span>
      <small class="muted">برای تغییر، از تب «ثبت بازتحویل» استفاده کنید.</small>
      <?php endif; ?>
    </div>
    <div class="equip-status-right">
      <strong><?=e($row['label'])?></strong>
      <span><?=e($row['unit_kind'])?>: <?=e($row['unit_no']?:'—')?></span>
      <?php if($row['model']):?><span>مدل: <?=e($row['model'])?></span><?php endif;?>
      <?php if($row['assigned_to']):?><span class="chip-assigned">در اختیار: <?=e($row['assigned_to'])?></span><?php else:?><span class="chip-stock">در انبار</span><?php endif;?>
      <?php if($row['notes']):?><small class="muted"><?=e($row['notes'])?></small><?php endif;?>
    </div>
  </div>
<?php endforeach; if(!$rows): ?>
  <p class="empty">آمادی از این نوع ثبت نشده است.</p>
<?php endif; ?>
</div>
<?php endif; ?>
<script src="<?= e(asset_url('assets/filter-bar.js')) ?>" defer></script>
<?php require __DIR__.'/../app/partials/footer.php'; ?>
