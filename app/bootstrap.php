<?php
$config = require __DIR__ . '/../config/config.php';
session_name($config['app']['session_name']);
session_start();
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',$config['db']['host'],$config['db']['port'],$config['db']['name'],$config['db']['charset']);
try {
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
} catch (PDOException $e) { http_response_code(500); exit('خطا در اتصال به پایگاه داده. تنظیمات config/config.php را بررسی کنید.'); }
function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function redirect(string $path): never { global $config; header('Location: '.$config['app']['base_url'].'/'.ltrim($path,'/')); exit; }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verify_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('درخواست نامعتبر است.'); } }
function user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): void { if (!user()) redirect('index.php'); }
function can_manage_personnel(): bool { return in_array(user()['role'] ?? '', ['super_admin','hr_manager'], true); }
function allowed_units(): array { return match (user()['role'] ?? '') { 'info_commander'=>['information'], 'ops_commander'=>['operations'], default=>['information','operations'], }; }
function can_view_unit(string $unit): bool { return in_array($unit, allowed_units(), true); }
function can_manage_finance(): bool { return can_manage_personnel(); }
function education_options(): array { return ['illiterate'=>'بی‌سواد','sekel'=>'سیکل','diploma'=>'دیپلم','associate'=>'کاردانی','bachelor'=>'کارشناسی','master'=>'کارشناسی ارشد','phd'=>'دکتری','seminary'=>'حوزوی','student'=>'در حال تحصیل','dropout'=>'ترک تحصیل','other'=>'سایر']; }
function position_options(): array { return ['unit_commander'=>'فرمانده دسته','group_commander'=>'فرمانده گروه','team_leader'=>'سر تیم','element'=>'عنصر']; }
function personnel_status_options(): array { return ['active'=>'فعال','dismissed'=>'برکنار شده']; }
/** انواع آماد (تجهیزات): کلید داخلی => برچسب فارسی. equipment.php/equipment_status.php/equipment_return.php این را به اشتراک می‌گذارند. */
function equipment_type_options(): array { return ['car'=>'خودرو','motorcycle'=>'موتور سیکلت','led'=>'ال ای دی','gun'=>'سلاح','shocker'=>'شوکر','spray'=>'افشانه','radio'=>'بی‌سیم','hand-caugh'=>'دستبند','mobile'=>'موبایل','computer'=>'رایانه','camera'=>'دوربین','other'=>'سایر']; }
/** انواع خودرویی که به‌جای شماره سریال، «پلاک» یکتای هر واحد را می‌گیرند. */
function equipment_vehicle_types(): array { return ['car','motorcycle']; }
/** وضعیت آمادی هر واحد آماد: کلید داخلی => برچسب فارسی. */
function equipment_status_options(): array { return ['healthy'=>'سالم','needs_repair'=>'نیاز به تعمیر','in_repair'=>'در دست تعمیر','repaired'=>'تعمیر شده']; }
/** رنگ هر وضعیت برای نشان (کلاس kind-chip مانند)، برای استایل ثابت در هر سه صفحه. */
function equipment_status_chip_class(string $status): string { return ['healthy'=>'chip-healthy','needs_repair'=>'chip-needs-repair','in_repair'=>'chip-in-repair','repaired'=>'chip-repaired'][$status] ?? 'chip-healthy'; }
/* --- قواعد خودکار پرونده انضباطی --- */
const DISC_WARNINGS_PER_REPRIMAND   = 3;   // هر ۳ اخطار => ۱ توبیخ
const DISC_REPRIMANDS_FOR_DISMISSAL = 3;   // ۳ توبیخ => برکناری خودکار
const DISC_AUTO_REPRIMAND_TITLE  = 'توبیخ خودکار (سه اخطار)';
const DISC_AUTO_REPRIMAND_REASON = 'به ازای هر سه اخطار ثبت‌شده، یک توبیخ به صورت خودکار صادر می‌شود.';
const DISC_AUTO_DISMISS_REASON   = 'برکناری خودکار به دلیل ثبت سه توبیخ در پرونده انضباطی.';

/**
 * پس از هر ثبت پرونده انضباطی اجرا می‌شود:
 * ۱) به ازای هر ۳ اخطار، یک «توبیخ خودکار» می‌سازد.
 * ۲) اگر مجموع توبیخ‌ها به ۳ برسد، عنصر را خودکار برکنار می‌کند.
 * خروجی: ['warnings'=>int,'reprimands'=>int,'auto_reprimands'=>int,'dismissed'=>bool]
 */
