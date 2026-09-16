<?php
require __DIR__.'/../app/bootstrap.php'; require_login();
$orderTypes=['mission'=>'ماموریتی','responsibility'=>'مسئولیتی'];
$error=''; $success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 if(!can_manage_personnel()){http_response_code(403);exit('دسترسی غیرمجاز');}
 if(($_POST['action']??'')==='add_order'){
  try{
   $pid=(int)($_POST['personnel_id']??0); $type=$_POST['order_type']??'';
   $issueDate=parse_jalali_input($_POST['issue_date_jalali']??'');
   $description=trim($_POST['description']??'');
   $responsibilityPosition=trim($_POST['responsibility_position']??'');
   $memberIds=array_values(array_unique(array_filter(array_map('intval',$_POST['subordinate_ids']??[]))));
   $durationDays=(int)digits_only((string)($_POST['duration_days']??''));
   $orderNumber=trim((string)($_POST['order_number']??''));
   $weaponStatus=(string)($_POST['weapon_status']??'');
   $weaponType=trim((string)($_POST['weapon_type']??''));
   $weaponSerial=trim((string)($_POST['weapon_serial']??''));
   $purpose=trim((string)($_POST['purpose']??''));
   $vehicleType=(string)($_POST['vehicle_type']??'');
   $destination=trim((string)($_POST['destination']??''));
   $expiryDate=null;
   if(!$pid||!isset($orderTypes[$type])) throw new RuntimeException('اطلاعات حکم ناقص است.');
   if(!$issueDate) throw new RuntimeException('تاریخ صدور را صحیح وارد کنید.');
   if($type==='responsibility' && $responsibilityPosition==='') throw new RuntimeException('سمت حکم مسئولیتی را انتخاب کنید.');
   if($type==='mission'){
    if($durationDays<1||$durationDays>3650) throw new RuntimeException('مدت ماموریت را به روز و به‌درستی وارد کنید.');
    if($orderNumber==='') throw new RuntimeException('شماره حکم را وارد کنید.');
    if(!in_array($weaponStatus,['with','without'],true)) throw new RuntimeException('وضعیت سلاح را انتخاب کنید.');
    if($weaponStatus==='with' && $weaponType==='') throw new RuntimeException('برای ماموریت با سلاح، نوع اسلحه را وارد کنید.');
    if($weaponStatus==='with' && $weaponSerial==='') throw new RuntimeException('برای ماموریت با سلاح، شماره سریال را وارد کنید.');
    if($weaponStatus==='without'){ $weaponType=''; $weaponSerial=''; }
    if($vehicleType!=='' && !in_array($vehicleType,['car','motorcycle'],true)) throw new RuntimeException('وسیله نقلیه انتخاب‌شده معتبر نیست.');
    $expiryDate=date('Y-m-d', strtotime($issueDate.' +'.$durationDays.' days'));
   }else{
    $durationDays=0; $orderNumber=''; $weaponStatus=''; $weaponType=''; $weaponSerial=''; $purpose=''; $vehicleType=''; $destination='';
   }
   if(!can_view_unit((string)($pdo->query('SELECT ct.category_key AS unit FROM personnel p JOIN category_types ct ON ct.id=p.category_type_id WHERE p.id='.(int)$pid)->fetchColumn() ?: ''))) throw new RuntimeException('این عنصر خارج از محدوده دسترسی شماست.');
   $st=$pdo->prepare('SELECT p.id,p.personnel_status,ct.category_key AS unit FROM personnel p JOIN category_types ct ON ct.id=p.category_type_id WHERE p.id=?'); $st->execute([$pid]); $person=$st->fetch();
   if(!$person) throw new RuntimeException('عنصر پیدا نشد.');
   if(person_is_dismissed($person)) throw new RuntimeException('این عنصر برکنار شده است؛ ثبت حکم برای او امکان‌پذیر نیست.');
   if(!can_view_unit($person['unit'])) throw new RuntimeException('این عنصر خارج از محدوده دسترسی شماست.');
   if($memberIds){
    $ph=implode(',',array_fill(0,count($memberIds),'?'));
    $mst=$pdo->prepare('SELECT p.id,p.personnel_status,ct.category_key AS unit FROM personnel p JOIN category_types ct ON ct.id=p.category_type_id WHERE p.id IN ('.$ph.')'); $mst->execute($memberIds); $memberRows=$mst->fetchAll();
    if(count($memberRows)!==count($memberIds)) throw new RuntimeException('یکی از اعضای زیرحکم معتبر نیست.');
    foreach($memberRows as $mr){ if(person_is_dismissed($mr)) throw new RuntimeException('یکی از اعضای زیرحکم برکنار شده است.'); }
    foreach($memberRows as $mr){ if(!can_view_unit($mr['unit'])) throw new RuntimeException('یکی از اعضای زیرحکم خارج از محدوده دسترسی شماست.'); }
   }
   // عکس حکم (اختیاری): یک فایل، حداکثر ۸ مگابایت
   $orderPhoto=null;
   if(!empty($_FILES['order_photo']['name'])){
    $allowedOrderMime=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $f=$_FILES['order_photo'];
    if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('بارگذاری عکس حکم انجام نشد.');
    if(!is_uploaded_file($f['tmp_name'])) throw new RuntimeException('بارگذاری عکس حکم انجام نشد.');
    if($f['size']>8*1024*1024) throw new RuntimeException('حجم عکس حکم نباید بیشتر از ۸ مگابایت باشد.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if(!isset($allowedOrderMime[$mime])) throw new RuntimeException('فرمت عکس حکم مجاز نیست.');
    $orderPhoto=['tmp'=>$f['tmp_name'],'name'=>$f['name'],'mime'=>$mime,'size'=>(int)$f['size'],'ext'=>$allowedOrderMime[$mime]];
   }
   // ستون‌های حکم ماموریتی ممکن است در دیتابیس قدیمی نباشند.
   $orderCols=[]; foreach($pdo->query('SHOW COLUMNS FROM personnel_orders') as $col){$orderCols[strtolower($col['Field'])]=true;}
   $hasMissionCols=isset($orderCols['duration_days'],$orderCols['weapon_status'],$orderCols['purpose'],$orderCols['vehicle_type'],$orderCols['destination'],$orderCols['is_renewable'],$orderCols['order_number'],$orderCols['weapon_type']);
   if($type==='mission' && !$hasMissionCols) throw new RuntimeException('ساختار دیتابیس نیاز به به‌روزرسانی دارد. فایل database/upgrade_v4.41.sql را اجرا کنید.');
   $pdo->beginTransaction();
   $title=$type==='responsibility'?$responsibilityPosition:($purpose?:null);
   if($hasMissionCols){
    $st=$pdo->prepare('INSERT INTO personnel_orders(personnel_id,order_type,order_number,order_date,start_date,end_date,duration_days,weapon_status,weapon_type,weapon_serial,purpose,vehicle_type,destination,is_renewable,title,description,responsibility_position,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$pid,$type,$orderNumber?:null,$issueDate,$issueDate,$type==='mission'?$expiryDate:null,
                  $durationDays?:null,$weaponStatus?:null,$weaponType?:null,$weaponSerial?:null,$purpose?:null,$vehicleType?:null,$destination?:null,0,
                  $title,$description,$responsibilityPosition?:null,user()['id'],user()['id']]);
   }else{
    $st=$pdo->prepare('INSERT INTO personnel_orders(personnel_id,order_type,order_date,start_date,end_date,title,description,responsibility_position,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$pid,$type,$issueDate,$issueDate,$type==='mission'?$expiryDate:null,$title,$description,$responsibilityPosition?:null,user()['id'],user()['id']]);
   }
   $orderId=(int)$pdo->lastInsertId();
   $movedOrderFile=null;
   if($orderPhoto){
    $pdo->exec("CREATE TABLE IF NOT EXISTS personnel_order_documents (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT, order_id INT UNSIGNED NOT NULL,
      original_name VARCHAR(255) NOT NULL, stored_name VARCHAR(255) NOT NULL,
      mime_type VARCHAR(100) NOT NULL, file_size INT UNSIGNED NOT NULL,
      created_by INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id), KEY idx_order_doc_order(order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $stored='order_'.$orderId.'_'.bin2hex(random_bytes(10)).'.'.$orderPhoto['ext'];
    $dest=storage_path('documents').$stored;
    if(!move_uploaded_file($orderPhoto['tmp'],$dest)) throw new RuntimeException('ذخیره عکس حکم انجام نشد.');
    $movedOrderFile=$dest;
    $pdo->prepare('INSERT INTO personnel_order_documents(order_id,original_name,stored_name,mime_type,file_size,created_by) VALUES(?,?,?,?,?,?)')
        ->execute([$orderId,$orderPhoto['name'],$stored,$orderPhoto['mime'],$orderPhoto['size'],user()['id']]);
   }
   if($type==='mission' && $memberIds){
    $ins=$pdo->prepare('INSERT INTO personnel_order_members(order_id,personnel_id,created_by) VALUES(?,?,?)');
    foreach($memberIds as $mid) $ins->execute([$orderId,$mid,user()['id']]);
   }
   $pdo->commit(); $success='حکم با موفقیت ثبت شد.';
  }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); if(!empty($movedOrderFile)&&is_file($movedOrderFile))@unlink($movedOrderFile); $error=$e instanceof RuntimeException?$e->getMessage():'عملیات انجام نشد.'; }
 }
}
$activeTab=$_GET['tab']??'add'; if(!in_array($activeTab,['add','list'],true)) $activeTab='add';
$listCols=[]; foreach($pdo->query('SHOW COLUMNS FROM personnel_orders') as $col){$listCols[strtolower($col['Field'])]=true;}
$hasOrderNumber=isset($listCols['order_number']);
$allowed=allowed_units(); $place=implode(',',array_fill(0,count($allowed),'?')); $params=$allowed;
$q=fa_to_en_digits(trim($_GET['q']??'')); $where=['ct.category_key IN ('.$place.')'];
if($q!==''){
 $like='%'.$q.'%';
 if($hasOrderNumber){ $where[]='(p.full_name LIKE ? OR p.national_id LIKE ? OR o.order_number LIKE ?)'; array_push($params,$like,$like,$like); }
 else { $where[]='(p.full_name LIKE ? OR p.national_id LIKE ?)'; array_push($params,$like,$like); }
}
$orders=[];
if($activeTab==='list'){
 $st=$pdo->prepare('SELECT o.*,p.full_name,p.national_id,ct.category_key AS unit FROM personnel_orders o JOIN personnel p ON p.id=o.personnel_id JOIN category_types ct ON ct.id=p.category_type_id WHERE '.implode(' AND ',$where).' ORDER BY COALESCE(o.start_date,o.order_date,o.created_at) DESC,o.id DESC'); $st->execute($params); $orders=$st->fetchAll();
}
$people=$pdo->query('SELECT p.id,p.full_name,p.national_id,ct.category_key AS unit FROM personnel p JOIN category_types ct ON ct.id=p.category_type_id WHERE '.active_personnel_sql('p').' ORDER BY p.full_name')->fetchAll();
// Ensure the child table exists even when an older database was not migrated yet.
$pdo->exec("CREATE TABLE IF NOT EXISTS personnel_order_members (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT,
 order_id INT UNSIGNED NOT NULL,
 personnel_id INT UNSIGNED NOT NULL,
 created_by INT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 UNIQUE KEY uq_order_member (order_id, personnel_id),
 KEY idx_order_members_order (order_id),
 KEY idx_order_members_personnel (personnel_id),
 CONSTRAINT fk_order_members_order FOREIGN KEY (order_id) REFERENCES personnel_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT fk_order_members_person FOREIGN KEY (personnel_id) REFERENCES personnel(id) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT fk_order_members_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$orderMemberMap=[]; $memberQuery=$pdo->query('SELECT m.order_id,m.personnel_id,p.full_name,p.national_id FROM personnel_order_members m JOIN personnel p ON p.id=m.personnel_id ORDER BY m.order_id,p.full_name')->fetchAll(); foreach($memberQuery as $m){$orderMemberMap[(int)$m['order_id']][]=$m;}
require __DIR__.'/../app/partials/header.php'; ?>
<section class="page-head"><div><h1>احکام</h1></div><div class="report-actions"><button class="btn secondary" type="button" onclick="window.print()">دریافت PDF</button></div></section>
<style>.resource-tabs{display:flex;gap:10px;margin:0 0 22px;direction:rtl}.order-form-panel{border:1px solid #e3ebe7;border-radius:18px;box-shadow:0 10px 30px rgba(17,45,34,.06);background:#fff}.order-form-panel .section-title{font-size:20px;font-weight:900;margin-bottom:20px;color:#18382d}.order-form-panel .form-grid{gap:18px}.order-form-panel label{color:#29463c;font-weight:700}.order-form-panel input,.order-form-panel select,.order-form-panel textarea{border:1px solid #d7e2dc;background:#fbfdfc;border-radius:12px;min-height:46px;transition:.2s ease}.order-form-panel input:focus,.order-form-panel select:focus,.order-form-panel textarea:focus{border-color:#244b3b;box-shadow:0 0 0 4px rgba(36,75,59,.09);background:#fff}.order-form-panel textarea{padding-top:12px;resize:vertical}.subordinate-picker{border:1px solid #dce8e2;border-radius:16px;padding:16px;background:#f8fbf9}.subordinate-head{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:12px}.subordinate-head strong{font-size:16px;color:#18382d}.subordinate-head small{display:block;color:#71817a;margin-top:4px}.subordinate-search-wrap{margin-bottom:10px}.subordinate-search-wrap input{width:100%;background:#fff}.subordinate-list{max-height:310px;overflow:auto;display:grid;gap:8px;padding:4px}.subordinate-row{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:10px;padding:11px 12px;border:1px solid #e2ebe6;border-radius:12px;background:#fff;cursor:pointer;font-weight:600;transition:.15s ease}.subordinate-row:hover{border-color:#b9cec3;transform:translateY(-1px)}.subordinate-row.head-excluded{display:none!important}.subordinate-row small{color:#788780;font-weight:500}.subordinate-row input{min-height:auto}.member-count-badge{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;background:#edf5f0;color:#244b3b;font-weight:800}.member-mini-list{display:flex;flex-wrap:wrap;gap:5px;margin-top:7px}.member-mini-list span{padding:3px 7px;border-radius:999px;background:#f2f5f3;font-size:12px}.actions .btn.primary{min-width:140px;box-shadow:0 8px 18px rgba(36,75,59,.18)}.order-mode-note{display:none!important}</style><style>
.order-selected-person{grid-column:1/-1;border:1px solid #e1e9e5;border-radius:16px;background:#f8fbf9;padding:16px 18px;margin-bottom:2px}
.order-selected-person__head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;color:#18382d;font-weight:900}
.order-selected-person__hint{font-size:12px;font-weight:600;color:#82918b}
.order-selected-person__grid{display:grid;grid-template-columns:120px 1.3fr 1fr;gap:10px}
.order-selected-person__cell{background:#fff;border:1px solid #e3ebe7;border-radius:12px;padding:11px 13px;min-width:0}
.order-selected-person__cell span{display:block;font-size:12px;color:#7b8a84;margin-bottom:5px}
.order-selected-person__cell strong{display:block;color:#244b3b;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
@media(max-width:760px){.order-selected-person__grid{grid-template-columns:1fr}.order-selected-person__head{align-items:flex-start;flex-direction:column}}
</style>
<style>.resource-tab{display:inline-flex;align-items:center;justify-content:center;padding:11px 24px;border-radius:12px;text-decoration:none;font-weight:800;border:1px solid #d9e2de;background:#fff;color:#244b3b;transition:.2s;box-shadow:0 3px 10px rgba(0,0,0,.05)}.resource-tab:hover{transform:translateY(-2px);box-shadow:0 7px 18px rgba(36,75,59,.12)}.resource-tab.active{background:#244b3b;color:#fff;border-color:#244b3b}</style><div class="training-tabs" role="tablist" aria-label="مدیریت احکام">
  <a class="training-tab <?= $activeTab==='add'?'active':'' ?>" href="orders.php?tab=add">افزودن حکم جدید</a>
  <a class="training-tab <?= $activeTab==='list'?'active':'' ?>" href="orders.php?tab=list">گزارش احکام ثبت‌شده</a>
  <?php if($activeTab==='list'): ?><button class="training-tab-action" type="button" onclick="window.print()">دریافت PDF</button><?php endif; ?>
</div>
<?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?><?php if($error):?><div class="alert danger"><?=e($error)?></div><?php endif;?>
<?php if($activeTab==='add'): ?>
<?php if(can_manage_personnel()): ?>
<div class="panel order-form-panel">
 <form method="post" class="form-grid" id="orderForm" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add_order">
  <label>نوع حکم<select name="order_type" id="orderType" required><option value="responsibility">مسئولیتی</option><option value="mission">ماموریتی</option></select></label>
  <div class="order-field" id="responsibilityFields">
   <label>سمت حکم
    <select name="responsibility_position" id="responsibilityPosition">
      <option value="معاون اطلاعات">معاون اطلاعات</option>
      <option value="جانشین">جانشین</option>
      <option value="معاونت  "> دفتر معاونت </option>
      <option value="معاونت هماهنگ کننده">  معاونت هماهنگ کننده</option>
      <option value="دفتر نظارت"> دفتر نظارت ، بازرسی و حقوقی</option>
      <option value="مدیریت اداری، مالی و پشتیبانی">کارشناس مدیریت اداری، مالی و پشتیبانی</option>
      <option value="اداری و نیروی انسانی">کارشناس اداری و نیروی انسانی</option>
      <option value="مالی و بودجه">کارشناس مالی و بودجه</option>
      <option value="پشتیبانی و تدارکات">کارشناس پشتیبانی و تدارکات</option>
      <option value="آموزش و توانمند سازی">کارشناس آموزش و توانمند سازی</option>
      <option value="فناوری و توسعه">کارشناس فناوری و توسعه</option>
      <option value="مدیریت شبکه جمع آوری">کارشناس مدیریت شبکه جمع آوری</option>
      <option value="گشت">کارشناس گشت</option>
      <option value="آشکار">کارشناس آشکار</option>
      <option value="پنهان">کارشناس پنهان</option>
      <option value="GIS"> کارشناس GIS</option>
      <option value="مدیریت عملیاتی"> کارشناس مدیریت عملیاتی</option>
      <option value="عملیات و دستگیری"> کارشناس عملیات و دستگیری</option>
      <option value="بررسی">کارشناس بررسی</option>
      <option value="عملیات سایبری">کارشناس عملیات سایبری</option>
      <option value="عملیات روانی">کارشناس عملیات روانی</option>
    </select>
   </label>
  </div>

  <label class="mission-only">شماره حکم<input name="order_number" id="orderNumber" maxlength="60" inputmode="numeric" placeholder="شماره حکم"></label>

  <label>تاریخ صدور<input name="issue_date_jalali" id="issueDate" class="jalali" inputmode="numeric" maxlength="10" placeholder="۱۴۰۷/۰۷/۰۷" required></label>


  <label id="expiryWrap" class="mission-only">مدت (روز)<input name="duration_days" id="durationDays" inputmode="numeric" maxlength="4" placeholder="مثلاً ۷"></label>
  <label class="mission-only">تاریخ انقضا<input id="expiryDate" placeholder="از تاریخ صدور و مدت محاسبه می‌شود" readonly></label>
  <label class="mission-only">وضعیت سلاح<select name="weapon_status" id="weaponStatus"><option value="" disabled hidden selected>انتخاب وضعیت سلاح</option><option value="without">بدون سلاح</option><option value="with">با سلاح</option></select></label>
  <label class="mission-only">نوع اسلحه<input name="weapon_type" id="weaponType" maxlength="120" placeholder="نوع اسلحه" disabled></label>
  <label class="mission-only">شماره سریال سلاح<input name="weapon_serial" id="weaponSerial" maxlength="120" placeholder="شماره سریال سلاح" disabled></label>
  <label class="mission-only">به منظور<input name="purpose" maxlength="255" placeholder="موضوع ماموریت"></label>
  <label class="mission-only">با وسیله نقلیه<select name="vehicle_type"><option value="" disabled hidden selected>انتخاب وسیله نقلیه</option><option value="car">خودرو</option><option value="motorcycle">موتور سیکلت</option></select></label>
  <label class="mission-only">به مقصد<input name="destination" maxlength="255" placeholder="مقصد ماموریت"></label>

  <div class="documents-grid order-photo-grid wide">
    <div class="document-upload-card">
      <div class="document-upload-icon">▣</div>
      <div><strong>عکس حکم</strong><small>یک فایل، حداکثر ۸MB</small></div>
      <label class="file-picker"><span data-file-label="انتخاب عکس حکم">انتخاب عکس حکم</span><input type="file" name="order_photo" accept="application/pdf,image/jpeg,image/png,image/webp"></label>
    </div>
  </div>


  <div class="order-person-picker wide">
    <div class="order-person-head"><div><strong id="orderPersonTitle">انتخاب عنصر</strong><small>یک نفر را از فهرست انتخاب کنید</small></div><span id="orderPersonState">انتخاب نشده</span></div>
    <div class="order-person-search">
      <input type="search" id="orderPersonSearch" class="filter-control filter-search" placeholder="جستجو نام نام خانوادگی، کدملی" autocomplete="off">
      <button type="button" class="filter-btn" id="orderPersonSearchBtn">جستجو</button>
    </div>
    <div class="order-person-list" id="orderPersonList">
      <?php foreach($people as $p): if(can_view_unit($p['unit'])): ?>
      <label class="op-item" data-search="<?=e(mb_strtolower($p['full_name'].' '.$p['national_id'],'UTF-8'))?>">
        <input type="radio" name="personnel_id" value="<?=(int)$p['id']?>" required>
        <span class="op-name"><?=e($p['full_name'])?></span>
        <span class="op-nid"><?=e(fa_digits((string)$p['national_id']))?></span>
      </label>
      <?php endif; endforeach; ?>
    </div>
    <div class="order-person-empty" id="orderPersonEmpty" hidden>موردی با این جستجو پیدا نشد.</div>
  </div>

  <div class="mission-only wide order-person-picker" id="subordinatePicker">
    <div class="order-person-head"><div><strong>همراهان حکم</strong><small>می‌توانید چند نفر را انتخاب کنید</small></div><span id="selectedMemberCount">۰ نفر انتخاب شده</span></div>
    <div class="order-person-search">
      <input type="search" id="memberSearch" class="filter-control filter-search" placeholder="جستجو نام نام خانوادگی، کدملی" autocomplete="off">
      <button type="button" class="filter-btn" id="memberSearchBtn">جستجو</button>
    </div>
    <div class="order-person-list" id="subordinateList">
      <?php foreach($people as $p): if(can_view_unit($p['unit'])): ?>
      <label class="op-item subordinate-row" data-search="<?=e(mb_strtolower($p['full_name'].' '.$p['national_id'],'UTF-8'))?>">
       <input type="checkbox" name="subordinate_ids[]" value="<?=$p['id']?>">
       <span class="op-name"><?=e($p['full_name'])?></span>
       <span class="op-nid"><?=e(fa_digits((string)$p['national_id']))?></span>
      </label>
      <?php endif; endforeach; ?>
    </div>
    <div class="order-person-empty" id="memberEmpty" hidden>موردی با این جستجو پیدا نشد.</div>
  </div>

  <label class="wide">توضیحات<textarea name="description" rows="3" placeholder="توضیحات حکم"></textarea></label>
  <div class="actions wide"><button class="btn primary">ثبت حکم</button></div>
 </form>
</div>
<?php endif; ?>


<?php else: ?>
<form class="filter-bar orders-search-bar" method="get" data-auto-filter>
  <input type="hidden" name="tab" value="list">
  <input type="search" name="q" class="filter-control filter-search" placeholder="جستجو نام و نام خانوادگی، کد ملی یا شماره حکم" value="<?= e($q) ?>" aria-label="جستجو">
  <button class="filter-btn" type="submit">جستجو</button>
</form>
<?php $printTitle='گزارش احکام ثبت‌شده'; $printLandscape=true; require __DIR__.'/../app/partials/print_frame.php'; ?>
<div class="panel print-list orders-list-panel">
 <div class="orders-list-head"><div><h2>احکام ثبت‌شده</h2><p><?=fa_digits((string)count($orders))?> حکم</p></div></div>
 <div class="table-wrap"><table><thead><tr><th>ردیف</th><th>شماره حکم</th><th>نام و نام خانوادگی</th><th>کد ملی</th><th>نوع حکم</th><th>سمت / به منظور</th><th>صدور</th><th>انقضا</th><th>تمدید</th><th>همراهان حکم</th><th>توضیحات</th></tr></thead><tbody><?php $orderRow=0; foreach($orders as $o): $orderRow++; $members=$orderMemberMap[(int)$o['id']]??[]; ?><tr><td><?=fa_digits((string)$orderRow)?></td><td><?=e(fa_digits((string)($o['order_number']??''))?:'—')?></td><td><a href="personnel_view.php?id=<?=(int)$o['personnel_id']?>&tab=orders"><strong><?=e($o['full_name'])?></strong></a></td><td><?=e($o['national_id']?:'—')?></td><td><?=$o['order_type']==='mission'?'ماموریتی':'مسئولیتی'?></td><td><?=e($o['responsibility_position']?:($o['title']?:'—'))?></td><td><?=jalali_display($o['order_date'])?></td><td><?=$o['order_type']==='mission'?jalali_display($o['end_date']):'—'?></td><td><?= !empty($o['renewed_until']) ? jalali_display($o['renewed_until']) : '—' ?></td><td class="cell-members"><?php if($members):?><button type="button" class="members-btn" data-pv-open="membersModal" data-order-number="<?=e(fa_digits((string)($o['order_number']??''))?:'—')?>" data-members="<?=e(json_encode(array_column($members,'full_name'),JSON_UNESCAPED_UNICODE))?>"><?=fa_digits((string)count($members))?> نفر</button><?php else:?>—<?php endif;?></td><td><?=e($o['description']?:'—')?></td></tr><?php endforeach;if(!$orders):?><tr><td colspan="11" class="empty">حکمی با این جستجو پیدا نشد.</td></tr><?php endif;?></tbody></table></div>
</div>
<?php endif; ?>
<script>
(function(){
 const form=document.getElementById('orderForm');
 if(!form) return; // فقط در تب «افزودن حکم جدید»

 /* ---------- ابزارهای تاریخ شمسی ---------- */
 const FA='۰۱۲۳۴۵۶۷۸۹';
 const toEn=s=>String(s||'').replace(/[۰-۹]/g,d=>FA.indexOf(d));
 const toFa=s=>String(s||'').replace(/\d/g,d=>FA[+d]);
 const div=(a,b)=>Math.floor(a/b);
 function maskJalali(el){
  const v=toEn(el.value).replace(/\D/g,'').slice(0,8);
  let out=v.slice(0,4);
  if(v.length>4) out+='/'+v.slice(4,6);
  if(v.length>6) out+='/'+v.slice(6,8);
  el.value=toFa(out);
 }
 function jalaliToGregorian(jy,jm,jd){
  jy-=979; jm-=1; jd-=1;
  let dayNo=365*jy+div(jy,33)*8+div((jy%33)+3,4);
  for(let i=0;i<jm;i++) dayNo+=(i<6)?31:30;
  dayNo+=jd;
  let g=dayNo+79;
  let gy=1600+400*div(g,146097); g%=146097;
  let leap=true;
  if(g>=36525){ g--; gy+=100*div(g,36524); g%=36524; if(g>=365) g++; else leap=false; }
  gy+=4*div(g,1461); g%=1461;
  if(g>=366){ leap=false; g--; gy+=div(g,365); g%=365; }
  const md=[31,leap?29:28,31,30,31,30,31,31,30,31,30,31];
  let m=0; while(g>=md[m]){ g-=md[m]; m++; }
  return new Date(Date.UTC(gy,m,g+1));
 }
 function gregorianToJalali(date){
  let gy=date.getUTCFullYear()-1600, gm=date.getUTCMonth(), gd=date.getUTCDate()-1;
  let n=365*gy+div(gy+3,4)-div(gy+99,100)+div(gy+399,400);
  const md=[31,28,31,30,31,30,31,31,30,31,30,31];
  for(let i=0;i<gm;i++) n+=md[i];
  const year=gy+1600;
  if(gm>1 && (year%4===0 && (year%100!==0 || year%400===0))) n++;
  n+=gd;
  let j=n-79;
  const np=div(j,12053); j%=12053;
  let jy=979+33*np+4*div(j,1461); j%=1461;
  if(j>=366){ jy+=div(j-1,365); j=(j-1)%365; }
  let jm,jd;
  if(j<186){ jm=1+div(j,31); jd=1+(j%31); } else { jm=7+div(j-186,30); jd=1+((j-186)%30); }
  return String(jy).padStart(4,'0')+'/'+String(jm).padStart(2,'0')+'/'+String(jd).padStart(2,'0');
 }

 /* ---------- عناصر فرم ---------- */
 const typeEl=document.getElementById('orderType');
 const resp=document.getElementById('responsibilityFields');
 const pos=document.getElementById('responsibilityPosition');
 const miss=[...document.querySelectorAll('.mission-only')];
 const issueEl=document.getElementById('issueDate');
 const daysEl=document.getElementById('durationDays');
 const expiryEl=document.getElementById('expiryDate');
 const numberEl=document.getElementById('orderNumber');
 const wsEl=document.getElementById('weaponStatus');
 const wtypeEl=document.getElementById('weaponType');
 const serialEl=document.getElementById('weaponSerial');
 const personList=document.getElementById('orderPersonList');
 const personSearch=document.getElementById('orderPersonSearch');
 const personSearchBtn=document.getElementById('orderPersonSearchBtn');
 const personEmpty=document.getElementById('orderPersonEmpty');
 const personState=document.getElementById('orderPersonState');
 const personTitle=document.getElementById('orderPersonTitle');
 const personItems=personList?[...personList.querySelectorAll('.op-item')]:[];
 const memberSearch=document.getElementById('memberSearch');
 const memberSearchBtn=document.getElementById('memberSearchBtn');
 const memberEmpty=document.getElementById('memberEmpty');
 const memberCount=document.getElementById('selectedMemberCount');
 const memberRows=[...document.querySelectorAll('.subordinate-row')];

 /* ---------- ماسک تاریخ ---------- */
 document.querySelectorAll('.jalali').forEach(el=>{
  el.addEventListener('input',()=>{ maskJalali(el); syncExpiry(); });
  el.addEventListener('paste',()=>setTimeout(()=>{ maskJalali(el); syncExpiry(); },0));
 });

 /* ---------- تاریخ انقضا = تاریخ صدور + مدت ---------- */
 function syncExpiry(){
  if(!issueEl||!daysEl||!expiryEl) return;
  const digits=toEn(issueEl.value).replace(/\D/g,'');
  const n=parseInt(toEn(daysEl.value).replace(/\D/g,''),10);
  if(digits.length!==8 || !n || n<1){ expiryEl.value=''; return; }
  const jy=+digits.slice(0,4), jm=+digits.slice(4,6), jd=+digits.slice(6,8);
  if(jm<1||jm>12||jd<1||jd>31){ expiryEl.value=''; return; }
  const g=jalaliToGregorian(jy,jm,jd);
  g.setUTCDate(g.getUTCDate()+n);
  expiryEl.value=toFa(gregorianToJalali(g));
 }
 daysEl?.addEventListener('input',function(){
  this.value=toEn(this.value).replace(/\D/g,'').slice(0,4);
  syncExpiry();
 });

 /* ---------- سلاح: نوع و شماره سریال فقط با «با سلاح» ---------- */
 function syncWeapon(){
  if(!wsEl) return;
  const withWeapon=wsEl.value==='with';
  [wtypeEl,serialEl].forEach(el=>{
   if(!el) return;
   el.disabled=!withWeapon;
   el.required=withWeapon;
   if(!withWeapon) el.value='';
  });
 }
 wsEl?.addEventListener('change',syncWeapon);

 const faFold=(v)=>String(v??'').replace(/[۰-۹٠-٩]/g,d=>{const i='۰۱۲۳۴۵۶۷۸۹'.indexOf(d);return i>-1?String(i):String('٠١٢٣٤٥٦٧٨٩'.indexOf(d));}).toLowerCase();
 /* ---------- لیست همراهان حکم ---------- */
 function currentPersonId(){ const r=form.querySelector('input[name="personnel_id"]:checked'); return r?r.value:''; }
 function updateMembers(){
  const q=faFold((memberSearch?.value||'').trim());
  const headId=currentPersonId();
  let shown=0, selected=0;
  memberRows.forEach(r=>{
   const cb=r.querySelector('input');
   if(cb.value===headId){ cb.checked=false; r.hidden=true; return; }   // صاحب حکم نمی‌تواند زیرحکم باشد
   const hit=!q||faFold(r.dataset.search||'').includes(q);
   r.hidden=!hit;
   if(hit) shown++;
   if(cb.checked) selected++;
  });
  if(memberEmpty) memberEmpty.hidden=shown!==0;
  if(memberCount) memberCount.textContent=new Intl.NumberFormat('fa-IR').format(selected)+' نفر انتخاب شده';
 }
 memberSearch?.addEventListener('input',updateMembers);
 memberSearch?.addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); updateMembers(); } });
 memberSearchBtn?.addEventListener('click',updateMembers);
 memberRows.forEach(r=>r.querySelector('input').addEventListener('change',updateMembers));

 /* ---------- لیست انتخاب عنصر ---------- */
 let wasChecked=false;
 function applyPerson(){
  if(!personList) return;
  const picked=personList.querySelector('input:checked');
  personList.classList.toggle('is-picked',!!picked);
  if(picked){
   personItems.forEach(el=>{ el.hidden = el.querySelector('input')!==picked; });
   if(personEmpty) personEmpty.hidden=true;
   if(personState) personState.textContent=picked.closest('.op-item').querySelector('.op-name').textContent.trim();
   if(personSearch) personSearch.value='';
  }else{
   const q=faFold((personSearch?.value||'').trim());
   let shown=0;
   personItems.forEach(el=>{ const hit=!q||faFold(el.dataset.search||'').includes(q); el.hidden=!hit; if(hit) shown++; });
   if(personEmpty) personEmpty.hidden=shown!==0;
   if(personState) personState.textContent='انتخاب نشده';
  }
  updateMembers();
 }
 personList?.addEventListener('mousedown',e=>{ const l=e.target.closest('.op-item'); wasChecked=!!(l&&l.querySelector('input').checked); });
 personList?.addEventListener('click',e=>{
  const l=e.target.closest('.op-item'); if(!l) return;
  const radio=l.querySelector('input');
  const cancel=wasChecked; wasChecked=false;
  if(cancel) e.preventDefault();   // مرورگر وضعیت رادیو را بعد از این هندلر نهایی می‌کند
  setTimeout(()=>{ if(cancel) radio.checked=false; applyPerson(); },0);
 });
 personSearch?.addEventListener('input',()=>{ if(!personList.querySelector('input:checked')) applyPerson(); });
 personSearch?.addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); applyPerson(); } });
 personSearchBtn?.addEventListener('click',applyPerson);

 /* ---------- ماموریتی / مسئولیتی ---------- */
 function syncOrderMode(){
  const mission=typeEl.value==='mission';
  // عنوان فهرست انتخاب فرد بسته به نوع حکم عوض می‌شود.
  if(personTitle) personTitle.textContent = mission ? 'انتخاب سرحکم' : 'انتخاب عنصر';
  if(resp){ resp.hidden=mission; resp.style.display=''; }
  miss.forEach(el=>{ el.hidden=!mission; el.style.display=''; });
  if(pos) pos.required=!mission;
  if(numberEl) numberEl.required=mission;
  if(daysEl) daysEl.required=mission;
  if(wsEl) wsEl.required=mission;
  form.querySelectorAll('input[name="subordinate_ids[]"]').forEach(x=>{ x.disabled=!mission; });
  syncWeapon();
  syncExpiry();
  updateMembers();
 }
 typeEl.addEventListener('change',syncOrderMode);

 /* ---------- اجرای اولیه ---------- */
 document.querySelectorAll('.jalali').forEach(maskJalali);
 syncOrderMode();
 applyPerson();
})();
</script>
<?php if($activeTab==='list'): ?>
<div class="pv-modal" id="membersModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-pv-close></div>
  <section class="pv-modal-card" role="dialog" aria-modal="true" aria-labelledby="membersModalTitle">
    <header class="pv-modal-head">
      <div><span class="pv-modal-eyebrow">حکم شماره <span data-order-number-target>—</span></span><h2 id="membersModalTitle">همراهان حکم</h2></div>
      <button type="button" class="pv-modal-close" data-pv-close aria-label="بستن">×</button>
    </header>
    <div class="members-viewer" data-members-list></div>
    <div class="pv-modal-actions"><button type="button" class="btn secondary" data-pv-close>بستن</button></div>
  </section>
</div>
<?php endif; ?>


<script src="<?= e(asset_url('assets/filter-bar.js')) ?>" defer></script>
<script src="<?= e(asset_url('assets/pv-modal.js')) ?>" defer></script>
<script>
/* پاپ‌آپ اعضای زیرحکم */
document.querySelectorAll('[data-members]').forEach(function(btn){
 btn.addEventListener('click',function(){
  var box=document.querySelector('[data-members-list]'); if(!box) return;
  var names=[]; try{ names=JSON.parse(btn.dataset.members||'[]'); }catch(e){ names=[]; }
  box.innerHTML = names.length
   ? '<ol class="members-list">'+names.map(function(n){ return '<li>'+String(n).replace(/[<>&]/g,'')+'</li>'; }).join('')+'</ol>'
   : '<div class="cert-empty">عضوی ثبت نشده است.</div>';
 });
});

</script>
<?php require __DIR__.'/../app/partials/footer.php'; ?>


