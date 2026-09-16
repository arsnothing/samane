/* پاپ‌آپ مشترک سامانه: همیشه وسط صفحه، بدون اسکرول، مستقل از انیمیشن‌های صفحه */
(function () {
  var modals = document.querySelectorAll('.pv-modal');
  if (!modals.length) return;

  // پاپ‌آپ باید مستقیم فرزند body باشد تا هیچ عنصر transform‌داری position:fixed آن را نشکند.
  modals.forEach(function (m) { if (m.parentElement !== document.body) document.body.appendChild(m); });

  function closeAll() {
    modals.forEach(function (m) { m.classList.remove('open'); m.setAttribute('aria-hidden', 'true'); });
    document.body.classList.remove('modal-open');
  }

  function camel(name) { return name.replace(/-([a-z])/g, function (_, c) { return c.toUpperCase(); }); }

  document.querySelectorAll('[data-pv-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var modal = document.getElementById(btn.dataset.pvOpen);
      if (!modal) return;
      closeAll();

      var form = modal.querySelector('form');
      if (form) form.reset();

      // هر data-x روی دکمه، در پاپ‌آپ داخل [data-x-target] می‌نشیند.
      Array.prototype.forEach.call(btn.attributes, function (attr) {
        if (attr.name.indexOf('data-') !== 0 || attr.name === 'data-pv-open') return;
        var target = modal.querySelector('[' + attr.name + '-target]');
        if (!target) return;
        if (/^(INPUT|SELECT|TEXTAREA)$/.test(target.tagName)) target.value = attr.value;
        else target.textContent = attr.value;
      });

      // گواهی دوره: فهرست اسناد از data-cert-docs خوانده و داخل پاپ‌آپ رندر می‌شود.
      var viewer = modal.querySelector('[data-cert-viewer]');
      if (viewer) {
        var docs = [];
        try { docs = JSON.parse(btn.dataset.certDocs || '[]'); } catch (err) { docs = []; }
        viewer.innerHTML = docs.length ? docs.map(function (d) {
          var url = 'personnel_file.php?type=training&id=' + encodeURIComponent(d.id);
          var name = String(d.name || '').replace(/[<>&"]/g, '');
          return /^image\//.test(d.mime || '')
            ? '<img src="' + url + '" alt="' + name + '" loading="lazy">'
            : '<a class="cert-file" href="' + url + '" target="_blank" rel="noopener">📄 <span>' + name + '</span></a>';
        }).join('') : '<div class="cert-empty">سندی برای این دوره ثبت نشده است.</div>';
      }

      // برچسب «نوع تشویق / نوع اخطار / نوع توبیخ» از عنوان همان پنل ساخته می‌شود.
      var title = btn.dataset.discTitle;
      if (title) {
        var label = modal.querySelector('[data-disc-subject-label]');
        if (label) label.textContent = 'نوع ' + title;
        var input = modal.querySelector('[data-disc-subject-input]');
        if (input) input.placeholder = 'نوع ' + title + ' را وارد کنید';
      }

      modal.classList.add('open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('modal-open');
      setTimeout(function () {
        var first = modal.querySelector('input:not([type=hidden]):not([type=file]):not([readonly])');
        if (first) first.focus();
      }, 40);
    });
  });

  document.querySelectorAll('[data-pv-close]').forEach(function (el) { el.addEventListener('click', closeAll); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); });

  // ماسک تاریخ شمسی داخل پاپ‌آپ‌ها
  var fa = '۰۱۲۳۴۵۶۷۸۹';
  function toEn(v) { return String(v || '').replace(/[۰-۹]/g, function (d) { return String(fa.indexOf(d)); }).replace(/\D/g, ''); }
  document.querySelectorAll('.pv-modal input.jalali').forEach(function (el) {
    function mask() {
      var v = toEn(el.value).slice(0, 8), out = v.slice(0, 4);
      if (v.length > 4) out += '/' + v.slice(4, 6);
      if (v.length > 6) out += '/' + v.slice(6, 8);
      el.value = out.replace(/\d/g, function (d) { return fa[+d]; });
    }
    el.addEventListener('input', mask);
    el.addEventListener('paste', function () { setTimeout(mask, 0); });
    mask();
  });

  // نمایش نام فایل‌های انتخاب‌شده روی دکمه آپلود
  document.querySelectorAll('.pv-upload-card input[type=file]').forEach(function (input) {
    var picker = input.closest('.file-picker');
    var label = picker ? picker.querySelector('[data-file-label]') : null;
    if (!label) return;
    input.addEventListener('change', function () {
      var n = input.files.length;
      label.textContent = n ? (n === 1 ? input.files[0].name : n.toLocaleString('fa-IR') + ' فایل انتخاب شد') : label.dataset.fileLabel;
    });
  });
})();
