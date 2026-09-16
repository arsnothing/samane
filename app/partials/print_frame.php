<?php
/**
 * قاب چاپ (PDF): کادر دور صفحه + سربرگ سازمانی.
 * سربرگ داخل <thead> یک جدول پوششی قرار می‌گیرد تا مرورگر آن را
 * در بالای «همه» صفحات خروجی تکرار کند.
 * پیش از فراخوانی، در صورت نیاز $printTitle را مقداردهی کنید.
 * بستن جدول پوششی در app/partials/footer.php انجام می‌شود.
 */
$printTitle = $printTitle ?? '';
// $printLandscape = true;  →  خروجی PDF این صفحه افقی (A4 landscape) می‌شود.
$printLandscape = !empty($printLandscape);
$printFrameOpen = true;
?>
<?php if ($printLandscape): ?>
<style>@media print{@page{size:A4 landscape;margin:10mm 9mm}}</style>
<?php endif; ?>
<div class="print-only print-page-border" aria-hidden="true"></div>
<table class="print-sheet"><thead class="print-sheet-head"><tr><td>
  <div class="print-only print-letterhead" aria-hidden="true">
    <img class="print-emblem" src="<?= e(asset_url('assets/letterhead-logo.png')) ?>" alt="">
    <div class="print-letterhead-text">
      <span class="print-basmala">باسمه تعالی</span>
      <strong>فرماندهی انتظامی جمهوری اسلامی ایران</strong>
      <strong>سازمان رزم سوم فراجا (یگان انصار)</strong>
      <strong>معاونت اطلاعات قائد شمیرانات</strong>
    </div>
  </div>
<?php if ($printTitle !== ''): ?>
  <div class="print-only print-doc-title" aria-hidden="true"><?= e($printTitle) ?></div>
<?php endif; ?>
</td></tr></thead><tbody class="print-sheet-body"><tr><td class="print-sheet-cell">
