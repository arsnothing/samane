<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
if (!can_manage_personnel()) { http_response_code(403); exit('دسترسی غیرمجاز'); }
verify_csrf();

$courseOptions = training_course_options();

$id = filter_input(INPUT_POST, 'personnel_id', FILTER_VALIDATE_INT);
$courseKey = trim((string)($_POST['course_key'] ?? ''));
$dateInput = trim((string)($_POST['training_date_jalali'] ?? ''));
$returnQuery = (string)($_POST['return_query'] ?? '');

parse_str($returnQuery, $returnParams);
$allowedReturnKeys = ['course_key','q','province_id','city_id','filter','category_number'];
$returnParams = array_intersect_key(is_array($returnParams) ? $returnParams : [], array_flip($allowedReturnKeys));
$back = static function (string $flag) use ($returnParams): never {
    $params = $returnParams;
    $params[str_starts_with($flag, 'saved') ? 'saved' : 'error'] = str_starts_with($flag, 'saved') ? '1' : $flag;
    redirect('training.php?' . http_build_query($params));
};

if (!$id) $back('person');
if (!isset($courseOptions[$courseKey])) $back('course');

$trainingDate = null;
if ($dateInput !== '') {
    $trainingDate = parse_jalali_input($dateInput);
    if ($trainingDate === null) $back('date');
}

$st = $pdo->prepare(personnel_select_sql('p') . ' WHERE p.id=?');
$st->execute([$id]);
$person = $st->fetch();
if (!$person || !can_view_unit($person['unit'])) $back('person');
if (person_is_dismissed($person)) $back('dismissed');

// اسناد دوره: حداکثر ۵ فایل، هرکدام تا ۸ مگابایت
$uploads = [];
if (!empty($_FILES['training_documents']['name'][0])) {
    $allowedMime = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $files = $_FILES['training_documents'];
    $count = min(count($files['name']), 5);
    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || $files['name'][$i] === '') continue;
        if ($files['error'][$i] !== UPLOAD_ERR_OK || $files['size'][$i] > 8 * 1024 * 1024) $back('file');
        if (!is_uploaded_file($files['tmp_name'][$i])) $back('file');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($files['tmp_name'][$i]);
        if (!isset($allowedMime[$mime])) $back('file');
        $uploads[] = ['tmp'=>$files['tmp_name'][$i],'name'=>$files['name'][$i],'mime'=>$mime,'size'=>(int)$files['size'][$i],'ext'=>$allowedMime[$mime]];
    }
}

try {
    $hasDate = (bool)$pdo->query("SHOW COLUMNS FROM training_records LIKE 'training_date'")->fetch();
    if (!$hasDate && $trainingDate !== null) throw new RuntimeException('upgrade_v4.41');

    $pdo->beginTransaction();

    if ($hasDate) {
        $ins = $pdo->prepare('INSERT INTO training_records(personnel_id,course_key,course_name,status,training_date,created_by,updated_by) VALUES(?,?,?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE course_name=VALUES(course_name),status=VALUES(status),training_date=VALUES(training_date),updated_by=VALUES(updated_by),id=LAST_INSERT_ID(id)');
        $ins->execute([$id, $courseKey, $courseOptions[$courseKey], 'completed', $trainingDate, user()['id'] ?? null, user()['id'] ?? null]);
    } else {
        $ins = $pdo->prepare('INSERT INTO training_records(personnel_id,course_key,course_name,status,created_by,updated_by) VALUES(?,?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE course_name=VALUES(course_name),status=VALUES(status),updated_by=VALUES(updated_by),id=LAST_INSERT_ID(id)');
        $ins->execute([$id, $courseKey, $courseOptions[$courseKey], 'completed', user()['id'] ?? null, user()['id'] ?? null]);
    }

    $trainingId = (int)$pdo->lastInsertId();
    if ($trainingId < 1) {
        $q = $pdo->prepare('SELECT id FROM training_records WHERE personnel_id=? AND course_key=? LIMIT 1');
        $q->execute([$id, $courseKey]);
        $trainingId = (int)$q->fetchColumn();
    }
    if ($trainingId < 1) throw new RuntimeException('شناسه دوره یافت نشد.');

    if ($uploads) {
        $doc = $pdo->prepare('INSERT INTO training_documents(training_record_id,original_name,stored_name,mime_type,file_size,created_by) VALUES(?,?,?,?,?,?)');
        foreach ($uploads as $file) {
            $stored = 'training_' . (int)$id . '_' . bin2hex(random_bytes(10)) . '.' . $file['ext'];
            if (!move_uploaded_file($file['tmp'], storage_path('documents') . $stored)) {
                throw new RuntimeException('ذخیره یکی از اسناد دوره انجام نشد.');
            }
            $doc->execute([$trainingId, $file['name'], $stored, $file['mime'], $file['size'], user()['id'] ?? null]);
        }
    }

    $pdo->commit();
    $back('saved');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[training_store] ' . $e->getMessage());
    $back(str_contains($e->getMessage(), 'upgrade_v4.41') ? 'schema' : 'save');
}
