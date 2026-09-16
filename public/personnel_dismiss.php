<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
if (!can_manage_personnel()) { http_response_code(403); exit('دسترسی غیرمجاز'); }
verify_csrf();

$id = filter_input(INPUT_POST, 'personnel_id', FILTER_VALIDATE_INT);
$dismissalType = trim((string)($_POST['dismissal_type'] ?? ''));
$dismissalDateInput = trim((string)($_POST['dismissal_date_jalali'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));
$returnTo = trim((string)($_POST['return_to'] ?? ''));
$allowedDismissalTypes = ['deputy','unit_commander','group_commander','resignation'];

if (!$id) { http_response_code(400); exit('رکورد نامعتبر است.'); }
if ($returnTo === '' || !preg_match('/^(disciplinary\.php\?id=\d+|disciplinary_reports\.php\?personnel_id=\d+|personnel_view\.php\?id=\d+&tab=disciplinary)$/', $returnTo)) {
    $returnTo = 'personnel_view.php?id=' . (int)$id . '&tab=disciplinary';
}

// «نوع برکناری» از فرم حذف شده است؛ اگر ارسال نشود مقدار پیش‌فرض در نظر گرفته می‌شود.
if (!in_array($dismissalType, $allowedDismissalTypes, true)) $dismissalType = null;

if ($reason === '') redirect($returnTo . '&dismiss_error=1');

$dismissalDate = null;
if ($dismissalDateInput !== '') {
    $dismissalDate = parse_jalali_input($dismissalDateInput);
    if ($dismissalDate === null) redirect($returnTo . '&dismiss_error=date');
}

$st = $pdo->prepare(personnel_select_sql('p') . ' WHERE p.id=?');
$st->execute([$id]);
$person = $st->fetch();
if (!$person || !can_view_unit($person['unit'])) { http_response_code(404); exit('رکورد یافت نشد.'); }

// اسناد برکناری: حداکثر ۵ فایل، هرکدام تا ۸ مگابایت
$uploads = [];
if (!empty($_FILES['dismissal_documents']['name'][0])) {
    $allowedMime = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $files = $_FILES['dismissal_documents'];
    $count = min(count($files['name']), 5);
    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || $files['name'][$i] === '') continue;
        if ($files['error'][$i] !== UPLOAD_ERR_OK || $files['size'][$i] > 8 * 1024 * 1024) redirect($returnTo . '&dismiss_error=file');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($files['tmp_name'][$i]);
        if (!isset($allowedMime[$mime])) redirect($returnTo . '&dismiss_error=file');
        $uploads[] = [
            'tmp' => $files['tmp_name'][$i],
            'name' => $files['name'][$i],
            'mime' => $mime,
            'size' => (int)$files['size'][$i],
            'ext' => $allowedMime[$mime],
        ];
    }
}

try {
    // Make schema mismatch explicit rather than hiding the real DB error.
    $check = $pdo->query("SHOW COLUMNS FROM personnel_dismissals LIKE 'dismissal_date'");
    $hasDismissalDate = (bool)$check->fetch();
    if (!$hasDismissalDate && $dismissalDate !== null) {
        throw new RuntimeException('ستون dismissal_date در جدول personnel_dismissals وجود ندارد. فایل database/upgrade_v4.41.sql را اجرا کنید.');
    }

    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT personnel_status FROM personnel WHERE id=? FOR UPDATE');
    $lock->execute([$id]);
    $status = $lock->fetchColumn();
    if ($status === false) throw new RuntimeException('رکورد یافت نشد.');

    $exists = $pdo->prepare('SELECT id FROM personnel_dismissals WHERE personnel_id=? LIMIT 1');
    $exists->execute([$id]);
    $dismissalId = $exists->fetchColumn();

    $dateColumn = $hasDismissalDate ? ', dismissal_date=?' : '';
    if ($dismissalId) {
        $sql = 'UPDATE personnel_dismissals SET dismissal_type=?, reason=?' . $dateColumn . ', dismissed_by=?, dismissed_at=CURRENT_TIMESTAMP WHERE id=?';
        $params = $hasDismissalDate
            ? [$dismissalType, $reason, $dismissalDate, user()['id'] ?? null, (int)$dismissalId]
            : [$dismissalType, $reason, user()['id'] ?? null, (int)$dismissalId];
        $pdo->prepare($sql)->execute($params);
    } else {
        $sql = $hasDismissalDate
            ? 'INSERT INTO personnel_dismissals(personnel_id,dismissal_type,reason,dismissal_date,dismissed_by) VALUES(?,?,?,?,?)'
            : 'INSERT INTO personnel_dismissals(personnel_id,dismissal_type,reason,dismissed_by) VALUES(?,?,?,?)';
        $params = $hasDismissalDate
            ? [$id, $dismissalType, $reason, $dismissalDate, user()['id'] ?? null]
            : [$id, $dismissalType, $reason, user()['id'] ?? null];
        $pdo->prepare($sql)->execute($params);
    }

    if ($uploads) {
        $insDoc = $pdo->prepare('INSERT INTO personnel_documents(personnel_id,document_type,original_name,stored_name,mime_type,file_size,created_by) VALUES(?,?,?,?,?,?,?)');
        foreach ($uploads as $file) {
            $stored = 'dismissal_' . (int)$id . '_' . bin2hex(random_bytes(10)) . '.' . $file['ext'];
            if (!move_uploaded_file($file['tmp'], storage_path('documents') . $stored)) {
                throw new RuntimeException('ذخیره یکی از اسناد برکناری انجام نشد.');
            }
            $insDoc->execute([$id, 'dismissal', $file['name'], $stored, $file['mime'], $file['size'], user()['id'] ?? null]);
        }
    }

    $up = $pdo->prepare("UPDATE personnel SET personnel_status='dismissed', updated_by=? WHERE id=?");
    $up->execute([user()['id'] ?? null, $id]);
    $pdo->commit();

    redirect($returnTo . '&dismissed=1');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[personnel_dismiss] ' . $e->getMessage());
    $flag = str_contains($e->getMessage(), 'upgrade_v4.41') ? 'schema' : '1';
    redirect($returnTo . '&dismiss_error=' . $flag);
}
