<?php
require __DIR__ . '/../app/bootstrap.php'; require_login();
$type=$_GET['type']??'';$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
// عکس‌ها هرگز کش نشوند؛ وگرنه پس از جایگزینی عکس پروفایل (یا پس از اولین ۴۰۴)
// مرورگر همان پاسخ قدیمی را نشان می‌دهد و عکس تازه دیده نمی‌شود.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');header('Pragma: no-cache');
if($type==='photo'){$st=$pdo->prepare('SELECT p.id,ct.category_key AS unit,p.profile_photo_path FROM personnel p JOIN category_types ct ON ct.id=p.category_type_id WHERE p.id=?');$st->execute([$id]);$r=$st->fetch();if(!$r||!can_view_unit($r['unit'])||!$r['profile_photo_path']){http_response_code(404);exit('فایل یافت نشد.');}$path=__DIR__.'/../storage/profile_photos/'.$r['profile_photo_path'];$mime=is_file($path)?(mime_content_type($path)?:'application/octet-stream'):'';}
elseif($type==='document'){$st=$pdo->prepare('SELECT d.*,ct.category_key AS unit FROM personnel_documents d JOIN personnel p ON p.id=d.personnel_id JOIN category_types ct ON ct.id=p.category_type_id WHERE d.id=?');$st->execute([$id]);$r=$st->fetch();if(!$r||!can_view_unit($r['unit'])){http_response_code(404);exit('فایل یافت نشد.');}$path=__DIR__.'/../storage/documents/'.$r['stored_name'];$mime=$r['mime_type'];}
elseif($type==='training'){$st=$pdo->prepare('SELECT td.*,ct.category_key AS unit FROM training_documents td JOIN training_records tr ON tr.id=td.training_record_id JOIN personnel p ON p.id=tr.personnel_id JOIN category_types ct ON ct.id=p.category_type_id WHERE td.id=?');$st->execute([$id]);$r=$st->fetch();if(!$r||!can_view_unit($r['unit'])){http_response_code(404);exit('فایل یافت نشد.');}$path=__DIR__.'/../storage/documents/'.$r['stored_name'];$mime=$r['mime_type'];}
elseif($type==='order'){$st=$pdo->prepare('SELECT od.*,ct.category_key AS unit FROM personnel_order_documents od JOIN personnel_orders o ON o.id=od.order_id JOIN personnel p ON p.id=o.personnel_id JOIN category_types ct ON ct.id=p.category_type_id WHERE od.id=?');$st->execute([$id]);$r=$st->fetch();if(!$r||!can_view_unit($r['unit'])){http_response_code(404);exit('فایل یافت نشد.');}$path=__DIR__.'/../storage/documents/'.$r['stored_name'];$mime=$r['mime_type'];}
else{http_response_code(400);exit('درخواست نامعتبر.');}
if(!is_file($path)){http_response_code(404);exit('فایل یافت نشد.');}
header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('X-Content-Type-Options: nosniff');readfile($path);
