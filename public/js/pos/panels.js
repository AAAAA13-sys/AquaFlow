// AquaFlow CASHIER terminal - sidebar pages.
//
//   Orders in Progress : active orders and confirmed deliveries
//   Sales & Transactions: the day's receipts, filters and CSV export
//
// Both pages share the same API as the terminal.

// ---------- Orders in Progress ----------

let queueStatus = 'active', queuePage = 1, queueRequest = 0, queueSearchTimer;
function renderQueuePage() {
 const list=document.getElementById('queue'); if(!list) return;
 const rows=DB.queue || [];
 list.innerHTML=rows.map(q=>'<article class="queue-card"><div class="flex-between"><b>'+esc(q.no)+'</b><span class="pill '+(q.completed ? 'pill-ok' : 'pill-info')+'">'+(q.completed ? 'Delivered' : 'Active order')+'</span></div><h3>'+esc(q.cust)+'</h3><p>'+esc(q.items)+'</p><p class="queue-meta">'+esc(q.orderType)+' · '+(q.completed ? (q.delivered_at ? 'Delivered '+esc(new Date(q.delivered_at).toLocaleString()) : 'Legacy completed order · handover time unavailable') : Number(q.mins)+' min since order')+'</p>'+(q.completed ? '' : '<button class="btn btn-primary" onclick="deliverQueueOrder('+Number(q.id)+',this)">Mark delivered</button>')+'</article>').join('') || '<div class="cashier-empty"><b>No '+(queueStatus==='active' ? 'active orders' : 'delivered orders')+'</b><p>'+ (document.getElementById('queueSearch').value ? 'Try another receipt or customer name.' : 'Orders will appear here when available.')+'</p></div>';
}
async function selectQueueStatus(status) {
 queueStatus=status; queuePage=1;
 for(const [id,value] of [['activeOrdersTab','active'],['deliveredOrdersTab','delivered']]) {
  const button=document.getElementById(id), selected=status===value;
  button.classList.toggle('btn-primary',selected); button.classList.toggle('btn-ghost',!selected); button.setAttribute('aria-pressed',String(selected));
 }
 document.getElementById('queueHeading').textContent=status==='active' ? 'Active orders' : 'Delivered';
 await refreshQueuePage();
}
function searchQueue() { clearTimeout(queueSearchTimer); queueRequest++; queuePage=1; queueSearchTimer=setTimeout(refreshQueuePage,250); }
async function deliverQueueOrder(id,button) {
 if(!confirm('Confirm that the customer has received this order?')) return;
 button.disabled=true;
 try { await API.post('queue/'+id+'/deliver'); await refreshQueuePage(); }
 catch(error) { alert(error.message || 'Unable to confirm delivery.'); }
 finally { button.disabled=false; }
}
function changeQueuePage(delta) { queuePage=Math.max(1,queuePage+delta); refreshQueuePage(); }

// ---------- Sales & Transactions ----------

function historyFilters() {
  const type = document.getElementById('hType');
  const pay = document.getElementById('hPay');

  return {
    from: document.getElementById('hFrom')?.value || '',
    to: document.getElementById('hTo')?.value || '',
    type: (type && type.value) || 'All',
    pay: (pay && pay.value) || 'All',
  };
}

async function historyRows() {
  const filters = historyFilters();
  const rows = [];
  let page = 1, data;
  do {
    data = await API.transactions({...filters, paginated:1, page:page++});
    rows.push(...(data.transactions || []));
  } while (data.has_more);
  return rows;
}

let cashierHistoryRows = [], cashierHistoryPage = 1, cashierHistoryKey = '', cashierHistoryRequest = 0;
// Placeholder rows shown while the first page is in flight. The table used to
// render headers with a completely empty body, which reads as "no sales today"
// rather than "still loading" - easy to mistake for a real business condition.
function showHistorySkeleton(rows = 6) {
  const body = document.getElementById('hBody');
  if (!body) return;
  body.innerHTML = Array.from({ length: rows }, () =>
    '<tr aria-hidden="true">' + Array.from({ length: 11 }, () => '<td><span class="af-skeleton-row"></span></td>').join('') + '</tr>'
  ).join('');
  body.setAttribute('aria-busy', 'true');
}

