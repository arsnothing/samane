<?php
require __DIR__.'/../app/bootstrap.php';
require_login();
if (!can_manage_personnel()) { http_response_code(403); exit('دسترسی غیرمجاز'); }

if (($_GET['action'] ?? '') === 'districts') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $st=$pdo->prepare('SELECT id,district_name FROM city_districts WHERE city_id=? AND is_active=1 ORDER BY district_number');
        $st->execute([(int)($_GET['city_id']??0)]);
        echo json_encode($st->fetchAll(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) { echo '[]'; }
    exit;
}

if (($_GET['action'] ?? '') === 'cities') {
    header('Content-Type: application/json; charset=utf-8');
    $provinceId=(int)($_GET['province_id']??0);
    $st=$pdo->prepare("SELECT c.id,c.city_name FROM cities c INNER JOIN provinces p ON p.id=c.province_id WHERE c.province_id=? AND p.province_code='TEH' AND c.is_active=1 ORDER BY c.city_name");
    $st->execute([$provinceId]);
    echo json_encode($st->fetchAll(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT); $edit=(bool)$id; $row=null;
if($edit){
    $sql=personnel_select_sql('p').' WHERE p.id=?';
    $st=$pdo->prepare($sql);$st->execute([$id]);$row=$st->fetch();
    if(!$row) exit('رکورد یافت نشد.');
    block_if_dismissed($row, 'ویرایش پرونده');
}

$categoryRows=category_rows();
$categoryLabels=[]; foreach($categoryRows as $cat){$categoryLabels[(string)$cat['category_key']]=(string)$cat['category_name'];}
$eduLabels=education_options(); $positionLabels=position_options();
$provinces=$pdo->query("SELECT id,province_name FROM provinces WHERE is_active=1 AND province_code <> 'UNKNOWN' ORDER BY province_name")->fetchAll();
$selectedProvince=(int)($row['province_id']??($_POST['province_id']??0));
$cities=[];
if($selectedProvince){
    $st=$pdo->prepare("SELECT c.id,c.city_name FROM cities c INNER JOIN provinces p ON p.id=c.province_id WHERE c.province_id=? AND p.province_code='TEH' AND c.is_active=1 ORDER BY c.city_name");
    $st->execute([$selectedProvince]);
    $cities=$st->fetchAll();
}

$values=$row ?: [
 'first_name'=>'','last_name'=>'','national_id'=>'','father_name'=>'','birth_date'=>'','marital_status'=>'','education_status'=>'','mobile'=>'','emergency_phone'=>'','residence_address'=>'',
 'organizational_code'=>'','secondary_job'=>'','secondary_job_address'=>'','iban'=>'','profile_photo_path'=>'',
 'unit'=>'','unit_number'=>'1','group_no'=>'','team_no'=>'','position_type'=>'','personnel_status'=>'active','province_id'=>'','city_id'=>'','district_id'=>'','commander_number'=>'',
];
$error='';
/** در صورت خطا: تراکنش برگردانده و فایل‌های منتقل‌شده پاک می‌شوند تا رکورد یا فایل نیمه‌کاره نماند. */
function personnel_form_rollback(PDO $pdo, array $movedFiles): void {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($movedFiles as $f) { if (is_file($f)) @unlink($f); }
}
function normalize_iban_local(?string $value): string { return strtoupper(preg_replace('/\s+/', '', trim((string)$value))); }
function valid_iban_local(?string $iban): bool { return (bool)preg_match('/^IR\d{24}$/', normalize_iban_local($iban)); }

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $values=array_merge($values,$_POST);
    // «وضعیت عنصر» از فرم حذف شده است: مقدار قبلی رکورد (یا active برای رکورد جدید) حفظ می‌شود.
    $values['personnel_status']=$row['personnel_status'] ?? 'active';
    $values['national_id']=digits_only($values['national_id']??'');
    foreach(['mobile','emergency_phone','organizational_code','unit_number','group_no','team_no','commander_number'] as $f) $values[$f]=digits_only($values[$f]??'');
    $values['iban_digits']=digits_only($_POST['iban_digits']??'');
    $values['iban']=$values['iban_digits']!=='' ? 'IR'.$values['iban_digits'] : normalize_iban_local($values['iban']??'');
    $values['province_id']=(int)($values['province_id']??0);
    $values['city_id']=(int)($values['city_id']??0);
    $values['district_id']=(int)($values['district_id']??0);
    $values['group_no']=($values['group_no']!=='')?$values['group_no']:'1';

    $requiredFields=[
      'first_name'=>'نام','last_name'=>'نام خانوادگی','national_id'=>'کد ملی','father_name'=>'نام پدر',
      'marital_status'=>'وضعیت تأهل','education_status'=>'تحصیلات','mobile'=>'شماره تماس همراه',
      'emergency_phone'=>'شماره تماس اضطراری','residence_address'=>'آدرس محل سکونت',
      'secondary_job_address'=>'آدرس محل کار','organizational_code'=>'کد سازمانی',
      'position_type'=>'سمت','unit'=>'رسته','unit_number'=>'شماره دسته',
    ];
    foreach($requiredFields as $field=>$label){
      if(trim((string)($values[$field]??''))===''){ $error='«'.$label.'» را تکمیل کنید.'; break; }
    }
    if(!$error && trim((string)($_POST['birth_date_jalali']??''))==='') $error='«تاریخ تولد» را تکمیل کنید.';
    if(!$error && trim((string)($values['iban']??''))==='') $error='«شماره شبا» را تکمیل کنید.';
    if(!$error && !valid_name_text($values['first_name'])) $error='نام نباید شامل عدد باشد.';
    if(!$error && !valid_name_text($values['last_name'])) $error='نام خانوادگی نباید شامل عدد باشد.';
    if(!$error && trim((string)$values['father_name'])!=='' && !valid_name_text($values['father_name'])) $error='نام پدر نباید شامل عدد باشد.';
    if(!$error && !valid_digits($values['national_id'],10,10)) $error='کد ملی باید دقیقاً ۱۰ رقم باشد.';
    if(!$error && !valid_digits($values['mobile'],10,15)) $error='شماره اصلی فقط باید شامل اعداد باشد.';
    if(!$error && $values['emergency_phone']!=='' && !valid_digits($values['emergency_phone'],10,15)) $error='شماره تماس اضطراری نامعتبر است.';
    if(!$error && $values['organizational_code']!=='' && !valid_digits($values['organizational_code'],1,30)) $error='کد سازمانی نامعتبر است.';
    if(!$error && trim((string)$values['secondary_job'])!=='' && !valid_name_text($values['secondary_job'])) $error='عنوان شغل نباید شامل عدد باشد.';
    if(!$error && $values['iban']!=='' && !valid_iban_local($values['iban'])) $error='شماره شبا باید شامل IR و ۲۴ رقم باشد.';
    if(!$error && !array_key_exists((string)$values['unit'],$categoryLabels)) $error='رسته نامعتبر است.';
    if(!$error && !array_key_exists((string)$values['position_type'],$positionLabels)) $error='سمت نامعتبر است.';
    if(!$error && !array_key_exists((string)($values['personnel_status']??'active'),personnel_status_options())) $error='وضعیت عنصر نامعتبر است.';
    if(!$error && !valid_digits($values['unit_number'],1,null)) $error='شماره دسته را وارد کنید.';
    if(!$error && trim((string)($values['commander_number']??''))!=='' && !valid_digits($values['commander_number'],1,30)) $error='شماره قائد نامعتبر است.';
    if(!$error && (!valid_digits($values['group_no'],1,1) || !in_array((int)$values['group_no'],[1,2,3],true))) $error='گروه باید یکی از ۱ تا ۳ باشد.';

    $birthGregorian=parse_jalali_input($_POST['birth_date_jalali']??'');
    if(!$error && trim((string)($_POST['birth_date_jalali']??''))!=='' && $birthGregorian===null) $error='تاریخ تولد صحیح نیست.';

    $categoryTypeId=$category_number_id=$positionId=$groupId=$teamId=$cityProvinceId=null;
    if(!$error){
        $categoryTypeId=category_type_id((string)$values['unit']);
        $category_number_id=ensure_category_number_id((string)$values['unit'],(string)$values['unit_number']);
        $positionId=position_id((string)$values['position_type']);
        $groupId=group_id((int)$values['group_no']);
        $cityProvinceId=null;
        $st=$pdo->prepare('SELECT province_id FROM cities WHERE id=? AND is_active=1 LIMIT 1'); $st->execute([$values['city_id']]); $cityProvinceId=$st->fetchColumn();
        if(!$categoryTypeId||!$category_number_id||!$positionId) $error='اطلاعات سازمانی انتخاب‌شده معتبر نیست.';
        elseif(!$values['province_id']||!$values['city_id']||$cityProvinceId===false||((int)$cityProvinceId!==(int)$values['province_id'])) $error='استان و شهرستان معتبر را انتخاب کنید.';
        if(!$error && (int)$cityProvinceId!==(int)$values['province_id']) $error='شهرستان انتخاب‌شده متعلق به استان انتخابی نیست.';

        if(!$error && $values['district_id']){
            try { $dchk=$pdo->prepare('SELECT city_id FROM city_districts WHERE id=? LIMIT 1'); $dchk->execute([$values['district_id']]);
                  if((int)$dchk->fetchColumn() !== (int)$values['city_id']) $error='منطقه انتخاب‌شده متعلق به شهرستان انتخابی نیست.'; }
            catch (Throwable $e) { $values['district_id']=0; }
        }

        if(!$error && !in_array((string)$values['position_type'],['unit_commander'],true) && trim((string)($_POST['group_no']??''))==='') $error='«گروه» را انتخاب کنید.';
        if(!$error && !in_array((string)$values['position_type'],['unit_commander','group_commander'],true) && trim((string)($values['team_no']??''))==='') $error='«تیم» را انتخاب کنید.';
        if(!$error && in_array((string)$values['position_type'],['unit_commander','group_commander'],true)) $teamId=null; else $teamId=team_id((string)$values['unit'],(int)$values['team_no']);
        if(!$error && $values['position_type']==='unit_commander'){ $groupId=null; $teamId=null; }
        elseif(!$error && $values['position_type']==='group_commander'){ $teamId=null; }
        elseif(!$error && !$teamId) $error='تیم انتخاب‌شده با نوع رسته سازگار نیست.';
    }

    $hasDistrictColumn=false;
    if(!$error){ try { $hasDistrictColumn=(bool)$pdo->query("SHOW COLUMNS FROM personnel LIKE 'district_id'")->fetch(); } catch (Throwable $e) { $hasDistrictColumn=false; } }
    $hasCommanderColumn=false;
    if(!$error){ try { $hasCommanderColumn=(bool)$pdo->query("SHOW COLUMNS FROM personnel LIKE 'commander_number'")->fetch(); } catch (Throwable $e) { $hasCommanderColumn=false; } }
    if(!$error){
        $movedFiles=[];
        try{
            
            $pdo->beginTransaction();
            if($edit){
                $sql='UPDATE personnel SET first_name=?,last_name=?,source_full_name=?,national_id=?,position_id=?,category_type_id=?,category_number_id=?,province_id=?,city_id=?'.($hasDistrictColumn?',district_id=?':'').($hasCommanderColumn?',commander_number=?':'').',group_id=?,team_id=?,birth_date=?,father_name=?,marital_status=?,education_status=?,residence_address=?,mobile=?,emergency_phone=?,organizational_code=?,secondary_job=?,secondary_job_address=?,iban=?,personnel_status=?,updated_by=? WHERE id=?';
                $st=$pdo->prepare($sql);
                $st->execute([trim($values['first_name']),trim($values['last_name']),trim($values['first_name'].' '.$values['last_name']),$values['national_id'],$positionId,$categoryTypeId,$category_number_id,$values['province_id'],$values['city_id'],...($hasDistrictColumn?[$values['district_id']?:null]:[]),...($hasCommanderColumn?[trim((string)($values['commander_number']??''))!==''?(string)$values['commander_number']:null]:[]),$groupId,$teamId,$birthGregorian,trim($values['father_name']??''),$values['marital_status']!==''?$values['marital_status']:null,$values['education_status']!==''?$values['education_status']:null,trim($values['residence_address']??''),$values['mobile'],trim($values['emergency_phone']??''),$values['organizational_code'],trim($values['secondary_job']??''),trim($values['secondary_job_address']??''),$values['iban']!==''?$values['iban']:null,$values['personnel_status'],user()['id'],$id]);
                $personId=$id;
            }else{
                $sql='INSERT INTO personnel(first_name,last_name,source_full_name,national_id,position_id,category_type_id,category_number_id,province_id,city_id'.($hasDistrictColumn?',district_id':'').($hasCommanderColumn?',commander_number':'').',group_id,team_id,birth_date,father_name,marital_status,education_status,residence_address,mobile,emergency_phone,organizational_code,secondary_job,secondary_job_address,iban,personnel_status,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?'.($hasDistrictColumn?',?':'').($hasCommanderColumn?',?':'').',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                $st=$pdo->prepare($sql);
                $st->execute([trim($values['first_name']),trim($values['last_name']),trim($values['first_name'].' '.$values['last_name']),$values['national_id'],$positionId,$categoryTypeId,$category_number_id,$values['province_id'],$values['city_id'],...($hasDistrictColumn?[$values['district_id']?:null]:[]),...($hasCommanderColumn?[trim((string)($values['commander_number']??''))!==''?(string)$values['commander_number']:null]:[]),$groupId,$teamId,$birthGregorian,trim($values['father_name']??''),$values['marital_status']!==''?$values['marital_status']:null,$values['education_status']!==''?$values['education_status']:null,trim($values['residence_address']??''),$values['mobile'],trim($values['emergency_phone']??''),$values['organizational_code'],trim($values['secondary_job']??''),trim($values['secondary_job_address']??''),$values['iban']!==''?$values['iban']:null,$values['personnel_status'],user()['id'],user()['id']]);
                $personId=(int)$pdo->lastInsertId();
            }
            if(!empty($_FILES['profile_photo']['name'])){
                $file=$_FILES['profile_photo']; if($file['error']!==UPLOAD_ERR_OK||$file['size']>3*1024*1024) throw new RuntimeException('عکس پروفایل باید حداکثر ۳ مگابایت باشد.');
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];if(!isset($allowed[$mime]))throw new RuntimeException('فرمت عکس پروفایل مجاز نیست.');
                $stored='profile_'.$personId.'_'.bin2hex(random_bytes(8)).'.'.$allowed[$mime];
                $photoPath=storage_path('profile_photos').$stored;
                if(!move_uploaded_file($file['tmp_name'],$photoPath)) throw new RuntimeException('ذخیره عکس پروفایل انجام نشد.');
                $movedFiles[]=$photoPath;
                $pdo->prepare('UPDATE personnel SET profile_photo_path=? WHERE id=?')->execute([$stored,$personId]);
            }
            foreach(['birth_certificate'=>'document_birth_certificate','national_card'=>'document_national_card','criminal_record'=>'document_criminal_record'] as $documentType=>$input){
                if(empty($_FILES[$input]['name'])) continue; $file=$_FILES[$input]; if($file['error']!==UPLOAD_ERR_OK||$file['size']>8*1024*1024) throw new RuntimeException('حجم یکی از مدارک بیش از حد مجاز است.');
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$allowed=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];if(!isset($allowed[$mime]))throw new RuntimeException('فرمت یکی از مدارک مجاز نیست.');
                $stored='doc_'.$personId.'_'.bin2hex(random_bytes(10)).'.'.$allowed[$mime]; $docPath=storage_path('documents').$stored; if(!move_uploaded_file($file['tmp_name'],$docPath))throw new RuntimeException('ذخیره یکی از مدارک انجام نشد.'); $movedFiles[]=$docPath;
                $pdo->prepare('INSERT INTO personnel_documents(personnel_id,document_type,original_name,stored_name,mime_type,file_size,created_by) VALUES(?,?,?,?,?,?,?)')->execute([$personId,$documentType,$file['name'],$stored,$mime,(int)$file['size'],user()['id']]);
            }
            $pdo->commit();
            redirect('personnel_view.php?id='.$personId);
        }catch(RuntimeException $e){ personnel_form_rollback($pdo,$movedFiles); $error=$e->getMessage(); }
        catch(PDOException $e){ personnel_form_rollback($pdo,$movedFiles);$code=(int)($e->errorInfo[1]??0);$msg=(string)($e->errorInfo[2]??'');
            if($code===1062 && str_contains($msg,'uq_personnel_identity')) $error='رکوردی با ترکیب همین نام، نام خانوادگی، سمت، نوع رسته، شماره دسته، استان و شهرستان قبلاً ثبت شده است.';
            elseif($code===1062 && str_contains($msg,'uq_personnel_national_id')) $error='کد ملی قبلاً ثبت شده است.';
            elseif($code===1062 && str_contains($msg,'uq_personnel_command_scope')) $error='برای این جایگاه (رسته، شماره دسته، استان و شهرستان)، فرمانده/مسئول متناظر قبلاً ثبت شده است.';
            elseif(str_contains($msg,'تیم انتخاب‌شده')) $error='تیم انتخاب‌شده با نوع رسته سازگار نیست.';
            else $error='ذخیره اطلاعات انجام نشد.';
        }
    }
}

