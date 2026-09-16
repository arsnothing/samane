<?php require __DIR__ . '/../app/bootstrap.php'; require_login();

/* ── حوزه استحفاظی: جست‌وجوی محله و روستا ─────────────────────────── */
$fa2en = static fn(string $v): string => strtr($v, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);

$provinceId = (int)($_GET['province_id'] ?? 0);
$cityId     = (int)($_GET['city_id'] ?? 0);
$unitNumber = substr(preg_replace('/[^0-9]/', '', $fa2en(trim((string)($_GET['category_number'] ?? '')))), 0, 3);
$groupNo    = substr(preg_replace('/[^0-9]/', '', $fa2en(trim((string)($_GET['group_number'] ?? '')))), 0, 1);
if ($groupNo !== '' && !in_array((int)$groupNo, [1,2,3], true)) $groupNo = '';
$query = preg_replace('/\s+/u', ' ', trim((string)($_GET['q'] ?? '')));

// تا وقتی هیچ فیلتری انتخاب نشده باشد، چیزی نمایش داده نمی‌شود.
$hasFilter = ($provinceId || $cityId || $unitNumber !== '' || $groupNo !== '' || $query !== '');

$provinces = $pdo->query("SELECT id,province_name FROM provinces WHERE is_active=1 AND province_code <> 'UNKNOWN' ORDER BY province_name")->fetchAll();
$cities = [];
if ($provinceId) { $cst = $pdo->prepare('SELECT id,city_name FROM cities WHERE province_id=? AND is_active=1 ORDER BY city_name'); $cst->execute([$provinceId]); $cities = $cst->fetchAll(); }
$zones = []; $schemaReady = true;
if ($hasFilter) {
    try {
        // «دسته ۱» در این صفحه نمایش داده نمی‌شود.
        $w = ['z.unit_number <> 1']; $p = [];
        if ($provinceId) { $w[] = 'z.province_id=?'; $p[] = $provinceId; }
        if ($cityId)     { $w[] = 'z.city_id=?';     $p[] = $cityId; }
        if ($unitNumber !== '') { $w[] = 'z.unit_number=?';  $p[] = (int)$unitNumber; }
        if ($groupNo !== '')    { $w[] = 'z.group_number=?'; $p[] = (int)$groupNo; }
        if ($query !== '') {
            $like = '%'.$query.'%';
            $w[] = '(z.zone_name LIKE ? OR z.covered_areas LIKE ? OR z.district LIKE ? OR z.address LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        $sql = 'SELECT z.*, ct.category_key AS unit, ci.city_name, pr.province_name
                FROM patrol_zones z
                JOIN category_types ct ON ct.id=z.category_type_id
                JOIN provinces pr ON pr.id=z.province_id
                JOIN cities ci ON ci.id=z.city_id'
             . ($w ? ' WHERE '.implode(' AND ', $w) : '')
             . ' ORDER BY z.unit_number, z.group_number';
        $st = $pdo->prepare($sql); $st->execute($p);
        $zones = array_values(array_filter($st->fetchAll(), static fn($z) => can_view_unit((string)$z['unit'])));
    } catch (Throwable $e) { $schemaReady = false; }
}

// اماکن و اشخاص هر حوزه
$placesByZone = []; $personsByZone = [];
if ($zones) {
    $ids = array_map(static fn($z) => (int)$z['id'], $zones);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("SELECT zone_id,category,place_name,place_address,place_phone FROM zone_places WHERE zone_id IN ($ph) ORDER BY category,place_name");
        $st->execute($ids);
        foreach ($st->fetchAll() as $row) $placesByZone[(int)$row['zone_id']][(string)$row['category']][] = $row;
        $st = $pdo->prepare("SELECT zone_id,category,full_name,phone,note FROM zone_persons WHERE zone_id IN ($ph) ORDER BY category,full_name");
        $st->execute($ids);
        foreach ($st->fetchAll() as $row) $personsByZone[(int)$row['zone_id']][(string)$row['category']][] = $row;
    } catch (Throwable $e) { /* جدول‌های اماکن/اشخاص هنوز ساخته نشده‌اند */ }
}

$placeCategories = [
  'police'=>'کلانتری‌ها','military'=>'اماکن نظامی','law'=>'اماکن انتظامی','security'=>'اماکن امنیتی',
  'mosque'=>'مساجد و اماکن مذهبی','culture'=>'اماکن فرهنگی','education'=>'اماکن آموزشی','sport'=>'اماکن ورزشی',
  'facility'=>'تأسیسات','government'=>'دستگاه‌های دولتی','medical'=>'اماکن درمانی','other'=>'سایر اماکن مهم',
];
$personCategories = [
  'trusted'=>'معتمدین','thugs'=>'اراذل و اوباش','record'=>'اشخاص سابقه‌دار','notable'=>'افراد شاخص',
];