function apply_disciplinary_rules(int $personnelId, ?int $actorId = null): array {
    global $pdo;
    $result = ['warnings'=>0,'reprimands'=>0,'auto_reprimands'=>0,'dismissed'=>false];
    if ($personnelId <= 0) return $result;

    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM disciplinary_reports') as $col) { $cols[strtolower($col['Field'])] = $col; }
    $hasTitle = isset($cols['subject_title']);
    $hasDate  = isset($cols['report_date']);
    $subjectNullable = (($cols['subject_id']['Null'] ?? 'NO') === 'YES');
    // بدون ستون‌های نسخه ۴.۴۱ نمی‌توان توبیخ خودکار (بدون موضوع از جدول) ثبت کرد.
    if (!$hasTitle || !$subjectNullable) return $result;

    $countBy = static function (string $type) use ($pdo, $personnelId): int {
        $st = $pdo->prepare('SELECT COUNT(*) FROM disciplinary_reports WHERE personnel_id=? AND report_type=?');
        $st->execute([$personnelId, $type]);
        return (int)$st->fetchColumn();
    };

    $warnings = $countBy('warning');
    $st = $pdo->prepare('SELECT COUNT(*) FROM disciplinary_reports WHERE personnel_id=? AND report_type=? AND subject_title=?');
    $st->execute([$personnelId, 'reprimand', DISC_AUTO_REPRIMAND_TITLE]);
    $autoExisting = (int)$st->fetchColumn();

    $autoExpected = intdiv($warnings, DISC_WARNINGS_PER_REPRIMAND);
    $missing = max(0, $autoExpected - $autoExisting);
    if ($missing > 0) {
        $sql = $hasDate
            ? 'INSERT INTO disciplinary_reports(personnel_id,report_type,subject_id,subject_title,report_date,reason,created_by) VALUES(?,?,NULL,?,?,?,?)'
            : 'INSERT INTO disciplinary_reports(personnel_id,report_type,subject_id,subject_title,reason,created_by) VALUES(?,?,NULL,?,?,?)';
        $ins = $pdo->prepare($sql);
        for ($i = 0; $i < $missing; $i++) {
            $params = $hasDate
                ? [$personnelId,'reprimand',DISC_AUTO_REPRIMAND_TITLE,date('Y-m-d'),DISC_AUTO_REPRIMAND_REASON,$actorId]
                : [$personnelId,'reprimand',DISC_AUTO_REPRIMAND_TITLE,DISC_AUTO_REPRIMAND_REASON,$actorId];
            $ins->execute($params);
        }
    }

    $reprimands = $countBy('reprimand');
    $result = ['warnings'=>$warnings,'reprimands'=>$reprimands,'auto_reprimands'=>$missing,'dismissed'=>false];

    if ($reprimands >= DISC_REPRIMANDS_FOR_DISMISSAL && !personnel_is_dismissed($personnelId)) {
        auto_dismiss_personnel($personnelId, DISC_AUTO_DISMISS_REASON, $actorId);
        $result['dismissed'] = true;
    }
    return $result;
}

/** برکناری خودکار (بدون فرم): همان رکوردی را می‌سازد که برکناری دستی می‌سازد. */
function auto_dismiss_personnel(int $personnelId, string $reason, ?int $actorId = null): void {
    global $pdo;
    $hasDismissalDate = (bool)$pdo->query("SHOW COLUMNS FROM personnel_dismissals LIKE 'dismissal_date'")->fetch();
    $exists = $pdo->prepare('SELECT id FROM personnel_dismissals WHERE personnel_id=? LIMIT 1');
    $exists->execute([$personnelId]);
    $dismissalId = $exists->fetchColumn();
    $today = date('Y-m-d');

    if ($dismissalId) {
        $sql = 'UPDATE personnel_dismissals SET reason=?' . ($hasDismissalDate ? ', dismissal_date=?' : '') . ', dismissed_by=?, dismissed_at=CURRENT_TIMESTAMP WHERE id=?';
        $params = $hasDismissalDate ? [$reason,$today,$actorId,(int)$dismissalId] : [$reason,$actorId,(int)$dismissalId];
    } else {
        $sql = $hasDismissalDate
            ? 'INSERT INTO personnel_dismissals(personnel_id,dismissal_type,reason,dismissal_date,dismissed_by) VALUES(?,NULL,?,?,?)'
            : 'INSERT INTO personnel_dismissals(personnel_id,dismissal_type,reason,dismissed_by) VALUES(?,NULL,?,?)';
        $params = $hasDismissalDate ? [$personnelId,$reason,$today,$actorId] : [$personnelId,$reason,$actorId];
    }
    $pdo->prepare($sql)->execute($params);
    $pdo->prepare("UPDATE personnel SET personnel_status='dismissed', updated_by=? WHERE id=?")->execute([$actorId, $personnelId]);
}