$districtsList=[]; $currentDistrict=(int)($values['district_id']??0);
// شهرستانی که فقط یک منطقه دارد (مثل شمیرانات) خودش همان منطقه است: کادر قفل و مقدار خودکار.

if(!empty($values['city_id'])){ try { $dl=$pdo->prepare('SELECT id,district_name FROM city_districts WHERE city_id=? AND is_active=1 ORDER BY district_number'); $dl->execute([(int)$values['city_id']]); $districtsList=$dl->fetchAll(); } catch (Throwable $e) { $districtsList=[]; } }
// «منطقه» فقط برای شهرستانی که منطقه‌بندی واقعی دارد (شهرستان تهران با ۲۲ منطقه) باز می‌شود.
$districtSelectable = count($districtsList) > 1;
$birthJalali=jalali_to_input($values['birth_date']??'');
$currentProvince=(int)($values['province_id']??0); if($currentProvince && !$cities){$st=$pdo->prepare("SELECT c.id,c.city_name FROM cities c INNER JOIN provinces p ON p.id=c.province_id WHERE c.province_id=? AND p.province_code='TEH' AND c.is_active=1 ORDER BY c.city_name");$st->execute([$currentProvince]);$cities=$st->fetchAll();}
require __DIR__.'/../app/partials/header.php'; ?>
<section class="page-head"><div><h1><?=$edit?'ویرایش پرونده عنصر':'افزودن عنصر جدید'?></h1></div></section>
<?php if($error):?><div class="alert danger"><?=e($error)?></div><?php endif;?>
<form method="post" enctype="multipart/form-data" class="panel form-grid" id="personnelForm">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">

