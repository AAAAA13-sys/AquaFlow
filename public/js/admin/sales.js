// Sales Filters & Local Cache
function salesFilters() {
  const dateEl = document.getElementById('fDate');
  const channelEl = document.getElementById('fChannel');
  const payEl = document.getElementById('fPay');
  return {
    days: +((dateEl && dateEl.value) || 0),
    type: (channelEl && channelEl.value) || 'All',
    pay: (payEl && payEl.value) || 'All'
  };
}

function filteredSales() {
  const { days, type, pay } = salesFilters();
  const cutoff = new Date();
  cutoff.setDate(cutoff.getDate() - (days === 0 ? 0 : days - 1));
  const cutoffStr = cutoff.toISOString().split('T')[0];

  return (DB.transactions || []).filter(t => {
    const d = t.date || new Date().toISOString().split('T')[0];
    if (d < cutoffStr) return false;
    if (type !== 'All' && t.type !== type) return false;
    if (pay !== 'All' && t.pay !== pay) return false;
    return true;
  });
}


// Data Fetching
async function fetchSalesRows() {
  const { days, type, pay } = salesFilters();
  try {
    const rows = [];
    let page = 1, data;
    do {
      data = await API.transactions({ days, type, pay, paginated: 1, page: page++ });
      if (Array.isArray(data.transactions)) rows.push(...data.transactions);
    } while (data.has_more);
    return rows;
  } catch (error) {
    console.warn('AquaFlow: sales query failed, using cached data. ' + error.message);
    const rows = filteredSales();
    rows.fromCache = true;
    return rows;
  }
}


function filterSalesCashier(rows) {
  const cashier = document.getElementById('fCashier')?.value || '';
  return cashier ? rows.filter(t => t.by === cashier) : rows;
}

// Sales Table Rendering
let salesPage = 1;
let salesRows = [];
let salesRequest = 0;
let salesFilterKey = null;
const SALES_PAGE_SIZE = 20;

async function renderSalesTable() {
  const request = ++salesRequest;
  const filterKey = JSON.stringify({ ...salesFilters(), cashier: document.getElementById('fCashier')?.value || '', search: document.getElementById('salesBodySearch')?.value || '', order: document.getElementById('salesBodyOrder')?.value || 'newest' });
  const fetched = await fetchSalesRows();
  if (request !== salesRequest) return;
  const cashierField = document.getElementById('fCashier');
  if (cashierField) {
    const selected = cashierField.value;
    const names = [...new Set([...fetched.map(t => t.by), selected].filter(Boolean))].sort((a, b) => a.localeCompare(b));
    cashierField.innerHTML = '<option value="">All cashiers</option>' + names.map(name => '<option value="' + esc(name) + '">' + esc(name) + '</option>').join('');
    cashierField.value = selected;
  }
  salesRows = filterTableRows(filterSalesCashier(fetched), 'salesBody');
  if (filterKey !== salesFilterKey) salesPage = 1;
  salesFilterKey = filterKey;
  const results = document.getElementById('salesResults');
  if (results) results.textContent = salesRows.length.toLocaleString() + ' matching records' + (fetched.fromCache ? ' · Cached data — connection unavailable' : '');
  renderSalesPage();

  const sales = salesRows.filter(t => t.type !== 'Debt Payment');
  const total = rows => rows.reduce((sum, t) => sum + Number(t.total || 0), 0);
  const summary = document.getElementById('salesSum');
  if (summary) {
    const card = (label, value, note) => '<article class="sales-summary-card"><p>' + label + '</p><strong>' + value + '</strong><span>' + note + '</span></article>';
    summary.innerHTML = card('Sales revenue', money(total(sales)), 'Excludes debt payments') +
      card('Cash recorded', money(total(salesRows.filter(t => t.pay === 'Cash'))), 'Includes cash debt payments') +
      card('Account sales', money(total(sales.filter(t => t.pay === 'Account'))), 'Sales charged to customer accounts') +
      card('Transactions', salesRows.length.toLocaleString(), 'Records matching your filters');
  }
}

function renderSalesPage() {
  const body = document.getElementById('salesBody');
  const pages = Math.max(1, Math.ceil(salesRows.length / SALES_PAGE_SIZE));
  salesPage = Math.min(pages, Math.max(1, salesPage));
  const start = (salesPage - 1) * SALES_PAGE_SIZE;
  if (body) {
    body.innerHTML = salesRows.slice(start, start + SALES_PAGE_SIZE).map(t =>
      '<tr><td><b>' + esc(t.no) + '</b></td><td><span>' + esc(t.date || '—') + '</span><small class="cell-sub">' + esc(t.t) + '</small></td><td><b>' + esc(t.cust || 'Walk-in Guest') + '</b></td><td><span class="sales-type ' + (t.type === 'Delivery' ? 'sales-type--delivery' : '') + '">' + esc(t.type) + '</span></td><td>' + esc(t.gal || '—') + '</td><td class="num sales-amount">' + money(Number(t.total)) + '</td><td><span class="sales-payment">' + esc(t.pay) + '</span>' + (t.pay === 'Account' && t.payment_status ? '<small class="cell-sub">' + (t.payment_status === 'paid' ? 'Paid' : 'Unpaid') + '</small>' : '') + '</td><td class="sales-cashier">' + esc(t.by) + '</td></tr>'
    ).join('');
    if (!salesRows.length) body.innerHTML = '<tr><td colspan="8" class="sales-empty"><b>No transactions found</b><span>Try another period or clear your search and filters.</span></td></tr>';
  }
  const pagination = document.getElementById('salesPagination');
  if (pagination) pagination.innerHTML = '<span>' + (salesRows.length ? start + 1 : 0) + '–' + Math.min(start + SALES_PAGE_SIZE, salesRows.length) + ' of ' + salesRows.length + ' records</span><div><button type="button" class="btn btn-secondary btn-sm" onclick="changeSalesPage(-1)"' + (salesPage === 1 ? ' disabled' : '') + '>Previous</button><span>Page ' + salesPage + ' of ' + pages + '</span><button type="button" class="btn btn-secondary btn-sm" onclick="changeSalesPage(1)"' + (salesPage === pages ? ' disabled' : '') + '>Next</button></div>';
}

function changeSalesPage(delta) { salesPage += delta; renderSalesPage(); }

function resetSalesFilters() {
  for (const [id, value] of Object.entries({ fDate: '0', fChannel: 'All', fPay: 'All', fCashier: '', salesBodySearch: '', salesBodyOrder: 'newest' })) {
    const field = document.getElementById(id);
    if (field) field.value = value;
  }
  renderSalesTable();
}


// Audit Export
async function exportSalesCSV() {
  const rows = filterTableRows(filterSalesCashier(await fetchSalesRows()), 'salesBody');
  const csv = 'OR,Date,Time,Customer,Type,Gallons,Total,Pay,Cashier\n' +
    rows.map(t => [t.no, t.date || '', t.t, t.cust, t.type, t.gal, t.total, t.pay, t.by].map(value => { const cell = String(value ?? ''); return /[",\r\n]/.test(cell) ? '"' + cell.replace(/"/g, '""') + '"' : cell; }).join(',')).join('\n');
  downloadCSV(csv, 'sales-audit.csv');
}