/* --- عناصر برکنارشده: در هیچ اکشن جدیدی شرکت داده نمی‌شوند --- */
function person_is_dismissed(?array $person): bool { return (($person['personnel_status'] ?? 'active') === 'dismissed'); }
function personnel_is_dismissed(int $personnelId): bool {
    global $pdo;
    $st=$pdo->prepare('SELECT personnel_status FROM personnel WHERE id=?');
    $st->execute([$personnelId]);
    return (string)$st->fetchColumn() === 'dismissed';
}
/** شرط SQL برای کنار گذاشتن عناصر برکنارشده از فهرست‌های عملیاتی. */
function active_personnel_sql(string $alias='p'): string { return "{$alias}.personnel_status <> 'dismissed'"; }
/** اگر عنصر برکنار شده باشد، اجرای اکشن را متوقف می‌کند. */
function block_if_dismissed(?array $person, string $action='این عملیات'): void {
    if (person_is_dismissed($person)) { http_response_code(403); exit('این عنصر برکنار شده است؛ ' . $action . ' برای او امکان‌پذیر نیست.'); }
}

function document_type_options(): array { return ['birth_certificate'=>'شناسنامه','national_card'=>'کارت ملی','criminal_record'=>'گواهی سوء پیشینه','dismissal'=>'سند برکناری','other'=>'سایر']; }
function training_course_options(): array { return ['basic_combat'=>'رزم مقدماتی','general_judicial'=>'دوره عمومی و ضابطین قضایی','observe_describe'=>'مشاهده و توصیف','city_recognition'=>'شهر شناسی','cover_normalization'=>'پوشش و عادی سازی','surveillance'=>'تعقیب و مراقبت','arrest'=>'دستگیری','search_transfer'=>'بازرسی و انتقال متهم','self_defense'=>'دفاع مشروع','intel_investigation'=>'تحقیقات اطلاعاتی']; }
function asset_url(string $relative): string {
    global $config;
    $relative = ltrim($relative, '/');
    $file = __DIR__ . '/../public/' . $relative;
    $version = is_file($file) ? filemtime($file) : 0;
    return $config['app']['base_url'] . '/' . $relative . ($version ? '?v=' . $version : '');
}
function unit_label(string $unit): string { return $unit==='information'?'رسته اطلاعاتی':'رسته عملیاتی'; }