<div class="section-title wide">اطلاعات فردی</div>
<label>نام<input name="first_name" required value="<?=e($values['first_name']??'')?>"></label>
<label>نام خانوادگی<input name="last_name" required value="<?=e($values['last_name']??'')?>"></label>
<label>کد ملی<input name="national_id" required inputmode="numeric" maxlength="10" value="<?=e($values['national_id']??'')?>"></label>
<label>نام پدر<input name="father_name" required value="<?=e($values['father_name']??'')?>"></label>
<label>تاریخ تولد<input name="birth_date_jalali" required class="jalali" maxlength="10" inputmode="numeric" autocomplete="off" value="<?=e($birthJalali)?>"></label>
<label>وضعیت تأهل<select name="marital_status" required><option value="" disabled hidden <?=($values['marital_status']??'')===''?'selected':''?>>انتخاب وضعیت تأهل</option><option value="single" <?=($values['marital_status']??'')==='single'?'selected':''?>>مجرد</option><option value="married" <?=($values['marital_status']??'')==='married'?'selected':''?>>متأهل</option><option value="separated" <?=($values['marital_status']??'')==='separated'?'selected':''?>>متارکه</option></select></label>
<label>تحصیلات<select name="education_status" required><option value="" disabled hidden <?=($values['education_status']??'')===''?'selected':''?>>انتخاب تحصیلات</option><?php foreach($eduLabels as $k=>$v):?><option value="<?=$k?>" <?=($values['education_status']??'')===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select></label>
<label>شماره تماس همراه<input name="mobile" required inputmode="numeric" value="<?=e($values['mobile']??'')?>"></label>
<label>شماره تماس اضطراری<input name="emergency_phone" required inputmode="numeric" value="<?=e($values['emergency_phone']??'')?>"></label>
<label class="wide">آدرس محل سکونت<textarea name="residence_address" required rows="3"><?=e($values['residence_address']??'')?></textarea></label>

