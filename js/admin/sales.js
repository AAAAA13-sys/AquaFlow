// AquaFlow ADMIN portal - Sales & POS logs
// Logic copied from the original AquaFlow prototype, re-rendered
// with the current semantic design-system classes.

function filteredSales() {
  const dateEl = document.getElementById('fDate');
  const channelEl = document.getElementById('fChannel');
  const payEl = document.getElementById('fPay');
  const days = +((dateEl && dateEl.value) || 0);
  const ch = (channelEl && channelEl.value) || 'All';
  const pay = (payEl && payEl.value) || 'All';
  const cutoff = new Date();
  cutoff.setDate(cutoff.getDate() - (days === 0 ? 0 : days - 1));
  const cutoffStr = cutoff.toISOString().split('T')[0];
  return DB.transactions.filter(t => {
    const d = t.date || new Date().toISOString().split('T')[0];
    if (d < cutoffStr) return false;
    if (ch !== 'All' && t.type !== ch) return false;
    if (pay !== 'All' && t.pay !== pay) return false;
    return true;
  });
}

function renderSalesTable() {
  const rows = filteredSales();
  const body = document.getElementById('salesBody');
  if (body) {
    body.innerHTML = rows.map(t =>
      '<tr><td><b>' + t.no + '</b></td><td>' + t.t + '</td><td>' + t.cust + '</td><td>' + t.type + '</td><td>' + t.gal + '</td><td class="num">P' + t.total + '</td><td>' + t.pay + '</td><td class="cell-sub">' + t.by + '</td></tr>'
    ).join('');
  }
  const gross = rows.reduce((a, t) => a + t.total, 0);
  const summary = document.getElementById('salesSum');
  if (summary) {
    summary.innerHTML =
      '<span class="pill pill-info">Gross P' + gross.toLocaleString() + '</span>' +
      '<span class="pill pill-info">Volume ' + rows.length + ' orders</span>' +
      '<span class="pill pill-info">AOV P' + Math.round(gross / Math.max(1, rows.length)).toLocaleString() + '</span>';
  }
}

function exportSalesCSV() {
  const csv = 'OR,Date,Time,Customer,Type,Gallons,Total,Pay,Cashier\n' + filteredSales().map(t => [t.no, t.date || '', t.t, t.cust, t.type, t.gal, t.total, t.pay, t.by].join(',')).join('\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  a.download = 'sales-audit.csv';
  a.click();
}
