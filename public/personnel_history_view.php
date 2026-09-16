<?php require __DIR__ . '/../app/bootstrap.php'; require_login();

/* دو حالت ورودی: person=<کد عنصر> (از فهرست تاریخچه) یا id=<کد یک نسخه> (لینک‌های قدیمی) */
$id       = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$personId = filter_input(INPUT_GET, 'person', FILTER_VALIDATE_INT);

if ($personId) {
    $chk = $pdo->prepare('SELECT COUNT(*) FROM personnel_history WHERE personnel_id=?');
    $chk->execute([$personId]);
    if (!(int)$chk->fetchColumn()) { http_response_code(404); exit('سابقه پیدا نشد.'); }
    $personnelId = $personId;
    $id = 0; // هیچ ردیفی از پیش انتخاب نشده است
} else {
    if (!$id) { http_response_code(404); exit('سابقه پیدا نشد.'); }
    $owner = $pdo->prepare('SELECT personnel_id FROM personnel_history WHERE id=?');
    $owner->execute([$id]);
    $personnelId = (int)$owner->fetchColumn();
    if (!$personnelId) { http_response_code(404); exit('سابقه پیدا نشد.'); }
}

// نسخه فعلی پرونده = حالت «پس از تغییر» برای آخرین ویرایش
$cur = $pdo->prepare(personnel_select_sql('p') . ' WHERE p.id=?');
$cur->execute([$personnelId]);
$current = $cur->fetch();
if (!$current || !can_view_unit($current['unit'])) { http_response_code(404); exit('رکورد یافت نشد.'); }