$zonePayload = [];
foreach ($zones as $z) {
    $zid = (int)$z['id'];
    $zonePayload[] = [
        'id'      => $zid,
        'type'    => (($z['zone_type'] ?? 'neighborhood') === 'village') ? 'روستا' : 'محله',
        'name'    => (string)$z['zone_name'],
        'unit'    => $z['unit'] === 'information' ? 'اطلاعاتی' : 'عملیاتی',
        'number'  => fa_digits((string)$z['unit_number']),
        'group'   => fa_digits((string)$z['group_number']),
        'city'    => (string)$z['city_name'],
        'address' => (string)($z['address'] ?? ''),
        'phone'   => (string)($z['hall_phone'] ?? ''),
        'manager' => (string)($z['manager_name'] ?? ''),
        'managerPhone' => (string)($z['manager_phone'] ?? ''),
        'board'   => (string)($z['board_members'] ?? ''),
        'covered' => (string)($z['covered_areas'] ?? ''),
        'places'  => $placesByZone[$zid] ?? [],
        'persons' => $personsByZone[$zid] ?? [],
    ];
}

require __DIR__.'/../app/partials/header.php'; ?>
<section class="page-head"><div><h1>حوزه استحفاظی</h1></div></section>

<form class="filter-bar zone-filters" method="get" id="zoneFilters" data-auto-filter>
  <div class="smart-filter" id="zoneProvinceSmart" data-placeholder="استان">
    <button type="button" class="smart-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span class="smart-filter-value">استان</span><span class="smart-filter-arrow">⌄</span></button>
    <div class="smart-filter-menu"><input type="search" class="smart-filter-search" placeholder="جست‌وجوی استان" autocomplete="off"><div class="smart-filter-options" role="listbox"></div></div>
    <select name="province_id" id="zoneProvinceSelect" class="smart-filter-native" aria-label="استان"><option value="" hidden <?= !$provinceId?'selected':'' ?>>استان</option><?php foreach($provinces as $pr):?><option value="<?=$pr['id']?>" <?= $provinceId===(int)$pr['id']?'selected':'' ?>><?=e($pr['province_name'])?></option><?php endforeach;?></select>
  </div>

  <div class="smart-filter" id="zoneCitySmart" data-placeholder="شهرستان">
    <button type="button" class="smart-filter-trigger" aria-haspopup="listbox" aria-expanded="false" <?= !$provinceId?'disabled':'' ?>><span class="smart-filter-value">شهرستان</span><span class="smart-filter-arrow">⌄</span></button>
    <div class="smart-filter-menu"><input type="search" class="smart-filter-search" placeholder="جست‌وجوی شهرستان" autocomplete="off" <?= !$provinceId?'disabled':'' ?>><div class="smart-filter-options" role="listbox"></div></div>
    <select name="city_id" id="zoneCitySelect" class="smart-filter-native" aria-label="شهرستان" <?= !$provinceId?'disabled':'' ?>><option value="" hidden <?= !$cityId?'selected':'' ?>>شهرستان</option><?php foreach($cities as $c):?><option value="<?=$c['id']?>" <?= $cityId===(int)$c['id']?'selected':'' ?>><?=e($c['city_name'])?></option><?php endforeach;?></select>
  </div>

  <input type="text" name="category_number" class="filter-control filter-num" inputmode="numeric" maxlength="3" autocomplete="off" placeholder="شماره دسته" value="<?= e($unitNumber) ?>" aria-label="شماره دسته">
  <input type="text" name="group_number" class="filter-control filter-num filter-num-sm" inputmode="numeric" maxlength="1" autocomplete="off" placeholder="شماره گروه" value="<?= e($groupNo) ?>" aria-label="شماره گروه">
  <input type="search" name="q" class="filter-control filter-search" placeholder="جستجو محله، روستا" value="<?= e($query) ?>" aria-label="جستجو">
  <button class="filter-btn" type="submit">جستجو</button>
</form>

