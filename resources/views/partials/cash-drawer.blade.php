<section class="card cash-drawer-panel" id="cashDrawerPanel" data-overview="0">
  <div class="cash-panel-header"><div><h3 class="panel-heading">Your daily reconciliation</h3><p>Start with your float. Finish with the cash you actually count.</p></div><button class="btn btn-secondary" type="button" onclick="renderCashShift()">Refresh</button></div>
  <p id="cashStatus" class="cash-sync-status" role="status">Loading drawer information…</p>
  <form id="cashOpenForm" class="hidden" onsubmit="event.preventDefault(); openCashDrawer()">
    <h3>Open today's drawer</h3>
    <div id="cashOpenLateAlert" class="alert alert-warning hidden" style="margin: 0.75rem 0; padding: 0.75rem 1rem; border-radius: 8px; background: #FEF3C7; color: #92400E; border: 1px solid #FCD34D;">
      <strong>Late opening:</strong> <span id="cashOpenLateText">Opening past grace cutoff. Please provide an explanation note.</span>
    </div>
    <label class="form-label" for="cashFloat">Starting cash</label><input id="cashFloat" class="form-input" type="number" min="0" max="1000000" step="0.01" required>
    <p class="settings-help">Cash before the first transaction today. Recorded cash sales and accepted collections are added automatically.</p>
    <div id="cashOpeningNoteGroup" class="hidden" style="margin: 0.75rem 0;">
      <label class="form-label" for="cashOpeningNote">Late opening reason <span style="color:#B91C1C">*</span></label>
      <input id="cashOpeningNote" class="form-input" type="text" maxlength="500" placeholder="e.g. POS Startup delay, float delivery...">
      <div style="display:flex;gap:0.4rem;flex-wrap:wrap;margin-top:0.4rem;">
        <button type="button" class="btn btn-secondary btn-xs" onclick="setOpeningNote('POS Startup & System Verification')">POS Startup</button>
        <button type="button" class="btn btn-secondary btn-xs" onclick="setOpeningNote('Morning Float Handover Delay')">Float Delay</button>
        <button type="button" class="btn btn-secondary btn-xs" onclick="setOpeningNote('Station Maintenance & Power Check')">Maintenance</button>
        <button type="button" class="btn btn-secondary btn-xs" onclick="setOpeningNote('Transit & Commute Transit Delay')">Transit Delay</button>
      </div>
    </div>
    <button id="cashOpenBtn" class="btn btn-primary" type="submit">Open drawer</button>
  </form>
  <form id="cashCloseForm" class="hidden" onsubmit="event.preventDefault(); closeCashDrawer()">
    <div id="cashSummary"></div>
    <div class="cash-count-entry">
    <h3>Count and close</h3>
    <label class="form-label" for="cashCounted">Counted cash, including starting cash</label><input id="cashCounted" class="form-input" type="number" min="0" max="1000000" step="0.01" required oninput="handleCountedCashChange()">
    <div id="cashVarianceBadge" role="status" aria-live="polite" style="display:none;margin:0.75rem 0;padding:0.6rem 0.85rem;border-radius:8px;font-weight:600;font-size:0.875rem;"></div>
    <div id="cashNoteGroup">
      <label class="form-label" id="cashNoteLabel" for="cashNote">Explanation (required for a shortage or overage)</label>
      <textarea id="cashNote" class="form-input" maxlength="500"></textarea>
      <p id="cashNoteHelp" class="settings-help">Resolve pending collections first. Closing blocks new sales and collections for today.</p>
    </div>
    <button id="cashCloseBtn" class="btn btn-primary" type="submit">Count and close drawer</button>
    </div>
  </form>
  <div id="cashResultCard" class="hidden"><h3>Closed drawer</h3><div id="cashResult"></div></div>
  <div class="cash-history-section"><h3>Earlier closed drawers</h3><p class="settings-help">Your 14 most recent closed records.</p><div id="cashHistory"></div></div>
</section>
@push('scripts')
<script src="{{ asset('js/admin/cash.js') }}?v={{ asset_version('js/admin/cash.js') }}"></script>
@endpush