<div class="section-title wide">اطلاعات شغلی</div>
<label>عنوان شغلی<input name="secondary_job" value="<?=e($values['secondary_job']??'')?>"></label>
<label class="wide">آدرس محل کار<textarea name="secondary_job_address" required rows="3"><?=e($values['secondary_job_address']??'')?></textarea></label>

<div class="section-title wide">اطلاعات بانکی</div>
<label>شماره شبا<div class="iban-input"><span class="iban-prefix">IR</span><input name="iban_digits" required placeholder="حداکثر ۲۴ رقم وارد شود" maxlength="24" inputmode="numeric" value="<?=e(substr((string)($values['iban']??''),2,24))?>"><input type="hidden"  name="iban" value="<?=e($values['iban']??'')?>"></div></label>

<div class="section-title wide">جایگاه سازمانی</div>
<div class="organization-fields wide">
 <label class="wide"><span>کد سازمانی</span><input name="organizational_code" required inputmode="numeric" value="<?=e($values['organizational_code']??'')?>"></label>
 <label class="location-field"><span>استان محل خدمت</span><div class="smart-select" id="provinceSmart"><button type="button" class="smart-select-trigger" aria-haspopup="listbox" aria-expanded="false"><span class="smart-select-value">انتخاب استان</span><span class="smart-select-arrow">⌄</span></button><div class="smart-select-menu" role="listbox"><div class="smart-select-search-wrap"><input type="search" class="smart-select-search" placeholder="جست‌وجوی استان" autocomplete="off"></div><div class="smart-select-options"></div></div><select name="province_id" id="provinceSelect" class="smart-select-native" required><option value="" disabled hidden <?=empty($values['province_id'])?'selected':''?>>انتخاب استان</option><?php foreach($provinces as $p):?><option value="<?=$p['id']?>" <?=((int)$values['province_id']===(int)$p['id'])?'selected':''?>><?=e($p['province_name'])?></option><?php endforeach;?></select></div></label>
 <label><span>شماره قائد</span><input name="commander_number" inputmode="numeric" maxlength="30" value="<?=e($values['commander_number']??'')?>" placeholder="شماره قائد (اختیاری)"></label>
 <label class=""><span>سمت</span><select name="position_type" id="positionSelect" required><option value="" disabled hidden <?=($values['position_type']??'')===''?'selected':''?>>انتخاب سمت</option><?php foreach($positionLabels as $k=>$v):?><option value="<?=$k?>" <?=($values['position_type']??'')===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select></label>
 <label><span>رسته</span><select name="unit" id="unitSelect" required><option value="" disabled hidden <?=((string)($values['unit']??''))===''?'selected':''?>>انتخاب رسته</option><?php foreach($categoryLabels as $k=>$v):?><option value="<?=e($k)?>" <?=((string)($values['unit']??'')===(string)$k?'selected':'')?>><?=e($v)?></option><?php endforeach;?></select></label>
 <label><span>شماره دسته</span><input name="unit_number" inputmode="numeric" value="<?=e($values['unit_number']??'1')?>" required></label>
 <label id="groupField"><span>گروه</span><select name="group_no" id="groupSelect" required><option value="" disabled hidden <?=($values['group_no']??'')===''?'selected':''?>>انتخاب گروه</option><option value="1" <?=((int)($values['group_no']??0)===1?'selected':'')?>>گروه ۱</option><option value="2" <?=((int)($values['group_no']??0)===2?'selected':'')?>>گروه ۲</option><option value="3" <?=((int)($values['group_no']??0)===3?'selected':'')?>>گروه ۳</option></select></label>
 <label><span>تیم</span><select name="team_no" id="teamSelect" required></select></label>
