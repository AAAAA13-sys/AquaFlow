// Shared cashier reconciliation and read-only manager totals.
let currentCashShift = null, cashBusy = false, cashReadVersion = 0;

function formatTimingTag(timing, isClosed) {
  if (!timing) return '';
  const openClass = timing.opening_status === 'on_time' ? 'pill-ok' : (timing.opening_status === 'late' ? 'pill-bad' : 'pill-info');
  let html = '<div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin:0.5rem 0;">';
  html += '<span class="pill ' + openClass + '" title="Shift Schedule: ' + esc(timing.schedule) + '">⏱️ ' + esc(timing.opening_label) + '</span>';
  if (isClosed && timing.closing_label) {
    const closeClass = timing.closing_status === 'on_time' ? 'pill-ok' : (timing.closing_status === 'delayed' ? 'pill-bad' : 'pill-info');
    html += '<span class="pill ' + closeClass + '">🔒 ' + esc(timing.closing_label) + '</span>';
  }
  html += '</div>';
  return html;
}

function cashDetails(s) {
  const line = (label,value) => '<div class="insight-row"><span>'+label+'</span><b>'+money(value)+'</b></div>';
  return '<article class="card" style="padding:16px;margin:12px 0;overflow-wrap:anywhere">' +
    '<h3>'+esc(s.cashier)+' · '+esc(s.date)+'</h3>' +
    '<p>Status: <b>'+esc(s.status)+'</b></p>' +
    formatTimingTag(s.timing, !s.is_open) +
    line('Starting cash',s.opening_float) +
    line('Cash sales',s.total_sales) +
    line('Accepted collections / remittances',s.collections) +
    line('Expected cash',s.expected_cash) +
    (s.pending_collections ? line('Pending manager approval (resolve before closing)',s.pending_collections) : '') +
    (s.is_open ? '' : line('Counted cash',s.counted_cash)+line('Difference',s.variance)) +
    (s.opening_note ? '<p class="cell-sub"><b>Late opening note:</b> '+esc(s.opening_note)+'</p>' : '') +
    (s.note ? '<p class="cell-sub"><b>Closing note:</b> '+esc(s.note)+'</p>' : '') +
    '</article>';
}

function updateSidebarShiftBadge(shift) {
  const badge = document.getElementById('sidebarShiftBadge');
  if (!badge) return;
  if (!shift) {
    badge.textContent = 'Unopened';
    badge.style.background = '#94A3B8';
    badge.style.display = 'inline-block';
  } else if (shift.is_open) {
    badge.textContent = '● Open';
    badge.style.background = '#059669';
    badge.style.display = 'inline-block';
  } else {
    badge.textContent = 'Closed';
    badge.style.background = '#475569';
    badge.style.display = 'inline-block';
  }
}

function setOpeningNote(text) {
  const el = document.getElementById('cashOpeningNote');
  if (el) { el.value = text; el.focus(); }
}

function handleCountedCashChange() {
  const input = document.getElementById('cashCounted');
  const badge = document.getElementById('cashVarianceBadge');
  const label = document.getElementById('cashNoteLabel');
  if (!input || !badge || !currentCashShift) return;

  const val = input.value.trim();
  if (val === '' || !Number.isFinite(Number(val))) {
    badge.style.display = 'none';
    if (label) label.textContent = 'Explanation (required for a shortage or overage)';
    return;
  }

  const counted = Number(val);
  const expected = Number(currentCashShift.expected_cash || 0);
  const diff = Math.round((counted - expected) * 100) / 100;

  badge.style.display = 'block';
  if (Math.abs(diff) < 0.005) {
    badge.style.background = '#ECFDF5';
    badge.style.color = '#065F46';
    badge.style.border = '1px solid #A7F3D0';
    badge.textContent = '✓ Balanced — Drawer matches expected cash exactly (₱0.00 variance)';
    if (label) label.textContent = 'Explanation (optional for balanced drawer)';
  } else if (diff < -0.005) {
    badge.style.background = '#FEF2F2';
    badge.style.color = '#991B1B';
    badge.style.border = '1px solid #FECACA';
    badge.textContent = '⚠️ Shortage — Cash is short by ₱' + Math.abs(diff).toFixed(2);
    if (label) label.textContent = 'Explanation (Required for shortage: at least 5 characters) *';
  } else {
    badge.style.background = '#EFF6FF';
    badge.style.color = '#1E40AF';
    badge.style.border = '1px solid #BFDBFE';
    badge.textContent = 'ℹ️ Overage — Cash is over by ₱' + diff.toFixed(2);
    if (label) label.textContent = 'Explanation (Required for overage: at least 5 characters) *';
  }
}

