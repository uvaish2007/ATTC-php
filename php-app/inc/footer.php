      </div><!-- .container -->
    </main>
  </div><!-- .main -->
</div><!-- .app -->

<!-- Shown when a locked (maintenance) module is clicked in the sidebar. -->
<dialog class="modal" id="lockedModuleDlg" style="max-width:26rem">
  <div class="modal-head"><div><h3 id="lockedModuleTitle">Coming Soon</h3></div></div>
  <div class="modal-body">
    <p style="color:var(--ink-muted); font-size:14px; margin:0">
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
</script>
</body>
</html>
