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
    const data = await API.transactions({ days: days, type: type, pay: pay });
    return Array.isArray(data.transactions) ? data.transactions : [];
  } catch (error) {
    console.warn('AquaFlow: sales query failed, using cached data. ' + error.message);
    return filteredSales();
  }
}


// Sales Table Rendering
async function renderSalesTable() {
  const rows = await fetchSalesRows();

  const body = document.getElementById('salesBody');
  if (body) {
    body.innerHTML = rows.map(t =>
      '<tr><td><b>' + t.no + '</b></td><td>' + t.t + '</td><td>' + t.cust + '</td><td>' + t.type + '</td><td>' + t.gal + '</td><td class="num">₱' + Number(t.total).toLocaleString() + '</td><td>' + t.pay + '</td><td class="cell-sub">' + t.by + '</td></tr>'
    ).join('');
  }

  const gross = rows.reduce((total, t) => total + Number(t.total), 0);
  const summary = document.getElementById('salesSum');
  if (summary) {
    summary.innerHTML =
      '<span class="pill pill-info">Gross ₱' + gross.toLocaleString() + '</span>' +
      '<span class="pill pill-info">Volume ' + rows.length + ' orders</span>' +
      '<span class="pill pill-info">AOV ₱' + Math.round(gross / Math.max(1, rows.length)).toLocaleString() + '</span>';
  }
}


// Audit Export
async function exportSalesCSV() {
  const rows = await fetchSalesRows();
  const csv = 'OR,Date,Time,Customer,Type,Gallons,Total,Pay,Cashier\n' +
    rows.map(t => [t.no, t.date || '', t.t, t.cust, t.type, t.gal, t.total, t.pay, t.by].join(',')).join('\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  a.download = 'sales-audit.csv';
  a.click();
}
