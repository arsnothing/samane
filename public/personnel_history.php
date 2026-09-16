<?php require __DIR__ . '/../app/bootstrap.php'; require_login();

/* هر عنصر فقط یک بار در فهرست می‌آید؛ مراحل تغییرات با دکمه «مشاهده تغییرات» باز می‌شود. */
$q = fa_to_en_digits(trim((string)($_GET['q'] ?? '')));

$sortable = ['changed_at','person','national_id','position','unit','unit_number','edits'];
$sort = $_GET['sort'] ?? 'changed_at';
if (!in_array($sort, $sortable, true)) $sort = 'changed_at';
$direction = strtolower((string)($_GET['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

$allowed = allowed_units();
$place   = implode(',', array_fill(0, count($allowed), '?'));
$where   = ['ct.category_key IN (' . $place . ')'];
$params  = $allowed;

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = "(CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR p.national_id LIKE ? OR p.mobile LIKE ? OR COALESCE(u.full_name, '') LIKE ?)";
    array_push($params, $like, $like, $like, $like);
}

/* ۱) عناصری که سابقه دارند (با فیلتر دسترسی و جست‌وجو) */
$people = $pdo->prepare(
    'SELECT DISTINCT p.id AS personnel_id, p.first_name, p.last_name, p.national_id,
            pos.position_name, ct.category_name AS unit_label, cn.unit_number
     FROM personnel p
     JOIN personnel_history hh ON hh.personnel_id = p.id
     JOIN positions pos        ON pos.id = p.position_id
     JOIN category_types ct    ON ct.id  = p.category_type_id
     JOIN category_numbers cn  ON cn.id  = p.category_number_id
     LEFT JOIN personnel_history lh ON lh.personnel_id = p.id
     LEFT JOIN users u              ON u.id = lh.changed_by
     WHERE ' . implode(' AND ', $where)
);
$people->execute($params);
$people = $people->fetchAll();

$rows = [];
if ($people) {
    $ids = array_map(static fn($r) => (int)$r['personnel_id'], $people);
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    /* ۲) همه نسخه‌های ذخیره‌شده این افراد، با همان ستون‌هایی که صفحه «مشاهده تغییرات» می‌خواند */
    $hq = $pdo->prepare(
        'SELECT h.*, ct.category_name AS unit_label, cn.unit_number, g.group_number AS group_no,
                t.team_name AS team_name, pos.position_name, pr.province_name, ci.city_name,
                u.full_name AS changed_by_name
         FROM personnel_history h
         JOIN category_types ct   ON ct.id = h.category_type_id
         JOIN category_numbers cn ON cn.id = h.category_number_id
         JOIN positions pos       ON pos.id = h.position_id
         LEFT JOIN provinces pr   ON pr.id = h.province_id
         LEFT JOIN cities ci      ON ci.id = h.city_id
         LEFT JOIN personnel_groups g ON g.id = h.group_id
         LEFT JOIN teams t        ON t.id = h.team_id
         LEFT JOIN users u        ON u.id = h.changed_by
         WHERE h.personnel_id IN (' . $ph . ')
         ORDER BY h.personnel_id ASC, h.changed_at ASC, h.id ASC'
    );
    $hq->execute($ids);
    $historyRows = $hq->fetchAll();

    /* ۳) پرونده فعلی هر عنصر = حالت «پس از تغییر» آخرین ویرایش */
    $cq = $pdo->prepare(personnel_select_sql('p') . ' WHERE p.id IN (' . $ph . ')');
    $cq->execute($ids);
    $currentById = [];
    foreach ($cq->fetchAll() as $c) $currentById[(int)$c['id']] = $c;

    $summary = history_change_summary($historyRows, $currentById);

    foreach ($people as $person) {
        $pid = (int)$person['personnel_id'];
        if (!isset($summary[$pid])) continue; // هیچ ویرایشی فیلد نمایشی را عوض نکرده است
        $rows[] = $person + [
            'edit_count'      => $summary[$pid]['count'],
            'change_count'    => $summary[$pid]['changes'],
            'last_changed'    => $summary[$pid]['last_at'],
            'changed_by_name' => $summary[$pid]['last_by'],
        ];
    }

    /* ۴) مرتب‌سازی */
    $dir = $direction === 'asc' ? 1 : -1;
    usort($rows, static function ($a, $b) use ($sort, $dir) {
        switch ($sort) {
            case 'person':      $x = $a['first_name'].' '.$a['last_name']; $y = $b['first_name'].' '.$b['last_name']; break;
            case 'national_id': $x = (string)$a['national_id'];  $y = (string)$b['national_id'];  break;
            case 'position':    $x = (string)$a['position_name']; $y = (string)$b['position_name']; break;
            case 'unit':        $x = (string)$a['unit_label'];    $y = (string)$b['unit_label'];    break;
            case 'unit_number': $x = (int)$a['unit_number'];      $y = (int)$b['unit_number'];      break;
            case 'edits':       $x = (int)$a['edit_count'];       $y = (int)$b['edit_count'];       break;
            default:            $x = (string)$a['last_changed'];  $y = (string)$b['last_changed'];  break;
        }
        if ($x === $y) return ((int)$a['personnel_id'] <=> (int)$b['personnel_id']) * -1;
        return (is_int($x) ? ($x <=> $y) : strcmp((string)$x, (string)$y)) * $dir;
    });
}

$totalEdits = 0;
foreach ($rows as $r) $totalEdits += (int)$r['edit_count'];

require __DIR__ . '/../app/partials/header.php'; ?>
<section class="page-head"><div><h1>تاریخچه تغییرات</h1></div></section>
<form class="history-filters" method="get">
  <input type="search" name="q" value="<?=e($q)?>" placeholder="جستجو نام و نام‌خانوادگی، کد ملی، موبایل یا کاربر ویرایش‌کننده" autocomplete="off">
  <button class="btn secondary" type="submit">جستجو</button>
</form>
<div class="panel history-list-panel">
  <div class="history-list-head">
    <div>
      <h2>سوابق تغییرات</h2>
      <p><?=fa_digits((string)count($rows))?> عنصر، مجموعاً <?=fa_digits((string)$totalEdits)?> ویرایش</p>
    </div>
  </div>
  <?php if($rows): ?>
  <div class="history-table-wrap"><table class="history-table"><thead><tr>
    <th>ردیف</th>
    <?php
    $heads = [
      ['person','نام و نام خانوادگی'],
      ['national_id','کد ملی'],
      ['position','سمت'],
      ['unit','رسته'],
      ['unit_number','شماره دسته'],
      ['edits','تعداد ویرایش'],
      ['changed_at','آخرین تغییر'],
      ['changed_by','آخرین ویرایش‌کننده'],
      ['view','مشاهده'],
    ];
    foreach($heads as [$key,$label]): ?>
      <?php if($key==='view' || $key==='changed_by'): ?>
        <th><?=e($label)?></th>
      <?php else:
        $next = ($sort===$key && $direction==='asc') ? 'desc' : 'asc';
        $url  = '?'.e(http_build_query(array_merge($_GET,['sort'=>$key,'direction'=>$next]))); ?>
        <th><a href="<?=$url?>"><?=e($label)?> <span class="history-sort"><?= $sort===$key?($direction==='asc'?'↑':'↓'):'↕' ?></span></a></th>
      <?php endif; ?>
    <?php endforeach; ?></tr></thead><tbody>
  <?php $historyRow=0; foreach($rows as $r): $historyRow++; ?>
    <tr>
      <td><?=fa_digits((string)$historyRow)?></td>
      <td><strong><?=e($r['first_name'].' '.$r['last_name'])?></strong></td>
      <td><?=e(fa_digits((string)$r['national_id']))?></td>
      <td><?=e($r['position_name'])?></td>
      <td><?=e($r['unit_label'])?></td>
      <td><?=fa_digits((string)($r['unit_number']??''))?:'—'?></td>
      <td><span class="history-count-chip"><?=fa_digits((string)(int)$r['edit_count'])?> بار</span></td>
      <td><?=jalali_display($r['last_changed'])?></td>
      <td><?=e($r['changed_by_name']?:'—')?></td>
      <td><a class="history-view-btn" href="personnel_history_view.php?person=<?=(int)$r['personnel_id']?>">مشاهده تغییرات</a></td>
    </tr>
  <?php endforeach; ?></tbody></table></div>
  <?php else: ?><div class="personnel-empty"><strong>سابقه‌ای ثبت نشده است.</strong><span>با اولین ویرایش یک پرونده، نسخه قبلی اینجا ذخیره می‌شود.</span></div><?php endif; ?>
</div>
<style>
.history-filters{display:flex;gap:10px;align-items:center;margin-bottom:16px}
.history-filters input{flex:1;min-width:0;height:46px;border:1px solid #dde7e2;border-radius:14px;padding:0 14px;font:inherit;outline:none}
.history-filters input:focus{border-color:#2f6b4f;box-shadow:0 0 0 3px rgba(47,107,79,.13)}
.history-list-panel{padding:18px;overflow:hidden}
.history-list-head{display:flex;justify-content:space-between;align-items:center;padding-bottom:12px;border-bottom:1px solid #edf1ee;margin-bottom:14px}
.history-list-head h2{margin:0;font-size:18px;color:#24362e}
.history-list-head p{margin:4px 0 0;color:#87918c;font-size:12px}
.history-table-wrap{width:100%;overflow:hidden}
.history-table{width:100%;border-collapse:collapse;table-layout:fixed}
.history-table th,.history-table td{padding:12px 8px;border-bottom:1px solid #edf1ef;text-align:center;vertical-align:middle;font-size:12px;overflow-wrap:anywhere}
.history-table th{background:#f7faf8;color:#66756e;font-weight:800}
.history-table th a{color:inherit;text-decoration:none}
.history-sort{color:#9ca8a2}
.history-table td{color:#283b31}
.history-count-chip{display:inline-flex;align-items:center;justify-content:center;min-width:46px;padding:4px 10px;border-radius:999px;background:#eef5f1;color:#2d6549;font-size:11px;font-weight:800}
.history-view-btn{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 12px;border:1px solid #d6e4db;background:#f2f8f4;color:#2d6549;border-radius:10px;text-decoration:none;font-size:11px;font-weight:800;white-space:nowrap}
.history-view-btn:hover{background:#e9f4ed;border-color:#bad3c4}
@media(max-width:900px){
 .history-filters{display:grid;grid-template-columns:1fr}
 .history-table-wrap{overflow:visible}
 .history-table{display:block}
 .history-table thead{display:none}
 .history-table tbody,.history-table tr,.history-table td{display:block;width:100%;box-sizing:border-box}
 .history-table tr{padding:10px;border:1px solid #e4ece7;border-radius:13px;margin-bottom:9px;background:#fff}
 .history-table td{border:0;padding:7px 5px;text-align:right;display:flex;justify-content:space-between;gap:12px}
 .history-table td::before{font-weight:800;color:#8a9891}
 .history-table td:nth-child(1)::before{content:'ردیف'}
 .history-table td:nth-child(2)::before{content:'نام و نام خانوادگی'}
 .history-table td:nth-child(3)::before{content:'کد ملی'}
 .history-table td:nth-child(4)::before{content:'سمت'}
 .history-table td:nth-child(5)::before{content:'رسته'}
 .history-table td:nth-child(6)::before{content:'شماره دسته'}
 .history-table td:nth-child(7)::before{content:'تعداد ویرایش'}
 .history-table td:nth-child(8)::before{content:'آخرین تغییر'}
 .history-table td:nth-child(9)::before{content:'آخرین ویرایش‌کننده'}
 .history-table td:nth-child(10)::before{content:'مشاهده'}
}
</style>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