<div class="panel zone-panel">
  <?php if(!$schemaReady): ?>
    <div class="personnel-empty"><strong>جدول حوزه استحفاظی ساخته نشده است.</strong><span>فایل‌های database/upgrade_v4.42.sql و upgrade_v4.43.sql را اجرا کنید.</span></div>
  <?php elseif(!$hasFilter): ?>
    <div class="personnel-empty"><strong>برای نمایش، یکی از فیلترها را انتخاب کنید.</strong></div>
    <br>
    <br>
  <?php elseif(!$zones): ?>
    <div class="personnel-empty"><strong>موردی پیدا نشد.</strong><span>فیلترها یا عبارت جست‌وجو را تغییر بدهید.</span></div>
  <?php else: ?>
    <div class="zone-panel-head"><div><h2>نتیجه جست‌وجو</h2><p><?= fa_digits((string)count($zones)) ?> مورد</p></div></div>
    <div class="zone-result-grid">
      <?php foreach($zonePayload as $i=>$z): ?>
        <button type="button" class="zone-card" data-zone="<?= $i ?>">
          <span class="zone-card-badge <?= $z['type']==='روستا'?'village':'hood' ?>"><?= e($z['type']) ?></span>
          <span class="zone-card-name"><?= e($z['name']) ?></span>
          <span class="zone-card-meta">دسته <?= e($z['number']) ?> · گروه <?= e($z['group']) ?> · <?= e($z['city']) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="pv-modal zone-modal" id="zoneModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-zone-close></div>
  <section class="pv-modal-card" role="dialog" aria-modal="true" aria-labelledby="zoneModalTitle">
    <header class="pv-modal-head">
      <div><span class="pv-modal-eyebrow" id="zoneModalType">محله</span><h2 id="zoneModalTitle">—</h2></div>
      <button type="button" class="pv-modal-close" data-zone-close aria-label="بستن">×</button>
    </header>
    <div class="zone-detail" id="zoneDetail"></div>
    <footer class="zone-modal-actions">
      <button type="button" class="btn secondary" id="openPlaces">اماکن و تأسیسات حساس و مهم</button>
      <button type="button" class="btn secondary" id="openPersons">اشخاص شاخص</button>
    </footer>
  </section>
</div>

<div class="pv-modal zone-modal zone-sub-modal" id="placesModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-sub-close></div>
  <section class="pv-modal-card zone-wide-card" role="dialog" aria-modal="true" aria-labelledby="placesModalTitle">
    <header class="pv-modal-head">
      <div><span class="pv-modal-eyebrow" id="placesModalZone">—</span><h2 id="placesModalTitle">اماکن و تأسیسات حساس و مهم</h2></div>
      <button type="button" class="pv-modal-close" data-sub-close aria-label="بستن">×</button>
    </header>
    <input type="search" class="zone-sub-search" id="placesSearch" placeholder="جستجو در اماکن" autocomplete="off" aria-label="جستجو در اماکن">
    <div class="zone-sub-cols" id="placesBody"></div>
  </section>
</div>

<div class="pv-modal zone-modal zone-sub-modal" id="personsModal" aria-hidden="true">
  <div class="pv-modal-backdrop" data-sub-close></div>
  <section class="pv-modal-card zone-wide-card" role="dialog" aria-modal="true" aria-labelledby="personsModalTitle">
    <header class="pv-modal-head">
      <div><span class="pv-modal-eyebrow" id="personsModalZone">—</span><h2 id="personsModalTitle">اشخاص شاخص</h2></div>
      <button type="button" class="pv-modal-close" data-sub-close aria-label="بستن">×</button>
    </header>
    <input type="search" class="zone-sub-search" id="personsSearch" placeholder="جستجو در اشخاص" autocomplete="off" aria-label="جستجو در اشخاص">
    <div class="zone-sub-cols" id="personsBody"></div>
  </section>
</div>