function personnel_select_sql(string $alias='p'): string {
    return "SELECT {$alias}.*, ct.category_key AS unit, ct.category_name AS unit_label, cn.unit_number, g.group_number AS group_no, t.team_number AS team_no, t.team_name AS team_type, pos.position_key AS position_type, pos.position_name AS position_label, pr.province_name, ci.city_name
            FROM personnel {$alias}
            JOIN category_types ct ON ct.id={$alias}.category_type_id
            JOIN category_numbers cn ON cn.id={$alias}.category_number_id
            JOIN positions pos ON pos.id={$alias}.position_id
            JOIN provinces pr ON pr.id={$alias}.province_id
            JOIN cities ci ON ci.id={$alias}.city_id
            LEFT JOIN personnel_groups g ON g.id={$alias}.group_id
            LEFT JOIN teams t ON t.id={$alias}.team_id";
}
function category_rows(): array {
    global $pdo;
    return $pdo->query("SELECT id,category_key,category_name FROM category_types WHERE is_active=1 ORDER BY id")->fetchAll();
}
function category_number_id(string $categoryKey, string $number): ?int {
    global $pdo;
    $st=$pdo->prepare("SELECT cn.id FROM category_numbers cn JOIN category_types ct ON ct.id=cn.category_type_id WHERE ct.category_key=? AND cn.unit_number=? AND cn.is_active=1 LIMIT 1");
    $st->execute([$categoryKey,$number]);
    $v=$st->fetchColumn(); return $v===false?null:(int)$v;
}
function ensure_category_number_id(string $categoryKey, string $number): ?int {
    global $pdo;
    $existing=category_number_id($categoryKey,$number);
    if($existing) return $existing;
    $ct=category_type_id($categoryKey);
    if(!$ct) return null;
    $st=$pdo->prepare("INSERT INTO category_numbers(category_type_id,unit_number,unit_name) VALUES(?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $st->execute([$ct,$number,'دسته '.$number]);
    return (int)$pdo->lastInsertId();
}
function category_type_id(string $categoryKey): ?int {
    global $pdo; $st=$pdo->prepare("SELECT id FROM category_types WHERE category_key=? AND is_active=1 LIMIT 1"); $st->execute([$categoryKey]); $v=$st->fetchColumn(); return $v===false?null:(int)$v;
}
function position_id(string $positionKey): ?int {
    global $pdo; $st=$pdo->prepare("SELECT id FROM positions WHERE position_key=? AND is_active=1 LIMIT 1"); $st->execute([$positionKey]); $v=$st->fetchColumn(); return $v===false?null:(int)$v;
}
function group_id(int $groupNumber): ?int {
    global $pdo; $st=$pdo->prepare("SELECT id FROM personnel_groups WHERE group_number=? AND is_active=1 LIMIT 1"); $st->execute([$groupNumber]); $v=$st->fetchColumn(); return $v===false?null:(int)$v;
}
function team_id(string $categoryKey, int $teamNumber): ?int {
    global $pdo; $st=$pdo->prepare("SELECT t.id FROM teams t JOIN category_types ct ON ct.id=t.category_type_id WHERE ct.category_key=? AND t.team_number=? AND t.is_active=1 LIMIT 1"); $st->execute([$categoryKey,$teamNumber]); $v=$st->fetchColumn(); return $v===false?null:(int)$v;
}

function fa_digits(?string $value): string { return strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); }
function format_amount($amount): string { return fa_digits(number_format((float)$amount, 0, '.', ',')).' ریال'; }

/** ارقام فارسی/عربی را به لاتین برمی‌گرداند و بقیه متن را دست‌نخورده نگه می‌دارد.
 *  برای جست‌وجوها لازم است: کاربر «۰۰۱۲» تایپ می‌کند، دیتابیس «0012» ذخیره کرده است. */
function fa_to_en_digits(?string $value): string {
    return strtr((string)$value, [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
    ]);
}
function digits_only(?string $value): string {
    return preg_replace('/\D+/', '', fa_to_en_digits($value));
}
function valid_digits(?string $value, int $min=1, ?int $max=null): bool {
    $s = digits_only($value);
    return preg_match('/^\d+$/', $s) && strlen($s) >= $min && ($max === null || strlen($s) <= $max);
}
function valid_name_text(?string $value): bool {
    return trim((string)$value) !== '' && (bool)preg_match('/^[\p{L}\s‌\-]+$/u', trim((string)$value));
}

