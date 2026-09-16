/* نوار فیلتر مشترک: پاپ‌آپ انتخاب + به‌روزرسانی خودکار لیست با تغییر هر فیلد */
(function () {
  /* ارقام فارسی/عربی را برای مقایسه به لاتین برمی‌گرداند تا جست‌وجو با هر دو نوع رقم کار کند. */
  function faFold(v){
    return String(v == null ? '' : v).replace(/[۰-۹٠-٩]/g, function (d) {
      var i = '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);
      return i > -1 ? String(i) : String('٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    }).toLocaleLowerCase('fa-IR');
  }

  function initSmartFilter(root) {
    var trigger = root.querySelector('.smart-filter-trigger');
    var value = root.querySelector('.smart-filter-value');
    var search = root.querySelector('.smart-filter-search');
    var options = root.querySelector('.smart-filter-options');
    var select = root.querySelector('.smart-filter-native');
    if (!trigger || !value || !options || !select) return;
    var placeholder = root.dataset.placeholder || '';

    function close() { root.classList.remove('open'); trigger.setAttribute('aria-expanded', 'false'); }

    // اگر گزینه‌ای با مقدار خالی وجود دارد، فیلتر قابل پاک‌کردن است.
    var clearable = !!select.querySelector('option[value=""]');
    var clearBtn = null;
    if (clearable) {
      clearBtn = document.createElement('span');
      clearBtn.className = 'smart-filter-clear';
      clearBtn.textContent = '×';
      clearBtn.setAttribute('role', 'button');
      clearBtn.setAttribute('tabindex', '0');
      clearBtn.setAttribute('aria-label', 'پاک کردن ' + (placeholder || 'فیلتر'));
      clearBtn.title = 'پاک کردن';
      root.appendChild(clearBtn);
      var doClear = function (e) {
        e.preventDefault(); e.stopPropagation();
        select.value = '';
        sync(); close();
        select.dispatchEvent(new Event('change', { bubbles: true }));
      };
      clearBtn.addEventListener('click', doClear);
      clearBtn.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') doClear(e); });
    }

    function sync() {
      var opt = select.options[select.selectedIndex];
      var hasValue = !!(opt && opt.value);
      value.textContent = hasValue ? opt.textContent.trim() : placeholder;
      root.classList.toggle('has-value', hasValue && clearable);
    }

    function render() {
      var term = faFold(((search && search.value) || '').trim());
      var list = Array.prototype.filter.call(select.options, function (o) {
        // گزینه با مقدار خالی همان متن راهنماست (نوع رسته، دوره آموزشی، استان ...)
        // و هیچ‌وقت نباید در فهرست بیاید؛ پاک‌کردن فیلتر با دکمه × انجام می‌شود.
        if (o.disabled || o.hidden || o.value === '') return false;
        return !term || faFold(o.textContent).indexOf(term) !== -1;
      });
      if (!list.length) { options.innerHTML = '<div class="smart-filter-empty">موردی پیدا نشد</div>'; return; }
      options.innerHTML = list.map(function (o) {
        return '<div class="smart-filter-option' + (o.selected ? ' active' : '') + '" role="option" data-value="' +
          String(o.value).replace(/"/g, '&quot;') + '">' + o.textContent + '</div>';
      }).join('');
      options.querySelectorAll('.smart-filter-option').forEach(function (el) {
        el.addEventListener('click', function () {
          select.value = el.dataset.value;
          sync();
          close();
          select.dispatchEvent(new Event('change', { bubbles: true }));
        });
      });
    }

    trigger.addEventListener('click', function (e) {
      e.preventDefault();
      if (trigger.disabled) return;
      var willOpen = !root.classList.contains('open');
      document.querySelectorAll('.smart-filter.open').forEach(function (x) {
        x.classList.remove('open');
        var t = x.querySelector('.smart-filter-trigger');
        if (t) t.setAttribute('aria-expanded', 'false');
      });
      if (!willOpen) return;
      root.classList.add('open');
      trigger.setAttribute('aria-expanded', 'true');
      if (search) { search.value = ''; }
      render();
      if (search) setTimeout(function () { search.focus(); }, 0);
    });
    if (search) search.addEventListener('input', render);
    select.addEventListener('change', sync);
    sync();
    render();
  }

  document.querySelectorAll('.smart-filter').forEach(initSmartFilter);
  document.addEventListener('click', function (e) {
    document.querySelectorAll('.smart-filter.open').forEach(function (root) {
      if (!root.contains(e.target)) {
        root.classList.remove('open');
        var t = root.querySelector('.smart-filter-trigger');
        if (t) t.setAttribute('aria-expanded', 'false');
      }
    });
  });

  // با تغییر هر فیلتر، لیست بدون نیاز به دکمه جستجو به‌روز می‌شود.
  document.querySelectorAll('form[data-auto-filter]').forEach(function (form) {
    function submit() { form.requestSubmit ? form.requestSubmit() : form.submit(); }

    form.querySelectorAll('select').forEach(function (select) {
      select.addEventListener('change', function () {
        // با عوض‌شدن استان، شهرستان قبلی باید پاک شود.
        if (select.name === 'province_id') {
          var city = form.querySelector('[name="city_id"]');
          if (city) city.value = '';
        }
        submit();
      });
    });

    // ورودی‌های متنی فقط با Enter یا خروج از فیلد اعمال می‌شوند،
    // تا تایپ کُند وسط کار باعث ارسال ناقص نشود.
    form.querySelectorAll('input[type="search"], input[type="text"]').forEach(function (input) {
      var applied = input.value;
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); applied = input.value; input.blur(); submit(); }
      });
      input.addEventListener('blur', function () {
        if (input.value !== applied) { applied = input.value; submit(); }
      });
    });
  });
})();
