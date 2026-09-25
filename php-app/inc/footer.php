      </div><!-- .container -->
    </main>
  </div><!-- .main -->
</div><!-- .app -->

<!-- Shown when a locked (maintenance) module is clicked in the sidebar. -->
<dialog class="modal" id="lockedModuleDlg" style="max-width:26rem">
  <div class="modal-head"><div><h3 id="lockedModuleTitle">Coming Soon</h3></div></div>
  <div class="modal-body">
    <p class="modal-text">
      This module is in maintenance for the current alpha release. It will be
      switched on soon — for now the alpha focuses on Targets.
    </p>
  </div>
  <div class="modal-foot">
    <button type="button" class="btn btn-primary btn-sm" onclick="this.closest('dialog').close()">Got it</button>
  </div>
</dialog>
<script>
  function showLockedModule(label) {
    var dlg = document.getElementById('lockedModuleDlg');
    document.getElementById('lockedModuleTitle').textContent = (label || 'This module') + ' · Coming Soon';
    if (dlg.showModal) { dlg.showModal(); } else { alert(label + ' is coming soon.'); }
  }

  (function () {
    var nav = document.querySelector('.sidebar-nav');
    if (!nav) return;

    var KEY = 'atts.navScroll';

    function updateFade() {
      var max = nav.scrollHeight - nav.clientHeight;
      nav.classList.toggle('fade-top', nav.scrollTop > 2);
      nav.classList.toggle('fade-bottom', max > 2 && nav.scrollTop < max - 2);
    }

    try {
      var saved = parseInt(sessionStorage.getItem(KEY), 10);
      if (!isNaN(saved)) nav.scrollTop = saved;
    } catch (e) {}

    var active = nav.querySelector('.nav-link.active');
    if (active) {
      var nr = nav.getBoundingClientRect(), ar = active.getBoundingClientRect(), pad = 36;
      if (ar.bottom > nr.bottom - pad)   nav.scrollTop += ar.bottom - nr.bottom + pad;
      else if (ar.top < nr.top + pad)    nav.scrollTop -= nr.top - ar.top + pad;
    }

    nav.addEventListener('scroll', function () {
      updateFade();
      try { sessionStorage.setItem(KEY, String(nav.scrollTop)); } catch (e) {}
    }, { passive: true });
    window.addEventListener('resize', updateFade);
    updateFade();
  })();

  (function () {
    var meter = document.createElement('span');
    meter.setAttribute('aria-hidden', 'true');
    meter.style.cssText = 'position:absolute;left:-9999px;top:0;visibility:hidden;white-space:pre';
    document.body.appendChild(meter);
    function fit(sel) {
      var cs = getComputedStyle(sel), opt = sel.options[sel.selectedIndex];
      meter.style.fontFamily = cs.fontFamily;
      meter.style.fontSize = cs.fontSize;
      meter.style.fontWeight = cs.fontWeight;
      meter.style.letterSpacing = cs.letterSpacing;
      meter.textContent = opt ? opt.text : '';
      sel.style.width = Math.ceil(meter.getBoundingClientRect().width
        + parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight) + 4) + 'px';
    }

    document.querySelectorAll('.fb-field').forEach(function (field) {
      var sel = field.querySelector(':scope > select');   // not a date picker's own selects
      if (sel) {
        fit(sel);
        sel.addEventListener('change', function () { fit(sel); });
      }
      function update() {
        var set = false;
        if (sel) {
          var def = sel.hasAttribute('data-default') ? sel.getAttribute('data-default')
                  : (sel.options.length ? sel.options[0].value : '');
          set = sel.value !== def;
        } else {
          field.querySelectorAll('input:not([type=hidden])').forEach(function (i) {
            if (i.value.trim() !== '') set = true;
          });
        }
        field.classList.toggle('is-set', set);
      }
      field.addEventListener('change', update);
      field.addEventListener('input', update);
      update();
    });

    // Measured with the fallback font if Inter was still loading; measure again.
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(function () {
        document.querySelectorAll('.fb-field > select').forEach(fit);
      });
    }
  })();

  (function () {
    var seq = 0;

    function wire(root) {
      root.querySelectorAll('.field, .fb-field').forEach(function (field) {
        var label = field.querySelector(':scope > label');
        if (!label || label.htmlFor) return;

        var control = field.querySelector('input:not([type=hidden]), select, textarea');
        if (!control || label.contains(control)) return;

        if (!control.id) { control.id = 'fld-' + (++seq); }
        label.htmlFor = control.id;
      });
    }

    wire(document);

    if (window.MutationObserver) {
      new MutationObserver(function (records) {
        records.forEach(function (r) {
          r.addedNodes.forEach(function (n) {
            if (n.nodeType === 1) { wire(n.parentNode || document); }
          });
        });
      }).observe(document.body, { childList: true, subtree: true });
    }
  })();

  (function () {
    var toggle = document.getElementById('navToggle');
    var scrim  = document.getElementById('navScrim');
    if (!toggle || !scrim) return;

    function setNav(open) {
      document.body.classList.toggle('nav-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    }

    toggle.addEventListener('click', function () {
      setNav(!document.body.classList.contains('nav-open'));
    });
    scrim.addEventListener('click', function () { setNav(false); });
    document.addEventListener('keydown', function (evt) {
      if (evt.key === 'Escape') setNav(false);
    });

    // Following a link closes the drawer, so the next page never loads behind it.
    document.querySelectorAll('.sidebar-nav a').forEach(function (a) {
      a.addEventListener('click', function () { setNav(false); });
    });

    var wide = window.matchMedia('(min-width: 1025px)');
    var onWide = function (m) { if (m.matches) setNav(false); };
    if (wide.addEventListener) wide.addEventListener('change', onWide);
    else if (wide.addListener) wide.addListener(onWide);
  })();
</script>
</body>
</html>