function jalali_to_gregorian(int $jy, int $jm, int $jd): ?string {
    if ($jy < 1200 || $jy > 1600 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) return null;
    $jy -= 979; $jm -= 1; $jd -= 1;
    $j_day_no = 365 * $jy + intdiv($jy,33) * 8 + intdiv(($jy % 33) + 3,4);
    for ($i=0; $i<$jm; $i++) $j_day_no += ($i < 6) ? 31 : 30;
    $j_day_no += $jd;
    $g_day_no = $j_day_no + 79;
    $gy = 1600 + 400 * intdiv($g_day_no,146097);
    $g_day_no %= 146097;
    $leap = true;
    if ($g_day_no >= 36525) {
        $g_day_no--;
        $gy += 100 * intdiv($g_day_no,36524);
        $g_day_no %= 36524;
        if ($g_day_no >= 365) $g_day_no++; else $leap = false;
    }
    $gy += 4 * intdiv($g_day_no,1461);
    $g_day_no %= 1461;
    if ($g_day_no >= 366) { $leap = false; $g_day_no--; $gy += intdiv($g_day_no,365); $g_day_no %= 365; }
    $gd = $g_day_no + 1;
    $g_days_in_month = [31, ($leap ? 29 : 28), 31,30,31,30,31,31,30,31,30,31];
    $gm = 0;
    while ($gm < 12 && $gd > $g_days_in_month[$gm]) { $gd -= $g_days_in_month[$gm]; $gm++; }
    return sprintf('%04d-%02d-%02d', $gy, $gm + 1, $gd);
}
function jalali_to_input(?string $date): string {
    $v = gregorian_to_jalali($date);
    if ($v === '') return '';
    [$y,$m,$d] = explode('/', $v);
    return fa_digits($y.'/'.$m.'/'.$d);
}
function parse_jalali_input(?string $value): ?string {
    $v = trim((string)$value);
    $v = strtr($v, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
    if (!preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $v, $m)) return null;
    $g = jalali_to_gregorian((int)$m[1], (int)$m[2], (int)$m[3]);
    if (!$g) return null;
    return jalali_to_input($g) === fa_digits(sprintf('%04d/%02d/%02d', (int)$m[1], (int)$m[2], (int)$m[3])) ? $g : null;
}
function gregorian_to_jalali(?string $date): string {
    if (!$date || !preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $m)) return '';
    $gy=(int)$m[1]-1600; $gm=(int)$m[2]-1; $gd=(int)$m[3]-1;
    $g_day_no=365*$gy+intdiv($gy+3,4)-intdiv($gy+99,100)+intdiv($gy+399,400);
    $g_days_in_month=[31,28,31,30,31,30,31,31,30,31,30,31];
    for($i=0;$i<$gm;$i++) $g_day_no += $g_days_in_month[$i];
    if($gm>1 && (($gy+1600)%4===0 && (($gy+1600)%100!==0 || ($gy+1600)%400===0))) $g_day_no++;
    $g_day_no += $gd;
    $j_day_no=$g_day_no-79;
    $j_np=intdiv($j_day_no,12053); $j_day_no%=12053;
    $jy=979+33*$j_np+4*intdiv($j_day_no,1461);
    $j_day_no%=1461;
    if($j_day_no>=366){$jy+=intdiv($j_day_no-1,365);$j_day_no=($j_day_no-1)%365;}
    if($j_day_no<186){$jm=1+intdiv($j_day_no,31);$jd=1+($j_day_no%31);}else{$jm=7+intdiv($j_day_no-186,30);$jd=1+(($j_day_no-186)%30);}
    return sprintf('%04d/%02d/%02d',$jy,$jm,$jd);
}

function jalali_display(?string $date): string {
    if ($date === null || trim($date) === '') return '—';
    $dateOnly = substr(trim($date), 0, 10);
    $v = gregorian_to_jalali($dateOnly);
    if ($v === '') return '—';
    [$y, $m, $d] = explode('/', $v);
    return fa_digits($y).'/'.fa_digits($m).'/'.fa_digits($d);
}

/**
 * مسیر پوشه ذخیره‌سازی را برمی‌گرداند و اگر وجود نداشت می‌سازد.
 * روی سرورهایی که پوشه‌های خالی storage در انتقال فایل‌ها جا می‌مانند،
 * جلوی «ذخیره نشدن عکس» را می‌گیرد.
 *
 * @param string $sub نام زیرپوشه: profile_photos یا documents
 * @return string مسیر پوشه، همراه با اسلش پایانی
 */
function storage_path(string $sub): string {
    $base = __DIR__ . '/../storage';
    $dir  = $base . '/' . trim($sub, '/');
    if (!is_dir($dir)) {
        if (!is_dir($base)) @mkdir($base, 0775, true);
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir)) {
        throw new RuntimeException('پوشه ذخیره‌سازی «storage/' . $sub . '» ساخته نشد. لطفاً این پوشه را روی سرور بسازید.');
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('پوشه «storage/' . $sub . '» قابل نوشتن نیست. سطح دسترسی آن را روی ۷۷۵ تنظیم کنید.');
    }
    return $dir . '/';
}