</div>

<div class="section-title wide">حوزه استحفاظی</div>
<div class="organization-fields wide">
    <label class="location-field"><span>شهرستان</span><div class="smart-select" id="citySmart"><button type="button" class="smart-select-trigger" aria-haspopup="listbox" aria-expanded="false" <?=empty($values['province_id'])?'disabled':''?>><span class="smart-select-value">انتخاب شهرستان</span><span class="smart-select-arrow">⌄</span></button><div class="smart-select-menu" role="listbox"><div class="smart-select-search-wrap"><input type="search" class="smart-select-search" placeholder="جست‌وجوی شهرستان" autocomplete="off" autocomplete="off" <?=empty($values['province_id'])?'disabled':''?>></div><div class="smart-select-options"></div></div><select name="city_id" id="citySelect" class="smart-select-native" required <?=empty($values['province_id'])?'disabled':''?>><option value="" disabled hidden <?=empty($values['city_id'])?'selected':''?>>انتخاب شهرستان</option><?php foreach($cities as $c):?><option value="<?=$c['id']?>" <?=((int)($values['city_id']??0)===(int)$c['id'])?'selected':''?>><?=e($c['city_name'])?></option><?php endforeach;?></select></div></label>
<label class="location-field"><span>منطقه</span><div class="smart-select" id="districtSmart"><button type="button" class="smart-select-trigger" aria-haspopup="listbox" aria-expanded="false" <?=$districtSelectable?'':'disabled'?> title="<?=$districtSelectable?'منطقه':'این شهرستان منطقه‌بندی ندارد'?>"><span class="smart-select-value">انتخاب منطقه</span><span class="smart-select-arrow">⌄</span></button><div class="smart-select-menu" role="listbox"><div class="smart-select-search-wrap"><input type="search" class="smart-select-search" placeholder="جست‌وجوی منطقه" autocomplete="off" <?=$districtSelectable?'':'disabled'?>></div><div class="smart-select-options"></div></div><select name="district_id" id="districtSelect" class="smart-select-native" <?=$districtSelectable?'':'disabled'?>><option value="" disabled hidden <?=!$currentDistrict?'selected':''?>>انتخاب منطقه</option><?php foreach($districtsList as $dd):?><option value="<?=$dd['id']?>" <?=$currentDistrict===(int)$dd['id']?'selected':''?>><?=e($dd['district_name'])?></option><?php endforeach;?></select></div></label>
</div>

