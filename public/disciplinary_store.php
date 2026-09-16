<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
if (!can_manage_personnel()) { http_response_code(403); exit('دسترسی غیرمجاز'); }
verify_csrf();

$id = filter_input(INPUT_POST, 'personnel_id', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(400); exit('رکورد نامعتبر است.'); }

$returnTo = 'personnel_view.php?id=' . (int)$id . '&tab=disciplinary';

$reportType   = trim((string)($_POST['report_type'] ?? ''));
$dateInput    = trim((string)($_POST['report_date_jalali'] ?? ''));
$reason       = trim((string)($_POST['reason'] ?? ''));
$subjectTitle = trim((string)($_POST['subject_title'] ?? ''));

if (!in_array($reportType, ['encouragement','warning','reprimand'], true)) redirect($returnTo . '&disc_error=type');
if ($reason === '' || $subjectTitle === '') redirect($returnTo . '&disc_error=1&disc_type=' . $reportType);

$reportDate = null;
if ($dateInput !== '') {
    $reportDate = parse_jalali_input($dateInput);
    if ($reportDate === null) redirect($returnTo . '&disc_error=date&disc_type=' . $reportType);
}

$st = $pdo->prepare(personnel_select_sql('p') . ' WHERE p.id=?');
$st->execute([$id]);
$person = $st->fetch();
if (!$person || !can_view_unit($person['unit'])) { http_response_code(404); exit('رکورد یافت نشد.'); }
block_if_dismissed($person, 'ثبت پرونده انضباطی');

try {
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM disciplinary_reports') as $col) { $columns[strtolower($col['Field'])] = $col; }
    if (!isset($columns['subject_title']) || !isset($columns['report_date'])) {
        throw new RuntimeException('upgrade_v4.41');
    }
    // اگر subject_id هنوز NOT NULL است، مایگریشن کامل اجرا نشده و متن آزاد قابل ثبت نیست.
    if (($columns['subject_id']['Null'] ?? 'NO') !== 'YES') {
        throw new RuntimeException('upgrade_v4.41');
    }

    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT INTO disciplinary_reports(personnel_id,report_type,subject_id,subject_title,report_date,reason,created_by) VALUES(?,?,NULL,?,?,?,?)');
    $ins->execute([$id, $reportType, mb_substr($subjectTitle, 0, 120), $reportDate, mb_substr($reason, 0, 255), user()['id'] ?? null]);
    // هر ۳ اخطار => ۱ توبیخ خودکار، و ۳ توبیخ => برکناری خودکار
    $rules = apply_disciplinary_rules((int)$id, user()['id'] ?? null);
    $pdo->commit();

    $extra = '';
    if ($rules['auto_reprimands'] > 0) $extra .= '&disc_auto=' . (int)$rules['auto_reprimands'];
    if ($rules['dismissed']) $extra .= '&auto_dismissed=1';
    redirect($returnTo . '&disc_saved=' . $reportType . $extra);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[disciplinary_store] ' . $e->getMessage());
    $flag = str_contains($e->getMessage(), 'upgrade_v4.41') ? 'schema' : 'save';
    redirect($returnTo . '&disc_error=' . $flag . '&disc_type=' . $reportType);
}