/** یک نسخه از پرونده را به «عنوان فیلد => مقدار نمایشی» تبدیل می‌کند (ترتیب، همان ترتیب فرم است). */
function history_fields(array $r): array {
    $maritalLabels = ['single'=>'مجرد','married'=>'متأهل','separated'=>'متارکه'];
    $team = (string)($r['team_name'] ?? ($r['team_type'] ?? ''));
    if ($team === '' && ($r['team_no'] ?? null) !== null) $team = 'تیم ' . (int)$r['team_no'];
    return [
        'کد سازمانی'         => (string)($r['organizational_code'] ?? ''),
        'سمت'                => (string)($r['position_name'] ?? ($r['position_label'] ?? '')),
        'رسته'               => (string)($r['unit_label'] ?? ''),
        'شماره دسته'         => (string)($r['unit_number'] ?? ''),
        'شماره قائد'         => (string)($r['commander_number'] ?? ''),
        'گروه'               => ($r['group_no'] ?? null) === null ? '' : 'گروه ' . (int)$r['group_no'],
        'تیم'                => $team,
        'استان'              => (string)($r['province_name'] ?? ''),
        'شهرستان'            => (string)($r['city_name'] ?? ''),
        'نام'                => (string)($r['first_name'] ?? ''),
        'نام خانوادگی'       => (string)($r['last_name'] ?? ''),
        'کد ملی'             => (string)($r['national_id'] ?? ''),
        'نام پدر'            => (string)($r['father_name'] ?? ''),
        'تاریخ تولد'         => $r['birth_date'] ? jalali_display($r['birth_date']) : '',
        'وضعیت تأهل'         => $maritalLabels[(string)($r['marital_status'] ?? '')] ?? '',
        'تحصیلات'            => education_options()[(string)($r['education_status'] ?? '')] ?? '',
        'شماره تماس همراه'   => (string)($r['mobile'] ?? ''),
        'شماره تماس اضطراری' => (string)($r['emergency_phone'] ?? ''),
        'آدرس محل سکونت'     => (string)($r['residence_address'] ?? ''),
        'عنوان شغلی'         => (string)($r['secondary_job'] ?? ''),
        'آدرس محل کار'       => (string)($r['secondary_job_address'] ?? ''),
        'شماره شبا'          => (string)($r['iban'] ?? ''),
        'وضعیت عنصر'         => personnel_status_options()[(string)($r['personnel_status'] ?? 'active')] ?? '',
    ];
}

/**
 * تعداد «ویرایش واقعی» و آخرین تغییر هر عنصر را می‌شمارد.
 * ویرایشی که هیچ فیلد نمایشی را عوض نکرده باشد شمرده نمی‌شود، تا عدد فهرست
 * تاریخچه دقیقاً با مراحلی که در صفحه «مشاهده تغییرات» دیده می‌شود یکی باشد.
 *
 * @param array<int,array> $historyRows نسخه‌های ذخیره‌شده، مرتب بر اساس (personnel_id, changed_at, id)
 * @param array<int,array> $currentById پرونده فعلی هر عنصر، کلید = personnel_id
 * @return array<int,array{count:int,last_at:?string,last_by:?string,changes:int}>
 */
function history_change_summary(array $historyRows, array $currentById): array {
    $byPerson = [];
    foreach ($historyRows as $row) $byPerson[(int)$row['personnel_id']][] = $row;

    $out = [];
    foreach ($byPerson as $pid => $versions) {
        if (!isset($currentById[$pid])) continue;
        $snapshots = $versions;
        $snapshots[] = $currentById[$pid];
        $count = 0; $changes = 0; $lastAt = null; $lastBy = null;
        foreach ($versions as $i => $before) {
            $beforeFields = history_fields($before);
            $afterFields  = history_fields($snapshots[$i + 1]);
            $diff = 0;
            foreach ($beforeFields as $label => $old) {
                if (trim((string)$old) !== trim((string)($afterFields[$label] ?? ''))) $diff++;
            }
            if (!$diff) continue;
            $count++; $changes += $diff;
            $lastAt = $before['changed_at'] ?? $lastAt;
            $lastBy = $before['changed_by_name'] ?? $lastBy;
        }
        if (!$count) continue; // هیچ تغییر نمایشی نداشته است
        $out[$pid] = ['count'=>$count, 'changes'=>$changes, 'last_at'=>$lastAt, 'last_by'=>$lastBy];
    }
    return $out;
}
