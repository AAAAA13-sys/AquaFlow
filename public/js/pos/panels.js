// AquaFlow CASHIER terminal - sidebar pages.
//
//   Orders in Progress : the live production queue with stage advancement
//   Sales & Transactions: the day's receipts, filters and CSV export
//
// Both pages share the same API as the terminal.

// ---------- Orders in Progress ----------

function renderQueuePage() {
  const list = document.getElementById('queue');
  if (!list) return;

  const queue = DB.queue || [];

  const count = document.getElementById('queueCount');
  if (count) count.textContent = queue.length + (queue.length === 1 ? ' order' : ' orders');

  list.innerHTML = queue.length
    ? queue.map(q => {
        const stage = Number(q.stage) || 0;

        return '<div class="queue-card">' +
          '<div class="flex-between"><b>' + q.no + '</b>' +
          '<span class="queue-meta">' + q.cust + ' | ' + q.mins + ' min | ' + q.items + '</span></div>' +
          '<div class="queue-stages">' + STAGES.map((s, i) =>
            '<span class="pill ' + (i < stage ? 'pill-ok' : i === stage ? 'pill-info' : 'pill-neutral') + '">' + s + '</span>'
          ).join('') + '</div>' +
          (q.id
            ? '<button class="btn btn-ghost btn-sm order-type-selector" onclick="advanceQueue(' + q.id + ')">' +
              (stage >= STAGES.length - 1 ? 'Mark Sealed' : 'Advance to ' + STAGES[stage + 1]) + '</button>'
            : '') +
          '</div>';
      }).join('')
    : '<p class="cust-option-sub">No orders in progress right now.</p>';

  const guide = document.getElementById('stageGuide');
  if (guide) {
    guide.innerHTML = STAGES.map((s, i) =>
      '<div class="custody-row"><span>' + (i + 1) + '. ' + s + '</span><b>' +
      (i === 0 ? 'Bottles arrive from the customer' :
        i === 1 ? 'Rinse and sanitise' :
          i === 2 ? 'Fill the jugs' : 'Cap, seal and hand over') +
      '</b></div>'
    ).join('');
  }
}

// ---------- Sales & Transactions ----------

function historyFilters() {
  const when = document.getElementById('hDate');
  const type = document.getElementById('hType');
  const pay = document.getElementById('hPay');

  return {
    days: +((when && when.value) || 0),
    type: (type && type.value) || 'All',
    pay: (pay && pay.value) || 'All',
  };
}

async function historyRows() {
  const { days, type, pay } = historyFilters();

  try {
    const data = await API.transactions({ days: days, type: type, pay: pay });
    return Array.isArray(data.transactions) ? data.transactions : [];
  } catch (error) {
    console.warn('AquaFlow: history query failed, using cached data. ' + error.message);

    const cutoff = new Date();
    cutoff.setDate(cutoff.getDate() - (days === 0 ? 0 : days - 1));
    const cutoffStr = cutoff.toISOString().split('T')[0];

    return (DB.transactions || []).filter(t =>
      (t.date || '') >= cutoffStr &&
      (type === 'All' || t.type === type) &&
      (pay === 'All' || t.pay === pay)
    );
  }
}

async function renderHistoryPage() {
  const body = document.getElementById('hBody');
  if (!body) return;

  const rows = await historyRows();
  const money = n => '₱' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  body.innerHTML = rows.length
    ? rows.map(t =>
        '<tr>' +
        '<td><b>' + t.no + '</b></td>' +
        '<td>' + (t.date || '-') + '</td>' +
        '<td>' + t.t + '</td>' +
        '<td>' + t.cust + '</td>' +
        '<td>' + t.type + '</td>' +
        '<td>' + t.gal + '</td>' +
        '<td class="num">' + money(t.vatable || 0) + '</td>' +
        '<td class="num">' + money(t.vat || 0) + '</td>' +
        '<td class="num"><b>' + money(t.total) + '</b></td>' +
        '<td>' + t.pay + '</td>' +
        '<td><button class="btn btn-ghost btn-sm" onclick="reprint(\'' + t.no + '\')">View</button></td>' +
        '</tr>'
      ).join('')
    : '<tr><td colspan="11" class="cust-option-sub">No transactions for this filter.</td></tr>';

  const gross = rows.reduce((sum, t) => sum + Number(t.total), 0);
  const vat = rows.reduce((sum, t) => sum + Number(t.vat || 0), 0);
  const summary = document.getElementById('hSum');

  if (summary) {
    summary.innerHTML =
      '<span class="pill pill-info">Gross ' + money(gross) + '</span>' +
      '<span class="pill pill-info">VAT ' + money(vat) + '</span>' +
      '<span class="pill pill-info">' + rows.length + ' transactions</span>';
  }
}

async function exportHistoryCsv() {
  const rows = await historyRows();

  const csv = 'OR,Date,Time,Customer,Type,Gallons,Vatable,VAT,Total,Pay,Cashier\n' +
    rows.map(t => [t.no, t.date || '', t.t, t.cust, t.type, t.gal, t.vatable || 0, t.vat || 0, t.total, t.pay, t.by].join(',')).join('\n');

  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  a.download = 'cashier-sales.csv';
  a.click();
}
