<?php if (!empty($printTitle)): ?>
<div class="print-only print-signature" aria-hidden="true">
  <span class="print-regards">با تجدید احترام</span>
  <div class="print-sign-row">
    <div><span>مسئول اطلاعات</span><strong>امیر هاشم‌زاده</strong></div>
    <div><span>قائد شمیرانات</span><strong>محمدصادق ذوالقدر</strong></div>
  </div>
</div>
<?php endif; ?>
<?php if (!empty($printFrameOpen)): ?></td></tr></tbody></table><?php endif; ?>
</main><footer>کلیه حقوق مادی و معنوی این سامانه، متعلق به سازمان رزم سوم فراجا می‌باشد.</footer><script>
(function(){
  const fa='۰۱۲۳۴۵۶۷۸۹';
  const en='0123456789';
  const toFa=(v)=>String(v??'').replace(/[0-9]/g,d=>fa[en.indexOf(d)]);
  const toEn=(v)=>String(v??'').replace(/[۰-۹]/g,d=>en[fa.indexOf(d)]);
  const onlyDigits=(v)=>toEn(v).replace(/\D/g,'');

  function formatJalali(value){
    const digits=onlyDigits(value).slice(0,8);
    let out=digits.slice(0,4);
    if(digits.length>4) out+='/'+digits.slice(4,6);
    if(digits.length>6) out+='/'+digits.slice(6,8);
    return toFa(out);
  }

  const FA_SKIP = 'input[type=password],input[type=email],input[type=url],input[name=username],[data-latin-digits],.jalali';
  const FA_TEXT_INPUTS = 'input:not([type]),input[type=text],input[type=search],input[type=tel],textarea';
  function faInput(input){
    const next = toFa(input.value);
    if(next === input.value) return;
    const start = input.selectionStart, end = input.selectionEnd;
    input.value = next;
    try { input.setSelectionRange(start, end); } catch (err) {}
  }

  function bind(root=document){
    root.querySelectorAll?.('input.jalali').forEach((input)=>{
      input.setAttribute('inputmode','numeric');
      input.setAttribute('maxlength','10');
      input.setAttribute('autocomplete','off');
      input.placeholder='۱۴۰۷/۰۷/۰۷';
      input.value=formatJalali(input.value);
      input.addEventListener('input',()=>{ input.value=formatJalali(input.value); });
      input.addEventListener('paste',()=>setTimeout(()=>{ input.value=formatJalali(input.value); },0));
      input.addEventListener('keydown',(e)=>{ if(e.key==='/' || e.key==='\\') e.preventDefault(); });
    });

    /* هر رقمی که کاربر در هر کادر متنی وارد کند، فارسی نمایش داده می‌شود.
       استثناها: نام کاربری و رمز (باید عیناً به سرور برود) و هر فیلدی که
       صریحاً data-latin-digits داشته باشد. سمت سرور همه‌جا با fa_to_en_digits()
       به لاتین برمی‌گردد، پس ذخیره و جست‌وجو دست‌نخورده کار می‌کند. */
    root.querySelectorAll?.(FA_TEXT_INPUTS).forEach((input)=>{
      if(input.dataset.persianDigitsBound) return;
      if(input.matches(FA_SKIP)) return;
      input.dataset.persianDigitsBound='1';
      faInput(input);
      input.addEventListener('input',()=>faInput(input));
      input.addEventListener('paste',()=>setTimeout(()=>faInput(input),0));
    });

    root.querySelectorAll?.('input[placeholder],textarea[placeholder]').forEach((el)=>{
      if(el.type!=='hidden' && el.type!=='password' && el.type!=='file' && !el.classList.contains('jalali')){
        el.placeholder=toFa(el.placeholder);
      }
    });
  }

  function convertText(root){
    const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT,{acceptNode(node){
      const p=node.parentElement;
      // متن گزینه‌های <option> هم فارسی می‌شود (مقدار value دست‌نخورده می‌ماند).
      if(!p || p.closest('script,style,template') || /^(INPUT|TEXTAREA|SELECT)$/.test(p.tagName)) return NodeFilter.FILTER_REJECT;
      return /[0-9]/.test(node.nodeValue||'') ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
    }});
    const nodes=[]; let n;
    while((n=walker.nextNode())) nodes.push(n);
    nodes.forEach(n=>{n.nodeValue=toFa(n.nodeValue);});
  }

  function run(){ bind(document); convertText(document.body); }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',run,{once:true}); else run();

  // Dynamic forms (equipment/payment rows) added after page load.
  new MutationObserver((mutations)=>{
    for(const m of mutations){
      for(const node of m.addedNodes){ if(node.nodeType===1) bind(node); }
    }
  }).observe(document.body,{childList:true,subtree:true});
})();
</script>
<script>
/* ---------------------------------------------------------------------------
 * ارتقای خودکار همه <select>های بومی به همان دراپ‌داون سفارشی سامانه،
 * تا منوی بازشو در تمام صفحات (از جمله «افزودن عنصر جدید») یک شکل باشد.
 * select اصلی سر جایش می‌ماند (نامرئی ولی قابل فوکوس) تا ارسال فرم،
 * اعتبارسنجی مرورگر و رویداد change صفحات دست‌نخورده کار کند.
 * ------------------------------------------------------------------------- */