// همه نسخه‌های ذخیره‌شده، از قدیم به جدید
$hst = $pdo->prepare('SELECT h.*,ct.category_name AS unit_label,cn.unit_number,g.group_number AS group_no,t.team_name AS team_name,pos.position_name,pr.province_name,ci.city_name,u.full_name AS changed_by_name
 FROM personnel_history h
 JOIN category_types ct ON ct.id=h.category_type_id
 JOIN category_numbers cn ON cn.id=h.category_number_id
 JOIN positions pos ON pos.id=h.position_id
 LEFT JOIN provinces pr ON pr.id=h.province_id
 LEFT JOIN cities ci ON ci.id=h.city_id
 LEFT JOIN personnel_groups g ON g.id=h.group_id
 LEFT JOIN teams t ON t.id=h.team_id
 LEFT JOIN users u ON u.id=h.changed_by
 WHERE h.personnel_id=? ORDER BY h.changed_at ASC, h.id ASC');
$hst->execute([$personnelId]);
$versions = $hst->fetchAll();

/* history_fields() در app/bootstrap.php تعریف شده تا فهرست تاریخچه هم از همان منطق استفاده کند. */

/**
 * هر رکورد تاریخچه «پیش از تغییر» است و نسخه بعدی (یا پرونده فعلی) «پس از تغییر».
 * خروجی: رویدادهای تغییر، از جدید به قدیم.
 */
$events = [];
$snapshots = $versions; $snapshots[] = $current;
foreach ($versions as $i => $before) {
    $beforeFields = history_fields($before);
    $afterFields  = history_fields($snapshots[$i + 1]);
    $changes = [];
    foreach ($beforeFields as $label => $old) {
        $new = (string)($afterFields[$label] ?? '');
        if (trim((string)$old) === trim($new)) continue;
        $changes[] = ['label'=>$label, 'old'=>(string)$old, 'new'=>$new];
    }
    if (!$changes) continue; // ویرایشی که هیچ فیلد نمایشی را تغییر نداده است
    $events[] = ['id'=>(int)$before['id'], 'at'=>$before['changed_at'], 'by'=>($before['changed_by_name'] ?: '—'), 'changes'=>$changes];
}
$events = array_reverse($events);
$changeCount = array_sum(array_map(static fn($e) => count($e['changes']), $events));
$personName = trim((string)($current['full_name'] ?? '')) ?: trim($current['first_name'].' '.$current['last_name']);

require __DIR__ . '/../app/partials/header.php'; ?>
<section class="page-head"><div><h1>سوابق تغییرات پرونده</h1><p><?=e($personName)?> · <?=fa_digits((string)count($events))?> ویرایش · <?=fa_digits((string)$changeCount)?> تغییر</p></div></section>
<div class="panel change-panel">
<?php if($events): ?>
  <div class="change-table-wrap"><table class="change-table">
    <thead><tr><th class="c-row">ردیف</th><th class="c-date">تاریخ</th><th class="c-title">عنوان تغییر</th><th class="c-old">پیش از تغییر</th><th class="c-new">پس از تغییر</th></tr></thead>
    <tbody>
    <?php $n=0; $stepTotal=count($events); foreach($events as $eventIndex=>$event):
      $dateText=jalali_display($event['at']);
      // رویدادها از جدید به قدیم چیده شده‌اند؛ شماره مرحله از قدیمی‌ترین ویرایش می‌شمارد.
      $stepNo = $stepTotal - $eventIndex; ?>
      <tr class="change-step"><td colspan="5"><div class="change-step-line">
        <span class="change-step-no">ویرایش <?=fa_digits((string)$stepNo)?> از <?=fa_digits((string)$stepTotal)?></span>
        <span class="change-step-meta"><?=e($dateText)?></span>
        <span class="change-step-meta">ویرایش‌کننده: <?=e($event['by'])?></span>
        <span class="change-step-count"><?=fa_digits((string)count($event['changes']))?> تغییر</span>
      </div></td></tr>
      <?php foreach($event['changes'] as $change): $n++; ?>
        <tr<?= (int)$event['id']===(int)$id ? ' class="is-selected"' : '' ?>>
          <td class="c-row"><?=fa_digits((string)$n)?></td>
          <td class="c-date"><?=e($dateText)?></td>
          <td class="c-title"><?=e($change['label'])?></td>
          <td class="c-old"><?=e($change['old']!==''?$change['old']:'—')?></td>
          <td class="c-new"><?=e($change['new']!==''?$change['new']:'—')?></td>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php else: ?>
  <div class="personnel-empty"><strong>تغییری برای نمایش نیست.</strong><span>هیچ‌کدام از فیلدهای این پرونده تا کنون تغییر نکرده است.</span></div>
<?php endif; ?>
</div>
<style>
.change-panel{padding:18px;overflow:hidden}
.change-table-wrap{width:100%;overflow:hidden}
.change-table{width:100%;border-collapse:collapse;table-layout:fixed}
.change-table th,.change-table td{padding:11px 10px;border-bottom:1px solid #edf1ef;text-align:center;vertical-align:middle;font-size:12px;overflow-wrap:anywhere}
.change-table th{background:#f7faf8;color:#66756e;font-weight:800;white-space:nowrap}
.change-table td{color:#283b31}
.change-table .c-row{width:58px}
.change-table .c-date{width:112px;white-space:nowrap;color:#6c7a73}
.change-table .c-title{width:158px;font-weight:800}
.change-table .c-old,.change-table .c-new{color:#283b31}
.change-table tbody tr:hover{background:#fbfdfb}
.change-table tr.is-selected{background:#f4faf6}
.change-step td{background:#f4f8f6;border-top:1px solid #e2ebe6;border-bottom:1px solid #e2ebe6;
  padding:9px 13px;text-align:right}
.change-step-line{display:flex;align-items:center;flex-wrap:wrap;gap:8px 16px;width:100%}
.change-step-no{display:inline-flex;align-items:center;justify-content:center;padding:4px 11px;border-radius:999px;
  background:#e4f0e9;color:#245b40;font-size:11px;font-weight:900;white-space:nowrap}
.change-step-meta{color:#7d8b84;font-size:11px;font-weight:800;white-space:nowrap}
.change-step-count{margin-inline-start:auto;padding:3px 10px;border-radius:999px;background:#fff;
  border:1px solid #dfe8e3;color:#5b6a63;font-size:11px;font-weight:800;white-space:nowrap}
@media(max-width:760px){
 .change-table-wrap{overflow:visible}
 .change-table,.change-table tbody,.change-table tr,.change-table td{display:block;width:100%;box-sizing:border-box}
 .change-table thead{display:none}
 .change-table tbody tr{padding:10px;border:1px solid #e4ece7;border-radius:13px;margin-bottom:9px;background:#fff}
 .change-table tbody tr.change-step{padding:8px 10px;border-radius:10px;background:#f4f8f6;border-color:#e2ebe6}
 .change-table td{border:0;padding:6px 4px;text-align:right;display:flex;justify-content:space-between;gap:12px}
 .change-table td::before{font-weight:800;color:#8a9891}
 .change-table td::before{white-space:nowrap;flex:0 0 auto}
 .change-table td.c-row,.change-table td.c-date{white-space:nowrap}
 .change-table td.c-row::before{content:'ردیف'}
 .change-table td.c-date::before{content:'تاریخ'}
 .change-table td.c-title::before{content:'عنوان تغییر'}
 .change-table td.c-old::before{content:'پیش از تغییر'}
 .change-table td.c-new::before{content:'پس از تغییر'}
 .change-step td::before{content:''}
}
</style>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