function clearHistorySkeleton() {
  const body = document.getElementById('hBody');
  if (body) body.removeAttribute('aria-busy');
}

async function renderHistoryPage() {
  const body = document.getElementById('hBody');
  if (!body) return;

  // Only skeletonise the very first paint; a 30s background refresh should not
  // flash the table away and back under the user.
  if (!body.dataset.loaded) showHistorySkeleton();
  else body.setAttribute('aria-busy', 'true');

  const request = ++cashierHistoryRequest;
  const key = JSON.stringify({...historyFilters(), search:document.getElementById('hBodySearch')?.value || '', order:document.getElementById('hBodyOrder')?.value || 'newest'});
  let fetched;
  try {
    fetched = await historyRows();
  } catch (error) {
    if (request !== cashierHistoryRequest) return;
    clearHistorySkeleton();
    cashierHistoryRows = [];
    for (const id of ['hSum', 'historyPages']) {
      const element = document.getElementById(id);
      if (element) element.innerHTML = '';
    }
    body.innerHTML = '<tr><td colspan="11" class="cashier-empty"><b>Could not load transactions</b><p>' +
      esc(error.message || 'Please try again.') + '</p><button type="button" class="btn btn-ghost btn-sm" onclick="renderHistoryPage()">Retry</button></td></tr>';
    return;
  }
  if (request !== cashierHistoryRequest) return;

  body.dataset.loaded = '1';
  clearHistorySkeleton();
  cashierHistoryRows = filterTableRows(fetched, 'hBody');
  if (key !== cashierHistoryKey) cashierHistoryPage = 1;
  cashierHistoryKey = key;
  renderHistoryRows();

  // A debt payment is a real recorded transaction, so it stays in the ledger,
  // but it is not a sale. The previous summary counted them inside "Sales
  // revenue / excludes debt payments", so the headline figure and the visible
  // rows disagreed. Payments are now broken out and labelled separately.
  const rows = cashierHistoryRows;
  const sales = rows.filter(t => t.type !== 'Debt Payment');
  const payments = rows.filter(t => t.type === 'Debt Payment');
  const revenue = sales.reduce((sum, t) => sum + Number(t.total), 0);
  const collected = payments.reduce((sum, t) => sum + Number(t.total), 0);
  const cash = sales.filter(t => t.pay === 'Cash').reduce((sum, t) => sum + Number(t.total), 0);

  const summary = document.getElementById('hSum');
  if (summary) {
    summary.innerHTML =
      '<article><span>Sales revenue</span><strong>' + money(revenue) + '</strong><small>' +
        sales.length + (sales.length === 1 ? ' sale' : ' sales') + '</small></article>' +
      '<article><span>Debt payments</span><strong>' + money(collected) + '</strong><small>' +
        (payments.length ? payments.length + (payments.length === 1 ? ' payment recorded' : ' payments recorded') : 'None this period') +
        '</small></article>' +
      '<article><span>Cash from sales</span><strong>' + money(cash) + '</strong><small>Cash and account split</small></article>' +
      '<article><span>Records</span><strong>' + rows.length + '</strong><small>Matching your filters</small></article>';
  }

  markScrollableTables('ledgerScrollNote');
}