(function(){
  const SEARCH_FROM = 7;              // از این تعداد گزینه به بالا، کادر جست‌وجو نشان داده شود
  let openBox = null;

  const isPlaceholder = (o)=> o && o.value==='' && (o.disabled || o.hidden);

  function skip(sel){
    return !sel || sel.multiple || sel.size>1 || sel.dataset.ddDone
      || sel.classList.contains('smart-select-native')
      || sel.classList.contains('smart-filter-native')
      || sel.hasAttribute('data-no-dropdown')
      || sel.closest('.smart-select,.smart-filter,.filter-modal-field');
  }

  function build(sel){
    if(skip(sel)) return;
    sel.dataset.ddDone='1';

    const box=document.createElement('div');
    box.className='smart-select dd-auto';
    sel.parentNode.insertBefore(box, sel);
    box.appendChild(sel);
    sel.classList.add('smart-select-native');

    const trigger=document.createElement('button');
    trigger.type='button';
    trigger.className='smart-select-trigger';
    trigger.setAttribute('aria-haspopup','listbox');
    trigger.setAttribute('aria-expanded','false');
    trigger.innerHTML='<span class="smart-select-value"></span><span class="smart-select-arrow" aria-hidden="true"></span>';

    const menu=document.createElement('div');
    menu.className='smart-select-menu';
    menu.setAttribute('role','listbox');
    menu.innerHTML='<div class="smart-select-search-wrap"><input type="search" class="smart-select-search" autocomplete="off"></div><div class="smart-select-options"></div>';

    box.appendChild(trigger);
    box.appendChild(menu);

    const valueEl=trigger.querySelector('.smart-select-value');
    const searchWrap=menu.querySelector('.smart-select-search-wrap');
    const search=menu.querySelector('.smart-select-search');
    const list=menu.querySelector('.smart-select-options');

    search.placeholder='جست‌وجو';

    function realOptions(){
      return Array.from(sel.options).filter(o=>!isPlaceholder(o));
    }

    function paintTrigger(){
      const opt=sel.selectedIndex>-1 ? sel.options[sel.selectedIndex] : null;
      const ph=Array.from(sel.options).find(isPlaceholder);
      if(opt && !isPlaceholder(opt)){
        valueEl.textContent=opt.textContent.trim();
        valueEl.classList.remove('is-placeholder');
      }else{
        valueEl.textContent=(ph?ph.textContent.trim():'') || sel.getAttribute('data-placeholder') || 'انتخاب کنید';
        valueEl.classList.add('is-placeholder');
      }
      trigger.disabled=sel.disabled;
      trigger.title=valueEl.textContent;
    }

    const faFold=(v)=>String(v??'').replace(/[۰-۹٠-٩]/g,d=>{const i='۰۱۲۳۴۵۶۷۸۹'.indexOf(d);return i>-1?String(i):String('٠١٢٣٤٥٦٧٨٩'.indexOf(d));}).toLocaleLowerCase('fa-IR');
    function paintList(filter){
      const q=faFold((filter||'').trim());
      const opts=realOptions();
      searchWrap.hidden = opts.length < SEARCH_FROM;
      const shown=opts.filter(o=>!q || faFold(o.textContent).includes(q));
      if(!shown.length){
        list.innerHTML='<div class="smart-select-empty">موردی پیدا نشد</div>';
        return;
      }
      list.innerHTML='';
      shown.forEach((o)=>{
        const row=document.createElement('div');
        row.className='smart-select-option'+(o.selected?' active':'')+(o.disabled?' is-disabled':'');
        row.setAttribute('role','option');
        row.setAttribute('aria-selected', o.selected?'true':'false');
        row.textContent=o.textContent.trim();
        row.dataset.value=o.value;
        if(o.disabled) row.setAttribute('aria-disabled','true');
        list.appendChild(row);
      });
    }

    function close(){
      box.classList.remove('open','drop-up');
      trigger.setAttribute('aria-expanded','false');
      if(openBox===box) openBox=null;
    }

    function open(){
      if(sel.disabled) return;
      if(openBox && openBox!==box) openBox.dispatchEvent(new CustomEvent('dd:close'));
      paintList('');
      search.value='';
      box.classList.add('open');
      trigger.setAttribute('aria-expanded','true');
      openBox=box;
      // اگر پایین صفحه جا نبود، منو رو به بالا باز شود.
      const r=trigger.getBoundingClientRect(), h=menu.offsetHeight||260;
      if(r.bottom+h+16>window.innerHeight && r.top>h+16) box.classList.add('drop-up');
      if(!searchWrap.hidden) setTimeout(()=>search.focus(),10);
    }

    box.addEventListener('dd:close', close);

    trigger.addEventListener('click',(ev)=>{
      // جلوگیری از فعال‌سازی <label> که کلیک را دوباره می‌فرستد و منو را می‌بندد.
      ev.preventDefault(); ev.stopPropagation();
      box.classList.contains('open') ? close() : open();
    });

    menu.addEventListener('click',(ev)=>{ ev.preventDefault(); ev.stopPropagation(); });

    list.addEventListener('click',(ev)=>{
      const row=ev.target.closest('.smart-select-option');
      if(!row || row.classList.contains('is-disabled')) return;
      ev.preventDefault(); ev.stopPropagation();
      if(sel.value!==row.dataset.value){
        sel.value=row.dataset.value;
        sel.dispatchEvent(new Event('input',{bubbles:true}));
        sel.dispatchEvent(new Event('change',{bubbles:true}));
      }
      paintTrigger();
      close();
      trigger.focus();
    });

    search.addEventListener('input',()=>paintList(search.value));
    search.addEventListener('keydown',(ev)=>{ if(ev.key==='Escape'){ ev.stopPropagation(); close(); trigger.focus(); } });

    trigger.addEventListener('keydown',(ev)=>{
      if(ev.key==='ArrowDown'||ev.key==='Enter'||ev.key===' '){ ev.preventDefault(); if(!box.classList.contains('open')) open(); }
      else if(ev.key==='Escape'){ close(); }
    });

    // تغییرهایی که کدِ خودِ صفحه اعمال می‌کند (بازسازی گزینه‌ها، فعال/غیرفعال‌کردن، ست‌کردن مقدار)
    sel.addEventListener('change', paintTrigger);
    new MutationObserver(()=>{ paintTrigger(); if(box.classList.contains('open')) paintList(search.value); })
      .observe(sel,{childList:true,subtree:true,attributes:true,attributeFilter:['disabled','value']});

    paintTrigger();
  }

  function scan(root){
    (root||document).querySelectorAll?.('select').forEach(build);
    if(root instanceof Element && root.matches?.('select')) build(root);
    markPlaceholders();
  }

  /* دراپ‌داون‌هایی که مؤلفهٔ اختصاصی خودشان را دارند (استان/شهرستان/منطقه و
     فیلترهای فهرست‌ها) هم باید متن راهنمایشان کم‌رنگ باشد، مثل بقیه. */
  function markPlaceholders(){
    document.querySelectorAll('.smart-select:not(.dd-auto),.smart-filter').forEach((box)=>{
      const sel=box.querySelector('select');
      const val=box.querySelector('.smart-select-value,.smart-filter-value');
      if(!sel||!val) return;
      val.classList.toggle('is-placeholder', !sel.value);
    });
  }
  document.addEventListener('change',(ev)=>{ if(ev.target && ev.target.tagName==='SELECT') markPlaceholders(); });
  document.addEventListener('click',()=>setTimeout(markPlaceholders,0));

  document.addEventListener('click',(ev)=>{
    if(openBox && !openBox.contains(ev.target)) openBox.dispatchEvent(new CustomEvent('dd:close'));
  });
  document.addEventListener('keydown',(ev)=>{
    if(ev.key==='Escape' && openBox) openBox.dispatchEvent(new CustomEvent('dd:close'));
  });

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',()=>scan(document),{once:true});
  else scan(document);
  new MutationObserver((ms)=>{for(const m of ms){for(const n of m.addedNodes){if(n.nodeType===1) scan(n);}}})
    .observe(document.documentElement,{childList:true,subtree:true});
})();
</script>
<script>
/* هر ورودی فایل/عکس پس از انتخاب، نام فایل را به‌جای متن راهنما نشان می‌دهد. */
(function(){
  const fa='۰۱۲۳۴۵۶۷۸۹';
  const toFa=(v)=>String(v??'').replace(/[0-9]/g,d=>fa[+d]);

  function shorten(name){
    name=String(name||'');
    if(name.length<=34) return name;
    const dot=name.lastIndexOf('.');
    const ext=dot>-1 ? name.slice(dot) : '';
    return name.slice(0,26)+'…'+ext;
  }

  function labelOf(picker){
    const span=picker.querySelector('span'); if(!span) return null;
    if(!span.dataset.fileLabel) span.dataset.fileLabel=span.textContent.trim();
    return span;
  }

  function render(input){
    const picker=input.closest('.file-picker'); if(!picker) return;
    const span=labelOf(picker); if(!span) return;
    const files=input.files;
    if(files && files.length===1){
      span.textContent=shorten(files[0].name);
      span.title=files[0].name;
      picker.classList.add('has-file');
    }else if(files && files.length>1){
      span.textContent=toFa(String(files.length))+' فایل انتخاب شد';
      span.title=Array.from(files).map(f=>f.name).join('، ');
      picker.classList.add('has-file');
    }else{
      span.textContent=span.dataset.fileLabel||'انتخاب فایل';
      span.removeAttribute('title');
      picker.classList.remove('has-file');
    }
  }

  function initAll(root){
    (root||document).querySelectorAll?.('.file-picker input[type="file"]').forEach((input)=>{
      labelOf(input.closest('.file-picker'));
      if(input.files && input.files.length) render(input);
    });
  }

  document.addEventListener('change',(ev)=>{
    const input=ev.target;
    if(input && input.matches && input.matches('.file-picker input[type="file"]')) render(input);
  });
  // فرم که ریست شود، متن راهنما برمی‌گردد.
  document.addEventListener('reset',()=>setTimeout(()=>{
    document.querySelectorAll('.file-picker input[type="file"]').forEach(render);
  },0));

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',()=>initAll(document),{once:true});
  else initAll(document);
  new MutationObserver((ms)=>{for(const m of ms){for(const n of m.addedNodes){if(n.nodeType===1) initAll(n);}}})
    .observe(document.documentElement,{childList:true,subtree:true});
})();
</script>
<script>
/* دکمه بازگشت در همهٔ صفحات یک جایگذاری، سایز و شکل واحد دارد:
   همیشه در ردیف تیتر صفحه (و چپِ آن ردیف). صفحه‌هایی که تیتر ندارند
   هم همان ردیف را می‌گیرند تا جای دکمه در کل سامانه یکی بماند. */
(function(){
  var bar=document.querySelector('.global-backbar'); if(!bar) return;
  var btn=bar.querySelector('.global-back-btn'); if(!btn) return;
  var head=document.querySelector('.page-head');
  if(!head){
    head=document.createElement('section');
    head.className='page-head page-head-back-only';
    bar.parentNode.insertBefore(head,bar);
  }
  head.classList.add('has-back'); head.appendChild(btn); bar.remove();
})();
</script>
</body></html>
