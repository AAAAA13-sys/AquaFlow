// Shared cashier reconciliation and read-only manager totals.
let currentCashShift = null, cashBusy = false, cashReadVersion = 0;
function cashDetails(s) {
  const line = (label,value) => '<div class="insight-row"><span>'+label+'</span><b>'+money(value)+'</b></div>';
  return '<article class="card" style="padding:16px;margin:12px 0;overflow-wrap:anywhere"><h3>'+esc(s.cashier)+' · '+esc(s.date)+'</h3><p>'+esc(s.status)+'</p>'+line('Starting cash',s.opening_float)+line('Cash sales',s.total_sales)+line('Accepted collections / remittances',s.collections)+line('Expected cash',s.expected_cash)+(s.pending_collections ? line('Pending manager approval (resolve before closing)',s.pending_collections) : '')+(s.is_open ? '' : line('Counted cash',s.counted_cash)+line('Difference',s.variance))+(s.note ? '<p>'+esc(s.note)+'</p>' : '')+'</article>';
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
      for (const [id,hide] of [['cashOpenForm',!!data.shift],['cashCloseForm',!data.shift?.is_open],['cashResultCard',!data.shift || data.shift.is_open]]) document.getElementById(id).classList[hide?'add':'remove']('hidden');
      if (data.shift) document.getElementById(data.shift.is_open?'cashSummary':'cashResult').innerHTML = cashDetails(data.shift);
    }
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
  await submitCashDrawer('open',{opening_float:Number(input.value)},'Confirm the starting cash before today’s first transaction?','cashOpenBtn');
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
  const button = document.getElementById(buttonId); button.disabled = true;
  try {
    if (!await appConfirm(message,action==='open'?'Open drawer':'Close drawer')) return;
    await API.post('cash-shift/'+action,body);
    appNotice(action==='open'?'Starting cash recorded.':'Drawer closed and count saved.');
  } catch(error) { appNotice((error.message||'Request could not be confirmed.')+' Refresh and check the saved drawer before retrying.'); }
  finally { cashBusy=false; button.disabled=false; await renderCashShift(); }
}
