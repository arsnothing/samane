<?php require __DIR__ . '/../app/bootstrap.php'; require_login();
$unit=$_GET['unit']??'all'; if($unit!=='all' && !can_view_unit($unit)) { http_response_code(403); exit('دسترسی غیرمجاز'); }
$filter=$_GET['filter']??'all'; if(!in_array($filter,['all','active','information','operations','dismissed'],true)) $filter='all';
$sortColumns = [
 'organizational_code'=>'p.organizational_code',
 'first_name'=>'p.first_name',
 'last_name'=>'p.last_name',
 'national_id'=>'p.national_id',
 'birth_date'=>'p.birth_date',
 'position'=>'pos.position_name',
 'unit'=>'ct.category_name',
 'unit_number'=>'cn.unit_number',
 'group'=>'g.group_number',
 'team'=>'t.team_number',
 'mobile'=>'p.mobile',
 'join_date'=>'p.created_at'
];
$sort = $_GET['sort'] ?? 'first_name'; if (!isset($sortColumns[$sort])) $sort='first_name';
$direction = strtolower((string)($_GET['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$sortSql = $sortColumns[$sort];
$provinceId=(int)($_GET['province_id']??0); $cityId=(int)($_GET['city_id']??0); $categoryNumber=trim((string)($_GET['category_number']??''));
$categoryNumber=strtr($categoryNumber,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
$categoryNumber=substr(preg_replace('/[^0-9]/','',$categoryNumber),0,3);
if($categoryNumber!=='' && (int)$categoryNumber<1){$categoryNumber='';}
$provinces=$pdo->query("SELECT id,province_name,province_code FROM provinces WHERE is_active=1 AND province_code <> 'UNKNOWN' ORDER BY province_name")->fetchAll();
$cities=[]; if($provinceId){$stLoc=$pdo->prepare("SELECT c.id,c.city_name FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.province_id=? AND p.province_code='TEH' AND c.is_active=1 ORDER BY c.city_name");$stLoc->execute([$provinceId]);$cities=$stLoc->fetchAll();}
$where=[];$params=[]; if($unit!=='all'){ $where[]='ct.category_key=?'; $params[]=$unit; }
if($filter==='active'){ $where[]='p.personnel_status=\'active\''; } elseif($filter==='dismissed'){ $where[]='p.personnel_status=\'dismissed\''; } elseif($filter==='information' || $filter==='operations'){ $where[]='ct.category_key=?'; $params[]=$filter; }
// عناصر برکنارشده جز در فیلتر «عناصر برکنار شده» در هیچ فهرستی نمایش داده نمی‌شوند.
if($filter!=='dismissed'){ $where[]=active_personnel_sql('p'); }
if($provinceId){$where[]='p.province_id=?';$params[]=$provinceId;}
if($cityId){$where[]='p.city_id=?';$params[]=$cityId;}
if($categoryNumber!==''){$where[]='cn.unit_number=?';$params[]=$categoryNumber;}
$q=fa_to_en_digits(trim((string)($_GET['q']??'')));
$q=strtr($q,["\u{00A0}"=>' ',"\u{200C}"=>' ',"\u{200D}"=>' ',"\u{064A}"=>'ی',"\u{0649}"=>'ی',"\u{0643}"=>'ک']);
$q=preg_replace('/\s+/u',' ', $q);
if($q!==''){
    // Search is always combined with the active filters. Full name is the primary search key.
    $qSql = strtr($q, ["ی"=>"ي", "ک"=>"ك"]);
    $like = '%'.$q.'%';
    $likeSql = '%'.$qSql.'%';
    $where[] = "(
        REPLACE(REPLACE(CONCAT_WS(' ', TRIM(p.first_name), TRIM(p.last_name)), 'ي', 'ی'), 'ك', 'ک') LIKE ?
        OR REPLACE(REPLACE(CONCAT_WS(' ', TRIM(p.first_name), TRIM(p.last_name)), 'ی', 'ي'), 'ک', 'ك') LIKE ?
        OR p.first_name LIKE ?
        OR p.last_name LIKE ?
        OR p.national_id LIKE ?
        OR p.mobile LIKE ?
    )";
    array_push($params, $like, $likeSql, $like, $like, $like, $like);
}

$baseSql=personnel_select_sql('p');

$sql=$baseSql.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY '.$sortSql.' '.$direction.', p.id ASC';
$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll();$rows=array_values(array_filter($rows,fn($r)=>can_view_unit($r['unit'])));
require __DIR__ . '/../app/partials/header.php'; ?>
<form class="filter-bar personnel-filters" method="get" id="personnelFilters" data-auto-filter>
  <input type="hidden" name="sort" value="<?= e($sort) ?>">
  <input type="hidden" name="direction" value="<?= e($direction) ?>">
  <div class="smart-filter" id="personnelProvinceSmart" data-placeholder="استان">
    <button type="button" class="smart-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span class="smart-filter-value">استان</span><span class="smart-filter-arrow">⌄</span></button>
    <div class="smart-filter-menu"><input type="search" class="smart-filter-search" placeholder="جست‌وجوی استان" autocomplete="off"><div class="smart-filter-options" role="listbox"></div></div>
    <select name="province_id" class="smart-filter-native" id="personnelProvinceSelect" aria-label="استان"><option value="" hidden <?= !$provinceId?'selected':'' ?>>استان</option><?php foreach($provinces as $p):?><option value="<?=$p['id']?>" <?= $provinceId===(int)$p['id']?'selected':'' ?>><?=e($p['province_name'])?></option><?php endforeach;?></select>
  </div>
  <div class="smart-filter" id="personnelCitySmart" data-placeholder="شهرستان">
    <button type="button" class="smart-filter-trigger" aria-haspopup="listbox" aria-expanded="false" <?= !$provinceId?'disabled':'' ?>><span class="smart-filter-value">شهرستان</span><span class="smart-filter-arrow">⌄</span></button>
    <div class="smart-filter-menu"><input type="search" class="smart-filter-search" placeholder="جست‌وجوی شهرستان" autocomplete="off" <?= !$provinceId?'disabled':'' ?>><div class="smart-filter-options" role="listbox"></div></div>
    <select name="city_id" class="smart-filter-native" id="personnelCitySelect" aria-label="شهرستان" <?= !$provinceId?'disabled':'' ?>><option value="" hidden <?= !$cityId?'selected':'' ?>>شهرستان</option><?php foreach($cities as $c):?><option value="<?=$c['id']?>" <?= $cityId===(int)$c['id']?'selected':'' ?>><?=e($c['city_name'])?></option><?php endforeach;?></select>
  </div>
  <div class="smart-filter" id="personnelCategorySmart" data-placeholder="نوع رسته">
    <button type="button" class="smart-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span class="smart-filter-value">نوع رسته</span><span class="smart-filter-arrow">⌄</span></button>
    <div class="smart-filter-menu"><div class="smart-filter-options" role="listbox"></div></div>
    <select name="filter" class="smart-filter-native" id="personnelCategorySelect" aria-label="نوع رسته"><option value="all" <?= $filter==='all'?'selected':'' ?>>کلیه عناصر</option><option value="active" <?= $filter==='active'?'selected':'' ?>>عناصر فعال</option><option value="information" <?= $filter==='information'?'selected':'' ?>>عناصر اطلاعاتی</option><option value="operations" <?= $filter==='operations'?'selected':'' ?>>عناصر عملیاتی</option><option value="dismissed" <?= $filter==='dismissed'?'selected':'' ?>>عناصر برکنار شده</option></select>
  </div>
  <input type="text" name="category_number" class="filter-control filter-number" inputmode="numeric" maxlength="3" autocomplete="off" placeholder="شماره دسته" value="<?= e($categoryNumber) ?>" aria-label="شماره دسته">
  <input type="search" name="q" class="filter-control filter-search" placeholder="جستجو نام و نام خانوادگی، کد ملی یا موبایل" value="<?= e($_GET['q']??'') ?>" aria-label="جستجو">
  <button class="filter-btn" type="submit">جستجو</button>
</form>
<?php $printTitle='فهرست عناصر'; $printLandscape=true; require __DIR__.'/../app/partials/print_frame.php'; ?>
<div class="panel print-list personnel-list-panel">
  <div class="personnel-list-head">
    <div>
      <h2>فهرست عناصر</h2>
      <p><?= fa_digits((string)count($rows)) ?> عنصر</p>
    </div>
    <div class="page-actions">
      <button class="personnel-history-btn" type="button" onclick="window.print()">دریافت PDF</button>
      <a class="personnel-history-btn" href="personnel_history.php">تاریخچه تغییرات</a>
    </div>
  </div>

  <?php if($rows): ?>
    <table class="personnel-directory" aria-label="فهرست عناصر">
      <thead class="personnel-directory-head">
        <tr class="personnel-head-row">
        <?php
        $tableHeaders = [
          ['key'=>'id','label'=>'ردیف'],
          ['key'=>'organizational_code','label'=>'کد سازمانی'],
          ['key'=>'first_name','label'=>'نام'],
          ['key'=>'last_name','label'=>'نام‌خانوادگی'],
          ['key'=>'national_id','label'=>'کد ملی'],
          ['key'=>'birth_date','label'=>'تاریخ تولد'],
          ['key'=>'position','label'=>'سمت'],
          ['key'=>'unit','label'=>'رسته'],
          ['key'=>'unit_number','label'=>'شماره‌ دسته'],
          ['key'=>'group','label'=>'گروه'],
          ['key'=>'team','label'=>'تیم'],
          ['key'=>'mobile','label'=>'شماره تماس همراه'],
          ['key'=>'join_date','label'=>'تاریخ عضویت'],
        ];
        foreach($tableHeaders as $header):
          $nextDirection = ($sort === $header['key'] && $direction === 'asc') ? 'desc' : 'asc';
          $sortParams = [];
          foreach (['unit','filter','province_id','city_id','category_number','q'] as $key) {
            if (isset($_GET[$key]) && (string)$_GET[$key] !== '') $sortParams[$key] = (string)$_GET[$key];
          }
          $sortParams['sort'] = $header['key'];
          $sortParams['direction'] = $nextDirection;
          $sortUrl = '?' . e(http_build_query($sortParams));
        ?>
          <th class="personnel-col-cell" scope="col">
            <a class="personnel-col-head" href="<?= $sortUrl ?>">
              <span><?= e($header['label']) ?></span>
              <span class="personnel-head-sort<?= $sort===$header['key']?' active':'' ?>"><?= $sort===$header['key'] ? ($direction==='asc'?'↑':'↓') : '↕' ?></span>
            </a>
          </th>
        <?php endforeach; ?>
        <th class="personnel-col-cell personnel-action-head" scope="col"></th>
        </tr>
      </thead>

      <tbody class="personnel-directory-body">
      <?php foreach($rows as $rowIndex => $r):
        $rowNumber = $rowIndex + 1;
        $cardFields = [
          ['key'=>'id','label'=>'ردیف','value'=>fa_digits((string)$rowNumber)],
          ['key'=>'organizational_code','label'=>'کد سازمانی','value'=>$r['organizational_code']?:'—'],
          ['key'=>'first_name','label'=>'نام','value'=>$r['first_name']],
          ['key'=>'last_name','label'=>'نام خانوادگی','value'=>$r['last_name']],
          ['key'=>'national_id','label'=>'کد ملی','value'=>$r['national_id']],
          ['key'=>'birth_date','label'=>'تاریخ تولد','value'=>jalali_display($r['birth_date'])],
          ['key'=>'position','label'=>'سمت','value'=>(['unit_commander'=>'فرمانده دسته','group_commander'=>'فرمانده گروه','team_leader'=>'سر تیم','element'=>'عنصر'][$r['position_type']]??$r['position_type'])],
          ['key'=>'unit','label'=>'رسته','value'=>($r['unit']==='information'?'اطلاعاتی':($r['unit']==='operations'?'عملیاتی':$r['unit_label']))],
          ['key'=>'unit_number','label'=>'شماره دسته','value'=>$r['unit_number']?:'—'],
          ['key'=>'group','label'=>'گروه','value'=>$r['group_no']===null?'—':fa_digits((string)$r['group_no'])],
          ['key'=>'team','label'=>'تیم','value'=>$r['team_no']===null?'—':($r['team_type']?:('تیم '.fa_digits((string)$r['team_no'])))],
          ['key'=>'mobile','label'=>'شماره تماس همراه','value'=>$r['mobile']?:'—'],
          ['key'=>'join_date','label'=>'تاریخ عضویت','value'=>jalali_display(substr((string)$r['created_at'],0,10))],
        ];
      ?>
        <tr class="personnel-directory-row">
          <?php foreach($cardFields as $field): ?>
            <td class="personnel-data-cell">
              <span class="personnel-data-label"><?= e($field['label']) ?></span>
              <strong class="personnel-data-value"><?= e((string)$field['value']) ?></strong>
            </td>
          <?php endforeach; ?>
          <td class="personnel-action-cell">
            <a class="personnel-profile-btn" href="personnel_view.php?id=<?= (int)$r['id'] ?>">نمایش پرونده</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="personnel-empty"><strong>موردی پیدا نشد.</strong><span>فیلترها یا عبارت جست‌وجو را تغییر بدهید.</span></div>
  <?php endif; ?>
</div>
<style>
.personnel-list-panel{overflow:visible;padding:18px;border-radius:18px}.personnel-list-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #edf1ee}.personnel-list-head h2{margin:0;font-size:18px;color:#24362e}.personnel-list-head p{margin:4px 0 0;color:#87918c;font-size:12px}
.personnel-directory{width:100%;min-width:0;}
.personnel-head-row{display:contents}
.personnel-directory-head,.personnel-directory-row{display:grid;grid-template-columns:.42fr .9fr 1.15fr 1.2fr .9fr .85fr 1.1fr .8fr .8fr .45fr .7fr 1.05fr .9fr 100px;gap:0;align-items:stretch;direction:rtl;}
.personnel-directory-head{background:#f7faf8;border:1px solid #e5ece8;border-radius:14px 14px 0 0;overflow:hidden;}
.personnel-col-head{min-width:0;padding:11px 9px;border-left:1px solid #e8eeeb;color:#67756e;text-decoration:none;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;gap:4px;text-align:center;line-height:1.45;}
.personnel-col-head:last-child{border-left:0}.personnel-col-head:hover{background:#f0f6f2;color:#315744}.personnel-head-sort{color:#a8b3ad;font-size:10px}.personnel-head-sort.active{color:#2c7653}.personnel-action-head{cursor:default}
.personnel-directory-body{border:1px solid #e5ece8;border-top:0;border-radius:0 0 14px 14px;overflow:hidden;background:#fff;}
.personnel-directory-row{border-top:1px solid #edf1ef;background:#fff;transition:background .16s ease}.personnel-directory-row:first-child{border-top:0}.personnel-directory-row:hover{background:#fbfdfb}
.personnel-data-cell{min-width:0;padding:12px 9px;border-left:1px solid #f0f3f1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;text-align:center;}
.personnel-data-cell:last-of-type{border-left:1px solid #f0f3f1}.personnel-data-label{display:none;color:#8b9891;font-size:10px;font-weight:800;line-height:1.4}.personnel-data-value{display:block;min-width:0;max-width:100%;color:#263a30;font-size:12px;font-weight:700;line-height:1.55;overflow-wrap:anywhere;}
.personnel-action-cell{display:flex;align-items:center;justify-content:center;padding:8px;min-width:0;}.personnel-profile-btn{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 10px;border-radius:9px;background:#edf7f0;border:1px solid #d4e6da;color:#245a40;text-decoration:none;font-size:11px;font-weight:800;white-space:nowrap;transition:.16s ease}.personnel-profile-btn:hover{background:#e4f2e8;border-color:#c2dacb;transform:translateY(-1px)}
.personnel-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:48px 20px;border:1px dashed #d6e1db;border-radius:16px;color:#738078;background:#fbfdfc}.personnel-empty span{font-size:12px;color:#98a39d}
.personnel-list-head{gap:14px}.personnel-history-btn{display:inline-flex;align-items:center;justify-content:center;height:38px;padding:0 14px;border:1px solid #d8e3dd;border-radius:10px;background:#f6faf7;color:#2e6249;text-decoration:none;font-size:12px;font-weight:800;transition:.18s ease}.personnel-history-btn:hover{background:#edf6f0;border-color:#c5d9cc;transform:translateY(-1px)}
@media(max-width:1250px){
.personnel-list-panel{overflow-x:visible}
.personnel-directory,.personnel-directory-head,.personnel-directory-row{min-width:0}
.personnel-directory-head{display:none}.personnel-directory-body{border:0;border-radius:0;background:transparent;overflow:visible}.personnel-directory-row{grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px;padding:8px;border:1px solid #e5ece8;border-radius:14px;background:#fff;box-shadow:0 3px 12px rgba(28,55,43,.035)}.personnel-data-cell{padding:9px;border:1px solid #edf1ef;border-radius:10px;background:#fafcfb}.personnel-data-label{display:block}.personnel-action-cell{grid-column:1/-1;padding:2px 0 0}.personnel-profile-btn{width:100%;min-height:38px}}
@media(max-width:760px){.personnel-list-panel{padding:12px}.personnel-directory-row{grid-template-columns:repeat(2,minmax(0,1fr));padding:7px;gap:7px}.personnel-data-cell{padding:9px 8px}.personnel-data-value{font-size:12px}.personnel-profile-btn{font-size:12px}}

/* V4.8 — final personnel directory polish */
.personnel-list-panel{background:#fff;border:1px solid #e4ebe7;border-radius:20px;padding:20px;box-shadow:0 10px 30px rgba(28,55,43,.045)}
.personnel-list-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid #edf2ef}
.personnel-list-head h2{margin:0;color:#1d3027;font-size:19px;font-weight:900;letter-spacing:-.01em}
.personnel-list-head p{margin:4px 0 0;color:#8a968f;font-size:11px}
.personnel-history-btn{height:40px;padding:0 15px;border:1px solid #cfe1d6;border-radius:11px;background:#f3f9f5;color:#2b6148;font-size:12px;font-weight:900;box-shadow:none}
.personnel-history-btn:hover{background:#eaf5ee;border-color:#b9d2c3;transform:translateY(-1px);box-shadow:0 6px 16px rgba(42,97,72,.08)}
.personnel-directory{width:100%;max-width:100%;min-width:0}
.personnel-directory-head,.personnel-directory-row{display:grid;grid-template-columns:.42fr .9fr 1.15fr 1.2fr .9fr .85fr 1.1fr .8fr .8fr .45fr .7fr 1.05fr .9fr 100px;align-items:stretch;direction:rtl}
.personnel-directory-head{background:#f6faf7;border:1px solid #e1eae5;border-radius:14px 14px 0 0;overflow:hidden}
.personnel-col-head{min-width:0;padding:12px 7px;border-left:1px solid #e7eee9;color:#6d7b74;text-decoration:none;font-size:10px;font-weight:900;display:flex;align-items:center;justify-content:center;gap:4px;text-align:center;line-height:1.5;white-space:normal}
.personnel-col-head:last-child{border-left:0}
.personnel-col-head:hover{background:#eef6f1;color:#2b6148}
.personnel-head-sort{color:#aab6af;font-size:9px;line-height:1}
.personnel-head-sort.active{color:#2d7653}
.personnel-directory-body{border:1px solid #e1eae5;border-top:0;border-radius:0 0 14px 14px;overflow:hidden;background:#fff}
.personnel-directory-row{min-width:0;border-top:1px solid #edf2ef;background:#fff;transition:background .16s ease}
.personnel-directory-row:first-child{border-top:0}
.personnel-directory-row:hover{background:#fbfdfc}
.personnel-data-cell{min-width:0;padding:13px 7px;border-left:1px solid #f0f4f2;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;text-align:center}
.personnel-data-cell:last-of-type{border-left:1px solid #f0f4f2}
.personnel-data-label{display:none;color:#87958d;font-size:9px;font-weight:900;line-height:1.45}
.personnel-data-value{display:block;width:100%;min-width:0;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#25372e;font-size:11px;font-weight:800;line-height:1.6}
.personnel-data-cell:first-child .personnel-data-value{color:#7d8b84;font-variant-numeric:tabular-nums}
.personnel-action-cell{display:flex;align-items:center;justify-content:center;padding:7px;min-width:0}
.personnel-profile-btn{display:inline-flex;align-items:center;justify-content:center;width:100%;min-height:34px;padding:0 8px;border:1px solid #cfe1d6;border-radius:10px;background:#f1f8f4;color:#2b6148;text-decoration:none;font-size:10px;font-weight:900;white-space:nowrap;transition:.16s ease}
.personnel-profile-btn:hover{background:#e7f3ec;border-color:#bad3c4;transform:translateY(-1px);box-shadow:0 5px 12px rgba(43,97,72,.08)}
.personnel-empty{padding:56px 20px;border:1px dashed #d5e2da;border-radius:16px;background:#fbfdfc;color:#728179}
@media(max-width:1250px){
 .personnel-directory-head{display:none}
 .personnel-directory-body{border:0;border-radius:0;background:transparent;overflow:visible}
 .personnel-directory-row{grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px;padding:8px;border:1px solid #e4ece7;border-radius:15px;background:#fff;box-shadow:0 4px 16px rgba(28,55,43,.04)}
 .personnel-data-cell{padding:10px;border:1px solid #edf2ef;border-radius:11px;background:#fafcfb}
 .personnel-data-label{display:block}
 .personnel-action-cell{grid-column:1/-1;padding:1px 0 0}
 .personnel-profile-btn{min-height:38px;font-size:11px}
}
@media(max-width:760px){
 .personnel-list-panel{padding:12px}
 .personnel-list-head{align-items:flex-start;flex-direction:column}
 .personnel-history-btn{width:100%}
 .personnel-directory-row{grid-template-columns:repeat(2,minmax(0,1fr));padding:7px;gap:7px}
 .personnel-data-cell{padding:9px 8px}
 .personnel-data-value{font-size:11px}
}


/* V5.1 — keep the clean list layout while preventing text wrapping */
.personnel-list-panel{
  width:100%;
  max-width:none;
  box-sizing:border-box;
  overflow:visible;
}
.personnel-directory{
  width:100%;
  min-width:0;
}
.personnel-col-head{
  white-space:nowrap !important;
  line-height:1.2 !important;
  font-size:11px !important;
  padding-left:7px !important;
  padding-right:7px !important;
}
.personnel-col-head > span{
  white-space:nowrap !important;
}
.personnel-col-head .personnel-head-sort{
  flex:0 0 auto;
}
@media(max-width:1250px){
  .personnel-list-panel{overflow:visible;}
  .personnel-directory{min-width:0;}
}

/* V5.2 — ساختار جدول واقعی (برای تکرار سرستون در چاپ)، با همان ظاهر گرید روی صفحه */
table.personnel-directory{display:block;border-collapse:separate;border-spacing:0}
.personnel-directory>thead.personnel-directory-head{display:block}
.personnel-directory>tbody.personnel-directory-body{display:block}
tr.personnel-head-row,tr.personnel-directory-row{
  display:grid;grid-template-columns:.42fr .9fr 1.15fr 1.2fr .9fr .85fr 1.1fr .8fr .8fr .45fr .7fr 1.05fr .9fr 100px;
  align-items:stretch;direction:rtl}
th.personnel-col-cell{display:contents}
td.personnel-data-cell{display:flex}
@media(max-width:1250px){
  tr.personnel-head-row{display:none}
  tr.personnel-directory-row{grid-template-columns:repeat(4,minmax(0,1fr))}
}
@media(max-width:760px){
  tr.personnel-directory-row{grid-template-columns:repeat(2,minmax(0,1fr))}
}

</style>
<script src="<?= e(asset_url('assets/filter-bar.js')) ?>" defer></script>
<script>
(function(){
 var categoryNumber=document.querySelector('[name="category_number"]');
 if(categoryNumber){categoryNumber.addEventListener('input',function(){this.value=this.value.replace(/[۰-۹]/g,function(d){return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d));}).replace(/[٠-٩]/g,function(d){return String('٠١٢٣٤٥٦٧٨٩'.indexOf(d));}).replace(/[^0-9]/g,'').slice(0,3).replace(/[0-9]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[+d];});});}
})();
</script>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