function renderHistoryRows() {
  const rows = cashierHistoryRows, pages = Math.max(1, Math.ceil(rows.length / 20));
  cashierHistoryPage = Math.max(1, Math.min(cashierHistoryPage, pages));
  const start = (cashierHistoryPage - 1) * 20;
  document.getElementById('hBody').innerHTML = rows.slice(start, start + 20).map((t, i) => {
    // Debt payments are tinted + italicised so the eye can separate them from
    // sales at a glance, matching the separate summary card above.
    const isPayment = t.type === 'Debt Payment';
    return '<tr' + (isPayment ? ' class="is-payment-row"' : '') + '>' +
      '<td><b>' + esc(t.no) + '</b>' + (isPayment ? '<span class="cell-sub">Debt payment</span>' : '') + '</td>' +
      '<td>' + esc(t.date || '—') + '</td><td>' + esc(t.t) + '</td>' +
      '<td>' + esc(t.cust || 'Walk-in Guest') + '</td><td>' + esc(t.type) + '</td><td>' + esc(t.gal) + '</td>' +
      '<td class="num">' + money(t.vatable || 0) + '</td><td class="num">' + money(t.vat || 0) + '</td>' +
      '<td class="num col-total"><b>' + money(t.total) + '</b></td><td>' + esc(t.pay) + '</td>' +
      '<td><button class="btn btn-ghost btn-sm" onclick="viewHistoryReceipt(' + (start + i) + ')">View receipt</button></td></tr>';
  }).join('') || '<tr><td colspan="11" class="cashier-empty"><b>No transactions found</b><p>Try another period or clear your search.</p></td></tr>';
  const nav = document.getElementById('historyPages');
  if (nav) nav.innerHTML = '<span>' + (rows.length ? start + 1 : 0) + '–' + Math.min(rows.length, start + 20) + ' of ' + rows.length + ' records</span><div><button class="btn btn-ghost btn-sm" onclick="changeHistoryPage(-1)"' + (cashierHistoryPage === 1 ? ' disabled' : '') + '>Previous</button><span>Page ' + cashierHistoryPage + ' of ' + pages + '</span><button class="btn btn-ghost btn-sm" onclick="changeHistoryPage(1)"' + (cashierHistoryPage === pages ? ' disabled' : '') + '>Next</button></div>';
}
function changeHistoryPage(delta) { cashierHistoryPage += delta; renderHistoryRows(); }
function viewHistoryReceipt(index) { const tx = cashierHistoryRows[index]; if (tx) displayTransactionReceipt(tx, true); }

async function exportHistoryCsv() {
  let rows;
  try {
    rows = filterTableRows(await historyRows(), 'hBody');
  } catch (error) {
    alert('Could not export transactions: ' + (error.message || 'Please try again.'));
    return;
  }

  const csv = 'OR,Date,Time,Customer,Type,Gallons,Vatable,VAT,Total,Pay,Cashier\n' +
    rows.map(t => [t.no, t.date || '', t.t, t.cust, t.type, t.gal, t.vatable || 0, t.vat || 0, t.total, t.pay, t.by].map(value => { const cell = String(value ?? ''); return /[",\r\n]/.test(cell) ? '"' + cell.replace(/"/g, '""') + '"' : cell; }).join(',')).join('\n');

  downloadCSV(csv, 'cashier-sales.csv');
}

async function refreshQueuePage() {
 if(!document.getElementById('queue')) return;
 const request=++queueRequest;
 try {
  const response=await API.getWithQuery('queue',{status:queueStatus,search:document.getElementById('queueSearch').value,page:queuePage});
  if(request!==queueRequest) return;
  DB.queue=response.queue || []; renderQueuePage();
  document.getElementById('queueCount').textContent=response.total+' orders';
  document.getElementById('queuePages').innerHTML='<button class="btn btn-ghost btn-sm" onclick="changeQueuePage(-1)"'+(queuePage===1 ? ' disabled' : '')+'>Previous</button><span>Page '+queuePage+'</span><button class="btn btn-ghost btn-sm" onclick="changeQueuePage(1)"'+(!response.has_more ? ' disabled' : '')+'>Next</button>';
 } catch(error) { if(request===queueRequest) document.getElementById('queue').innerHTML='<p role="alert">'+esc(error.message || 'Unable to load orders.')+'</p>'; }
}