<script src="<?= e(asset_url('assets/filter-bar.js')) ?>" defer></script>
<script>
window.ZONES = <?= json_encode($zonePayload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
window.PLACE_CATS = <?= json_encode($placeCategories, JSON_UNESCAPED_UNICODE) ?>;
window.PERSON_CATS = <?= json_encode($personCategories, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script>
(function () {
  var zones = window.ZONES || [], placeCats = window.PLACE_CATS || {}, personCats = window.PERSON_CATS || {};
  var current = null;

  // پاپ‌آپ‌ها باید فرزند مستقیم body باشند تا position:fixed آن‌ها نشکند.
  ['zoneModal','placesModal','personsModal'].forEach(function (id) {
    var m = document.getElementById(id);
    if (m && m.parentElement !== document.body) document.body.appendChild(m);
  });

  function esc(v) { return String(v == null ? '' : v).replace(/[<>&"]/g, function (c) { return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]; }); }
  function openModal(id) { var m = document.getElementById(id); m.classList.add('open'); m.setAttribute('aria-hidden','false'); document.body.classList.add('modal-open'); }
  function closeModal(id) {
    var m = document.getElementById(id); m.classList.remove('open'); m.setAttribute('aria-hidden','true');
    if (!document.querySelector('.pv-modal.open')) document.body.classList.remove('modal-open');
  }
  function line(label, value) {
    return '<div class="zone-line"><span>' + esc(label) + '</span><strong>' + (value ? esc(value) : '—') + '</strong></div>';
  }

  function showZone(z) {
    current = z;
    document.getElementById('zoneModalType').textContent = z.type;
    document.getElementById('zoneModalTitle').textContent = z.name;
    var village = (z.type === 'روستا');
    var html = '';
    html += line(village ? 'نام روستا' : 'نام محله', z.name);
    html += line(village ? 'آدرس دهیاری' : 'آدرس سرای محله', z.address);
    html += line(village ? 'شماره تماس دهیاری' : 'شماره تماس سرای محله', z.phone);
    html += line(village ? 'اعضای شورای روستا' : 'اعضای هیئت امنای محله', z.board);
    html += line(village ? 'دهیار و شماره تماس' : 'مدیر سرای محله و شماره تماس', [z.manager, z.managerPhone].filter(Boolean).join(' — '));
    if (z.covered) html += line('محدوده تحت پوشش', z.covered);
    html += '<div class="zone-line-pair">'
          + '<div class="zone-line"><span>شماره دسته ابلاغ‌شده</span><strong>' + esc(z.number) + '</strong></div>'
          + '<div class="zone-line"><span>شماره گروه ابلاغ‌شده</span><strong>' + esc(z.group) + '</strong></div>'
          + '</div>';
    document.getElementById('zoneDetail').innerHTML = html;
    openModal('zoneModal');
  }

  /* ارقام فارسی/عربی را برای مقایسه به لاتین برمی‌گرداند تا جست‌وجو با هر دو نوع رقم کار کند. */
  function faFold(v){
    return String(v == null ? '' : v).replace(/[۰-۹٠-٩]/g, function (d) {
      var i = '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);
      return i > -1 ? String(i) : String('٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    }).toLocaleLowerCase('fa-IR');
  }
  function renderCols(bodyId, cats, data, filter) {
    var q = faFold((filter || '').trim()), html = '';
    Object.keys(cats).forEach(function (key) {
      var items = (data && data[key]) || [];
      if (q) items = items.filter(function (it) { return faFold((it.place_name || it.full_name || '') + ' ' + (it.note || '')).indexOf(q) !== -1; });
      if (q && !items.length) return;
      html += '<section class="zone-sub-group"><h3>' + esc(cats[key]) + '<span>' + items.length.toLocaleString('fa-IR') + '</span></h3>';
      if (!items.length) html += '<p class="zone-sub-empty">موردی ثبت نشده است.</p>';
      else html += '<ul>' + items.map(function (it) {
        var name = esc(it.place_name || it.full_name || '');
        var extra = [it.place_address, it.place_phone, it.phone, it.note].filter(Boolean).map(esc).join(' · ');
        return '<li><strong>' + name + '</strong>' + (extra ? '<small>' + extra + '</small>' : '') + '</li>';
      }).join('') + '</ul>';
      html += '</section>';
    });
    if (!html) html = '<p class="zone-sub-empty zone-sub-empty-wide">موردی با این عبارت پیدا نشد.</p>';
    document.getElementById(bodyId).innerHTML = html;
  }

  document.querySelectorAll('.zone-card').forEach(function (card) {
    card.addEventListener('click', function () { var z = zones[parseInt(card.dataset.zone, 10)]; if (z) showZone(z); });
  });
  document.querySelectorAll('[data-zone-close]').forEach(function (el) { el.addEventListener('click', function () { closeModal('zoneModal'); }); });
  document.querySelectorAll('[data-sub-close]').forEach(function (el) { el.addEventListener('click', function () { closeModal('placesModal'); closeModal('personsModal'); }); });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var subOpen = document.getElementById('placesModal').classList.contains('open') || document.getElementById('personsModal').classList.contains('open');
    if (subOpen) { closeModal('placesModal'); closeModal('personsModal'); } else closeModal('zoneModal');
  });

  document.getElementById('openPlaces').addEventListener('click', function () {
    if (!current) return;
    document.getElementById('placesModalZone').textContent = current.type + ' ' + current.name;
    document.getElementById('placesSearch').value = '';
    renderCols('placesBody', placeCats, current.places, '');
    openModal('placesModal');
  });
  document.getElementById('openPersons').addEventListener('click', function () {
    if (!current) return;
    document.getElementById('personsModalZone').textContent = current.type + ' ' + current.name;
    document.getElementById('personsSearch').value = '';
    renderCols('personsBody', personCats, current.persons, '');
    openModal('personsModal');
  });
  document.getElementById('placesSearch').addEventListener('input', function () { renderCols('placesBody', placeCats, current && current.places, this.value); });
  document.getElementById('personsSearch').addEventListener('input', function () { renderCols('personsBody', personCats, current && current.persons, this.value); });

})();
</script>
<?php require __DIR__.'/../app/partials/footer.php'; ?>
