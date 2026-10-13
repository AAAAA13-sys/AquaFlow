// End-of-day cash reconciliation for the admin portal.
//
// Read-only against the sales record: it compares counted cash with what the
// recorded cash sales say should be in the drawer, and reports the difference.

function cashStatusBadge(shift) {
  const tone = shift.status === 'balanced' ? 'pill-ok' : shift.status === 'open' ? 'pill-warn' : 'pill-bad';
  const label = { balanced: 'Balanced', short: 'Short', over: 'Over', open: 'Open' }[shift.status] || shift.status;
  return '<span class="pill ' + tone + '">' + label + '</span>';
}

function cashVariance(shift) {
  if (shift.variance === null || shift.variance === undefined) return '-';
  const value = Number(shift.variance);
  if (Math.abs(value) <= 0.005) return money(0);
  // Sign belongs outside the currency symbol: -P40.00, not P-40.00.
  return (value < 0 ? '-' : '+') + money(Math.abs(value));
}

function renderCashHistory(history) {
  const body = document.getElementById('cashHistoryBody');
  if (!body) return;
  if (!history || !history.length) {
    body.innerHTML = '<tr><td colspan="8" class="overview-empty">No closed drawers yet. Close one above to see it here.</td></tr>';
    return;
  }
  body.innerHTML = history.map(s =>
    '<tr>' +
      '<td>' + esc(s.date) + '</td>' +
      '<td>' + esc(s.cashier) + '</td>' +
      '<td class="num">' + money(s.opening_float) + '</td>' +
      '<td class="num">' + money(s.total_sales) + '</td>' +
      '<td class="num">' + money(s.expected_cash) + '</td>' +
      '<td class="num">' + (s.counted_cash === null ? '-' : money(s.counted_cash)) + '</td>' +
      '<td class="num">' + cashVariance(s) + '</td>' +
      '<td>' + cashStatusBadge(s) + '</td>' +
    '</tr>'
  ).join('');
}

function renderCashSummary(shift) {
  const el = document.getElementById('cashSummary');
  if (!el || !shift) return;
  const row = (label, value) => '<div class="insight-row"><span>' + label + '</span><b>' + value + '</b></div>';
  el.innerHTML =
    row('Starting float', money(shift.opening_float)) +
    row('Cash sales so far', money(shift.total_sales) + ' (' + shift.cash_sales_count + ')') +
    row('Expected in drawer', '<strong>' + money(shift.expected_cash) + '</strong>');
}

function renderCashResult(shift) {
  const card = document.getElementById('cashResultCard');
  const el = document.getElementById('cashResult');
  if (!card || !el) return;

  const headline = shift.status === 'balanced'
    ? 'The drawer balances.'
    : shift.status === 'short'
      ? 'The drawer is ' + money(Math.abs(shift.variance)) + ' short.'
      : 'The drawer is ' + money(shift.variance) + ' over.';

  const explain = shift.status === 'balanced'
    ? 'Counted cash matches the recorded sales exactly.'
    : shift.status === 'short'
      ? 'Counted cash is less than expected. Check the float and any cash paid out.'
      : 'Counted cash is more than expected. Check for change given incorrectly or a mis-keyed amount.';

  el.innerHTML =
    '<p><strong>' + esc(headline) + '</strong></p>' +
    '<p class="settings-help">' + esc(explain) + '</p>' +
    '<dl class="cash-summary">' +
      '<div class="insight-row"><span>Starting float</span><b>' + money(shift.opening_float) + '</b></div>' +
      '<div class="insight-row"><span>Cash sales</span><b>' + money(shift.total_sales) + '</b></div>' +
      '<div class="insight-row"><span>Expected</span><b>' + money(shift.expected_cash) + '</b></div>' +
      '<div class="insight-row"><span>Counted</span><b>' + money(shift.counted_cash) + '</b></div>' +
      '<div class="insight-row"><span>Difference</span><b>' + cashVariance(shift) + '</b></div>' +
    '</dl>' +
    (shift.note ? '<p class="settings-help">Note: ' + esc(shift.note) + '</p>' : '');

  card.classList.remove('hidden');
}

async function renderCashShift() {
  const openForm = document.getElementById('cashOpenForm');
  const closeForm = document.getElementById('cashCloseForm');
  const resultCard = document.getElementById('cashResultCard');

  try {
    const data = await API.cashShift();

    renderCashHistory(data.history);

    if (data.shift && data.shift.is_open) {
      openForm?.classList.add('hidden');
      closeForm?.classList.remove('hidden');
      renderCashSummary(data.shift);
    } else {
      closeForm?.classList.add('hidden');
      openForm?.classList.remove('hidden');
      resultCard?.classList.add('hidden');
      if (data.shift) renderCashResult(data.shift);
    }
  } catch (error) {
    appNotice(error.message || 'Could not load the cash reconciliation.');
  }
}

async function openCashDrawer() {
  const button = document.getElementById('cashOpenBtn');
  const input = document.getElementById('cashFloat');
  if (!button || !input) return;
  if (input.value === '' || Number(input.value) < 0) {
    appNotice('Enter the starting float.');
    input.focus();
    return;
  }

  button.disabled = true;
  try {
    await API.openCashShift(Number(input.value));
    appNotice('Drawer opened. Count the cash when you close.');
    await renderCashShift();
  } catch (error) {
    appNotice(error.message || 'Could not open the drawer.');
  } finally { button.disabled = false; }
}

async function closeCashDrawer() {
  const button = document.getElementById('cashCloseBtn');
  const counted = document.getElementById('cashCounted');
  const note = document.getElementById('cashNote');
  if (!button || !counted) return;

  if (counted.value === '' || Number(counted.value) < 0) {
    appNotice('Enter the cash you counted.');
    counted.focus();
    return;
  }

  button.disabled = true;
  try {
    const result = await API.closeCashShift(Number(counted.value), note?.value || null);
    if (note) note.value = '';
    counted.value = '';
    await renderCashShift();
    renderCashResult(result.shift);
  } catch (error) {
    appNotice(error.message || 'Could not close the drawer.');
  } finally { button.disabled = false; }
}