<div class="section-title wide">عکس و مدارک</div>
<div class="document-upload-card profile-photo-upload-card"><div class="document-upload-icon">▣</div><div><strong>عکس پروفایل</strong><small>یک فایل، حداکثر ۳MB</small></div><label class="file-picker"><span>انتخاب فایل</span><input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp"></label></div>
<div class="documents-grid wide">
 <div class="document-upload-card"><div class="document-upload-icon">▣</div><div><strong>شناسنامه</strong><small>یک فایل، حداکثر 8MB</small></div><label class="file-picker"><span>انتخاب فایل</span><input type="file" name="document_birth_certificate" accept="application/pdf,image/jpeg,image/png,image/webp"></label></div>
 <div class="document-upload-card"><div class="document-upload-icon">▤</div><div><strong>کارت ملی</strong><small>یک فایل، حداکثر 8MB</small></div><label class="file-picker"><span>انتخاب فایل</span><input type="file" name="document_national_card" accept="application/pdf,image/jpeg,image/png,image/webp"></label></div>
 <div class="document-upload-card"><div class="document-upload-icon">▧</div><div><strong>گواهی عدم سوء پیشینه</strong><small>یک فایل، حداکثر 8MB</small></div><label class="file-picker"><span>انتخاب فایل</span><input type="file" name="document_criminal_record" accept="application/pdf,image/jpeg,image/png,image/webp"></label></div>