async function renderCashShift() {
  const panel = document.getElementById('cashDrawerPanel');
  if (!panel || cashBusy) return;
  const version = ++cashReadVersion;
  try {
    const overview = panel.dataset.overview === '1';
    const data = await API.get(overview ? 'cash-shift/overview' : 'cash-shift');
    if (version !== cashReadVersion || cashBusy) return;
    document.getElementById('cashHistory').innerHTML = data.history.map(cashDetails).join('') || '<p>No closed drawers yet.</p>';
    if (overview) document.getElementById('cashOverview').innerHTML = data.shifts.map(cashDetails).join('') || '<p>No current or outstanding drawers.</p>';
    else {
      currentCashShift = data.shift;
      for (const [id,hide] of [['cashOpenForm',!!data.shift],['cashCloseForm',!data.shift?.is_open],['cashResultCard',!data.shift || data.shift.is_open]]) {
        const el = document.getElementById(id);
        if (el) el.classList[hide?'add':'remove']('hidden');
      }
      if (data.shift) document.getElementById(data.shift.is_open?'cashSummary':'cashResult').innerHTML = cashDetails(data.shift);

      // Check for late opening on open form
      if (!data.shift) {
        const now = new Date();
        const mins = now.getHours() * 60 + now.getMinutes();
        const isLate = mins > (8 * 60 + 30);
        const lateAlert = document.getElementById('cashOpenLateAlert');
        const noteGroup = document.getElementById('cashOpeningNoteGroup');
        if (lateAlert) lateAlert.classList[isLate ? 'remove' : 'add']('hidden');
        if (noteGroup) noteGroup.classList[isLate ? 'remove' : 'add']('hidden');
      }
      handleCountedCashChange();
    }
    updateSidebarShiftBadge(data.shift);
    document.getElementById('cashStatus').textContent = 'Updated '+new Date().toLocaleTimeString()+'. Collections are cash received, not new sales revenue.';
  } catch(error) {
    if (version !== cashReadVersion) return;
    currentCashShift = null;
    for (const id of ['cashOpenForm','cashCloseForm']) document.getElementById(id)?.classList.add('hidden');
    document.getElementById('cashStatus').textContent = 'Unable to refresh. Figures may be outdated. Use Refresh to retry.';
  }
}

async function openCashDrawer() {
  const input = document.getElementById('cashFloat');
  if (!input || input.value === '' || !Number.isFinite(Number(input.value)) || Number(input.value)<0 || Number(input.value)>1000000) return appNotice('Enter starting cash from 0 to 1,000,000.');

  const noteGroup = document.getElementById('cashOpeningNoteGroup');
  const noteEl = document.getElementById('cashOpeningNote');
  const openingNote = noteEl ? noteEl.value.trim() : '';

  if (noteGroup && !noteGroup.classList.contains('hidden') && openingNote.length < 5) {
    return appNotice('Explain the late opening in at least five characters.');
  }

  await submitCashDrawer('open',{
    opening_float: Number(input.value),
    opening_note: openingNote || null,
  },'Confirm the starting cash before today’s first transaction?','cashOpenBtn');
}

async function closeCashDrawer() {
  if (!currentCashShift?.is_open || cashBusy) return;
  if (Submissions.pending()) return appNotice('Recover the pending payment before closing.');
  const input = document.getElementById('cashCounted'), note = document.getElementById('cashNote').value.trim();
  const amount = Number(input.value);
  if (input.value === '' || !Number.isFinite(amount) || amount<0 || amount>1000000) return appNotice('Enter counted cash from 0 to 1,000,000.');
  if (currentCashShift.pending_collections>0) return appNotice('Ask a manager to resolve pending collections first.');
  if (Math.abs(amount-currentCashShift.expected_cash)>=0.01 && note.length<5) return appNotice('Explain the shortage or overage in at least five characters.');
  await submitCashDrawer('close',{shift_id:currentCashShift.id,expected_cash:currentCashShift.expected_cash,counted_cash:amount,note:note||null},'Close this drawer? New sales and collections will be blocked today.','cashCloseBtn');
}

async function submitCashDrawer(action,body,message,buttonId) {
  if (cashBusy) return;
  cashBusy = true; ++cashReadVersion;
  const button = document.getElementById(buttonId); if (button) button.disabled = true;
  try {
    if (!await appConfirm(message,action==='open'?'Open drawer':'Close drawer')) return;
    await API.post('cash-shift/'+action,body);
    appNotice(action==='open'?'Starting cash recorded.':'Drawer closed and count saved.');
  } catch(error) { appNotice((error.message||'Request could not be confirmed.')+' Refresh and check the saved drawer before retrying.'); }
  finally { cashBusy=false; if (button) button.disabled=false; await renderCashShift(); }
}