</div>
<div class="actions wide"><button class="btn primary " type="submit">ثبت</button><a class="btn secondary" href="personnel.php">انصراف</a></div>
</form>
<style>
/* چیدمان مخصوص این صفحه؛ ظاهر دراپ‌داون‌ها از استایل سراسری (بلوک V4.55) می‌آید. */
.location-field{gap:7px!important}
.smart-select{position:relative;width:100%}
</style>
<script>
(function(){
 const unitSelect=document.getElementById('unitSelect'),teamSelect=document.getElementById('teamSelect'),positionSelect=document.getElementById('positionSelect'),groupSelect=document.getElementById('groupSelect'),groupField=document.getElementById('groupField');
 const provinceSelect=document.getElementById('provinceSelect'),citySelect=document.getElementById('citySelect');
 const currentTeam=<?=json_encode((string)($values['team_no']??''),JSON_UNESCAPED_UNICODE)?>;
 const currentGroup=<?=json_encode((string)($values['group_no']??''),JSON_UNESCAPED_UNICODE)?>;
 const currentCity=<?=json_encode((string)($values['city_id']??''),JSON_UNESCAPED_UNICODE)?>;
 function refreshTeams(reset=false){
  const options=unitSelect.value==='information'?[['1','پیاده'],['2','موتوری'],['3','خودرویی']]:[['1','تیم ۱'],['2','تیم ۲'],['3','تیم ۳']];
  const keep=reset?'':(teamSelect.value||currentTeam||'');
  teamSelect.innerHTML='<option value="" disabled hidden selected>انتخاب تیم</option>'+options.map(([v,t])=>`<option value="${v}">${t}</option>`).join('');
  // با عوض‌شدن رسته، گزینه‌ها بازسازی می‌شوند؛ اگر انتخاب قبلی نبود، متن راهنما بماند.
  teamSelect.value = keep || '';
  if(!teamSelect.value) teamSelect.selectedIndex = 0;
 }
 function syncOrganizationFields(){
  const commander=positionSelect.value,unitCommander=commander==='unit_commander',groupCommander=commander==='group_commander';
  groupField.style.display='flex'; groupSelect.disabled=unitCommander; teamSelect.disabled=unitCommander||groupCommander;
  if(unitCommander){groupSelect.value='';teamSelect.value='';} else if(!groupSelect.value && currentGroup) groupSelect.value=currentGroup;
  if(!unitCommander&&!groupCommander&&!teamSelect.value) teamSelect.value=currentTeam||'';
 }
 unitSelect.addEventListener('change',()=>{refreshTeams(true);syncOrganizationFields();}); positionSelect.addEventListener('change',syncOrganizationFields); refreshTeams(false);syncOrganizationFields();

 const faFold=(v)=>String(v??'').replace(/[۰-۹٠-٩]/g,d=>{const i='۰۱۲۳۴۵۶۷۸۹'.indexOf(d);return i>-1?String(i):String('٠١٢٣٤٥٦٧٨٩'.indexOf(d));}).toLocaleLowerCase('fa-IR');
 function setupSmartSelect(root, select, placeholder){
  if(!root||!select)return {setOptions(){},reset(){}};
  const trigger=root.querySelector('.smart-select-trigger'),valueEl=root.querySelector('.smart-select-value'),menu=root.querySelector('.smart-select-menu'),search=root.querySelector('.smart-select-search'),optionsEl=root.querySelector('.smart-select-options');
  function render(){
    const q=faFold((search.value||'').trim());
    const opts=[...select.options].filter(o=>o.value!=='' && !o.disabled);
    const filtered=opts.filter(o=>faFold(o.textContent).includes(q));
    optionsEl.innerHTML=filtered.length?filtered.map(o=>`<div class="smart-select-option ${o.selected?'active':''}" role="option" data-value="${o.value}">${o.textContent}</div>`).join(''):'<div class="smart-select-empty">موردی پیدا نشد</div>';
    // preventDefault لازم است: کل کنترل داخل یک <label> است و کلیک روی گزینه،
    // به‌صورت خودکار دوباره روی دکمهٔ trigger شلیک می‌شد و منو باز می‌ماند.
    optionsEl.querySelectorAll('.smart-select-option').forEach(el=>el.addEventListener('click',ev=>{ev.preventDefault();ev.stopPropagation();select.value=el.dataset.value;select.dispatchEvent(new Event('change',{bubbles:true})); close();}));
  }
  function sync(){const selected=select.selectedOptions[0]; valueEl.textContent=(selected && selected.value) ? selected.textContent.trim() : placeholder; root.classList.toggle('has-value', !!(selected && selected.value));}
  function open(){if(trigger.disabled)return;document.querySelectorAll('.smart-select.open').forEach(x=>x!==root&&x.classList.remove('open'));root.classList.add('open');trigger.setAttribute('aria-expanded','true');search.disabled=false;search.value='';render();setTimeout(()=>search.focus(),0);}
  function close(){root.classList.remove('open');trigger.setAttribute('aria-expanded','false');sync();}
  trigger.addEventListener('click',e=>{e.preventDefault();root.classList.contains('open')?close():open();});
  // هر کلیکی داخل منو نباید به فعال‌سازی <label> و باز/بسته‌شدن دوبارهٔ منو منجر شود.
  menu.addEventListener('click',e=>{e.preventDefault();});
  search.addEventListener('input',render); search.addEventListener('click',e=>e.stopPropagation());
  select.addEventListener('change',()=>{sync(); close();});
  sync(); render();
  return {setOptions(){sync();render();},reset(){select.value='';search.value='';sync();render();},close};
 }
 const provinceUI=setupSmartSelect(document.getElementById('provinceSmart'),provinceSelect,'انتخاب استان');
 const cityUI=setupSmartSelect(document.getElementById('citySmart'),citySelect,'انتخاب شهرستان');
 const districtSelect=document.getElementById('districtSelect');
 const districtUI=setupSmartSelect(document.getElementById('districtSmart'),districtSelect,'انتخاب منطقه');
 function setDistrictDisabled(disabled,cityChosen){const root=document.getElementById('districtSmart');if(!root)return;const t=root.querySelector('.smart-select-trigger'),se=root.querySelector('.smart-select-search');districtSelect.disabled=disabled;t.disabled=disabled;se.disabled=disabled;t.title=disabled?(cityChosen?'این شهرستان منطقه‌بندی ندارد':'ابتدا شهرستان را انتخاب کنید'):'منطقه';if(disabled)root.classList.remove('open');}
 function loadDistricts(){
  districtSelect.innerHTML='<option value="" disabled hidden selected>انتخاب منطقه</option>';
  districtUI.setOptions();
  if(!citySelect.value){setDistrictDisabled(true,false);return;}
  fetch('personnel_form.php?action=districts&city_id='+encodeURIComponent(citySelect.value),{headers:{'Accept':'application/json'}})
   .then(r=>r.json())
   .then(rows=>{districtSelect.innerHTML='<option value="" disabled hidden selected>انتخاب منطقه</option>'+rows.map(d=>`<option value="${d.id}">${d.district_name}</option>`).join('');districtUI.setOptions();setDistrictDisabled(rows.length<2,true); districtUI.setOptions();})
   .catch(()=>setDistrictDisabled(true,true));
 }
 citySelect.addEventListener('change',loadDistricts);
 function setCityDisabled(disabled){const root=document.getElementById('citySmart'),trigger=root.querySelector('.smart-select-trigger'),search=root.querySelector('.smart-select-search');citySelect.disabled=disabled;trigger.disabled=disabled;search.disabled=disabled;if(disabled)root.classList.remove('open');}
 function resetCitySelect(){ citySelect.innerHTML='<option value="" disabled hidden selected>انتخاب شهرستان</option>'; setCityDisabled(true); cityUI.setOptions(); }
 provinceSelect.addEventListener('change',()=>{provinceUI.close?.(); resetCitySelect(); setDistrictDisabled(true,false); districtSelect.innerHTML='<option value="" disabled hidden selected>انتخاب منطقه</option>'; districtUI.setOptions(); if(!provinceSelect.value)return; setCityDisabled(false); fetch('personnel_form.php?action=cities&province_id='+encodeURIComponent(provinceSelect.value),{headers:{'Accept':'application/json'}}).then(r=>r.json()).then(rows=>{citySelect.innerHTML='<option value="" disabled hidden selected>انتخاب شهرستان</option>'+rows.map(x=>`<option value="${x.id}">${x.city_name}</option>`).join(''); cityUI.setOptions(); if(currentCity && rows.some(x=>String(x.id)===String(currentCity))){citySelect.value=currentCity;cityUI.setOptions();} if(!rows.length)setCityDisabled(true);}).catch(()=>{resetCitySelect();});});
 if(currentCity) citySelect.value=currentCity;
 document.addEventListener('click',e=>{document.querySelectorAll('.smart-select.open').forEach(root=>{if(!root.contains(e.target)){const trigger=root.querySelector('.smart-select-trigger');root.classList.remove('open');trigger?.setAttribute('aria-expanded','false');}});});

 const toEnglishDigits=s=>(s||'').replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/\D/g,'');
 function maskJalali(el){let v=toEnglishDigits(el.value).slice(0,8),out=v.slice(0,4);if(v.length>4)out+='/'+v.slice(4,6);if(v.length>6)out+='/'+v.slice(6,8);el.value=out.replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d]);}
 // فقط شماره شبا انگلیسی می‌ماند؛ بقیه ارقام را اسکریپت مشترک فوتر فارسی می‌کند.
 document.querySelectorAll('input.jalali').forEach(el=>{el.addEventListener('input',()=>maskJalali(el));el.addEventListener('paste',()=>setTimeout(()=>maskJalali(el),0));maskJalali(el);});
 // شبا: کادر ورودی با ارقام فارسی دیده می‌شود، ولی مقدار ارسالی به سرور لاتین است.
const ibanDigits=document.querySelector('input[name="iban_digits"]'),ibanHidden=document.querySelector('input[name="iban"]');
const syncIban=()=>{ if(!ibanDigits||!ibanHidden) return;
  const latin=toEnglishDigits(ibanDigits.value).replace(/\D/g,'').slice(0,24);
  ibanDigits.value=latin.replace(/[0-9]/g,d=>'۰۱۲۳۴۵۶۷۸۹'[+d]);
  ibanHidden.value=latin?'IR'+latin:''; };
ibanDigits?.addEventListener('input',syncIban); syncIban();
 document.querySelectorAll('input[name="first_name"],input[name="last_name"],input[name="father_name"],input[name="secondary_job"]').forEach(el=>el.addEventListener('input',()=>{el.value=el.value.replace(/[0-9۰-۹]/g,'');}));
})();
</script>
<?php require __DIR__.'/../app/partials/footer.php'; ?>
