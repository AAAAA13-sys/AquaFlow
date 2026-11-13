// AquaFlow - Reports page (admin/reports).
//
// Shows one period's sales, collections, cost of supplies, purchases,
// expenses and the two profit figures from GET api/reports/summary. The
// server computes every amount (decimal strings, 2 places); this file only
// formats, charts and exports them. Period presets are anchored on the
// station's "today" as reported by the server, not the browser clock.
//
// Dynamic content is built with DOM nodes and textContent, never innerHTML.

const REP = (() => {
  const MAX_DAYS = 366;
  const CATEGORY_LABELS = {
    utilities: 'Utilities', rent: 'Rent', salaries: 'Salaries & wages', supplies: 'Supplies',
    maintenance: 'Maintenance', transport: 'Transport & fuel', permits: 'Permits & fees', other: 'Other'
  };
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  const state = {
    data: null,        // last successful summary for `applied`
    applied: null,     // {from, to} the data belongs to
    preset: 'month',
    today: null,       // station today (YYYY-MM-DD), learned from the server
    loading: false,
    error: null,
    generatedAt: null,
    seq: 0,
    chart: null
  };

  const $ = id => document.getElementById(id);

  function h(tag, props, ...kids) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(props || {})) {
      if (value === null || value === undefined || value === false) continue;
      if (key === 'class') node.className = value;
      else if (key === 'text') node.textContent = value;
      else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2), value);
      else if (value === true) node.setAttribute(key, '');
      else node.setAttribute(key, String(value));
    }
    kids.flat().forEach(kid => {
      if (kid === null || kid === undefined || kid === false) return;
      node.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
    });
    return node;
  }

  // ---- dates --------------------------------------------------------------

  function parseYmd(ymd) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd || '');
    return m ? new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]))) : null;
  }

  function ymd(date) {
    return date.getUTCFullYear() + '-' + String(date.getUTCMonth() + 1).padStart(2, '0') + '-' + String(date.getUTCDate()).padStart(2, '0');
  }

  function fmtDate(value) {
    const d = parseYmd(value);
    return d ? d.getUTCDate() + ' ' + MONTHS[d.getUTCMonth()] + ' ' + d.getUTCFullYear() : (value || '—');
  }

  function fmtPeriod(from, to) {
    return from === to ? fmtDate(from) : fmtDate(from) + ' – ' + fmtDate(to);
  }

  function daysBetween(from, to) {
    return Math.round((parseYmd(to) - parseYmd(from)) / 86400000) + 1;
  }

  /** Inclusive range for a preset, anchored on the station's today. Weeks start on Monday. */
  function presetRange(preset, todayYmd) {
    const today = parseYmd(todayYmd);
    if (!today) return null;
    const y = today.getUTCFullYear(), m = today.getUTCMonth();
    if (preset === 'today') return { from: todayYmd, to: todayYmd };
    if (preset === 'week') {
      const offset = (today.getUTCDay() + 6) % 7;
      return { from: ymd(new Date(Date.UTC(y, m, today.getUTCDate() - offset))), to: todayYmd };
    }
    if (preset === 'month') return { from: ymd(new Date(Date.UTC(y, m, 1))), to: todayYmd };
    if (preset === 'last-month') return { from: ymd(new Date(Date.UTC(y, m - 1, 1))), to: ymd(new Date(Date.UTC(y, m, 0))) };
    return null;
  }

  // ---- money --------------------------------------------------------------

  const num = value => Number(value || 0);
  // Negative figures read "−₱4,768.00" rather than money()'s "₱-4,768.00".
  const peso = value => (num(value) < 0 ? '−' + money(-num(value)) : money(num(value)));

  function errorText(error, fallback) {
    const errors = error && error.data && error.data.errors;
    if (errors && typeof errors === 'object') {
      const all = [...new Set(Object.values(errors).flat().filter(Boolean))];
      if (all.length) return all.join(' ');
    }
    return (error && error.message) || fallback;
  }

  function announce(message) {
    const node = $('repStatus');
    if (node) node.textContent = message;
  }

  // ---- stats --------------------------------------------------------------

  function renderStats() {
    const box = $('repStats');
    if (!box) return;
    const d = state.data;
    const loading = !d && !state.error;
    const stat = (label, value, sub, opts) => h('div', { class: 'stat' + (opts && opts.cls ? ' ' + opts.cls : '') },
      h('span', { class: 'stat__label', text: label }),
      loading ? h('span', { class: 'skeleton skeleton--figure' }) : h('span', { class: 'stat__value', text: value }),
      h('span', { class: 'stat__sub', text: loading ? '' : (sub || '') }));
    const tone = value => (num(value) < 0 ? 'is-alert' : (num(value) === 0 ? 'is-clear' : ''));

    if (!d) {
      box.replaceChildren(...['Net sales', 'Collected', 'Expenses', 'Operating margin', 'Cash flow']
        .map(label => stat(label, '—', state.error ? 'Not loaded' : '')));
      return;
    }
    box.replaceChildren(
      stat('Net sales', peso(d.sales.net), d.sales.count + (d.sales.count === 1 ? ' sale' : ' sales') + ' · without VAT'),
      stat('Collected', peso(d.collections.total), 'Cash only (GCash not included), incl. debt payments'),
      stat('Expenses', peso(d.expenses.total), 'Recorded, not voided', { cls: num(d.expenses.total) === 0 ? 'is-clear' : '' }),
      stat('Operating margin', peso(d.operating_margin.value),
        costIncomplete(d.cost_of_supplies) ? 'Some supply costs unknown' : 'After supplies and expenses', { cls: tone(d.operating_margin.value) }),
      stat('Cash flow', peso(d.cash_flow.value), 'Cash collected less purchases (at order cost; supplier credit not modelled) and expenses', { cls: tone(d.cash_flow.value) }));
  }

  // ---- sheet --------------------------------------------------------------

  function table(caption, head, rows, opts) {
    const numeric = (opts && opts.numeric) || [];
    return h('table', { class: 'rep-table' },
      h('caption', { class: 'visually-hidden', text: caption }),
      h('thead', null, h('tr', null, head.map((label, i) => h('th', { scope: 'col', class: numeric.includes(i) ? 'num' : null, text: label })))),
      h('tbody', null, rows.map(row => h('tr', { class: row.cls || null },
        row.cells.map((cell, i) => h(i === 0 ? 'th' : 'td', { scope: i === 0 ? 'row' : null, class: numeric.includes(i) ? 'num' : null, text: cell }))))));
  }

  function section(id, title, ...kids) {
    return h('section', { class: 'rep-section', id, 'aria-labelledby': id + 'Title' },
      h('h3', { class: 'rep-section__title', id: id + 'Title', text: title }), ...kids);
  }

  function emptyLine(text) {
    return h('p', { class: 'rep-empty', text });
  }

  function profitBlock(d) {
    const cost = d.cost_of_supplies;
    const line = (label, value, sign, cls) => h('tr', { class: cls || null },
      h('th', { scope: 'row', text: label }),
      h('td', { class: 'num', text: (sign || '') + peso(value) }));
    const marginRows = [
      line('Net sales (without VAT)', d.sales.net),
      line('Cost of supplies consumed' + (costIncomplete(cost) ? ' *' : ''), cost.total, '− '),
      line('Expenses', d.expenses.total, '− '),
      line('Operating margin', d.operating_margin.value, '', 'rep-result' + (num(d.operating_margin.value) < 0 ? ' is-negative' : ''))
    ];
    const flowRows = [
      line('Money collected', d.collections.total),
      line('Purchases received', d.purchases_received.total, '− '),
      line('Expenses', d.expenses.total, '− '),
      line('Cash flow', d.cash_flow.value, '', 'rep-result' + (num(d.cash_flow.value) < 0 ? ' is-negative' : ''))
    ];
    const card = (title, rows, formula, note) => h('div', { class: 'rep-profit__card' },
      h('h4', { class: 'rep-profit__title', text: title }),
      h('p', { class: 'rep-formula', text: formula }),
      h('table', { class: 'rep-table rep-profit__table' }, h('caption', { class: 'visually-hidden', text: title }), h('tbody', null, rows)),
      note ? h('p', { class: 'rep-profit__note', text: note }) : null);
    return section('repProfit', 'Profit',
      h('div', { class: 'rep-profit' },
        card('Operating margin', marginRows, d.operating_margin.formula,
          costIncomplete(cost) ? '* ' + incompleteText(cost) + ' The margin is overstated by the missing cost.' : 'Shows whether the period\'s sales covered what they used up and what the station spent.'),
        card('Cash flow', flowRows, d.cash_flow.formula, 'Shows whether more money came in than went out to suppliers and expenses in these dates.')));
  }

  // Cost of supplies is incomplete when a consumed item has no purchase cost
  // or when sales recorded no supply use at all (imported/generated history).
  function costIncomplete(cost) {
    return !!cost && (cost.items_without_cost > 0 || (cost.sales_without_consumption || 0) > 0);
  }

  function unrecordedText(cost) {
    const n = cost.sales_without_consumption || 0;
    return 'Supply use was not recorded for ' + n.toLocaleString() + (n === 1 ? ' sale' : ' sales') + '; cost of supplies and margin are incomplete.';
  }

  function incompleteText(cost) {
    const parts = [];
    if ((cost.sales_without_consumption || 0) > 0) parts.push(unrecordedText(cost));
    if (cost.items_without_cost > 0) parts.push(uncostedText(cost));
    return parts.join(' ');
  }

  function uncostedText(cost) {
    const names = (cost.uncosted_items || []).map(i => i.name).filter(Boolean);
    const n = cost.items_without_cost;
    return n + (n === 1 ? ' supply item has' : ' supply items have') + ' no purchase cost on record (' +
      cost.units_without_cost + ' units' + (names.length ? ': ' + names.join(', ') : '') + '), so they are not included in the cost of supplies.';
  }

  function renderChart(products) {
    if (state.chart) { state.chart.destroy(); state.chart = null; }
    const canvas = $('repProductChart');
    if (!canvas || typeof Chart === 'undefined' || !products.length) return;
    const top = products.slice(0, 10);
    state.chart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: top.map(p => p.name),
        datasets: [{ label: 'Revenue', data: top.map(p => num(p.revenue)), backgroundColor: '#1D4ED8', borderRadius: 4, maxBarThickness: 28 }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: context => peso(context.parsed.x) } }
        },
        scales: {
          x: { beginAtZero: true, ticks: { callback: value => '₱' + Number(value).toLocaleString() }, grid: { color: '#E2E8F0' } },
          y: { grid: { display: false }, ticks: { autoSkip: false } }
        }
      }
    });
  }

  function chartBox(count) {
    const box = h('div', { class: 'rep-chart' },
      h('canvas', { id: 'repProductChart', role: 'img', 'aria-label': 'Bar chart of revenue by product. The table below lists the same figures.' }));
    box.style.height = Math.max(140, Math.min(count, 10) * 36 + 40) + 'px';
    return box;
  }

  function renderSheet() {
    const sheet = $('repSheet');
    const body = $('repBody');
    if (!sheet || !body) return;
    sheet.setAttribute('aria-busy', state.loading && !state.data ? 'true' : 'false');
    const d = state.data;

    if (!d) {
      if (state.chart) { state.chart.destroy(); state.chart = null; }
      $('repMetaPeriod').textContent = state.applied ? fmtPeriod(state.applied.from, state.applied.to) : '—';
      $('repMetaGenerated').textContent = '—';
      if (state.error) {
        body.replaceChildren(h('div', { class: 'error-state', role: 'alert' },
          h('p', { class: 'error-state__message', text: 'The report could not be loaded. ' + state.error }),
          h('button', { type: 'button', class: 'btn btn-secondary btn-sm error-state__retry no-print', text: 'Retry', onclick: () => refresh() })));
      } else {
        body.replaceChildren(h('div', { class: 'rep-skeleton', 'aria-hidden': 'true' },
          [1, 2, 3, 4, 5].map(() => h('span', { class: 'skeleton skeleton--block' }))));
      }
      return;
    }

    $('repMetaPeriod').textContent = fmtPeriod(d.period.from, d.period.to) + ' (' + d.period.days + (d.period.days === 1 ? ' day)' : ' days)');
    $('repMetaGenerated').textContent = state.generatedAt ? state.generatedAt.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';

    const s = d.sales;
    const noSales = s.count === 0;

    const salesSummary = section('repSales', 'Sales',
      table('Sales totals', ['', 'Amount'], [
        { cells: ['Gross sales (VAT included)', peso(s.gross)] },
        { cells: ['VAT (12%)', peso(s.vat)] },
        { cells: ['Net sales (without VAT)', peso(s.net)], cls: 'rep-strong' },
        { cells: ['Discounts', peso(s.discounts)] },
        { cells: ['Number of sales', s.count.toLocaleString()] }
      ], { numeric: [1] }),
      h('p', { class: 'rep-hint', text: 'Matches Sales History for the same dates: voided sales and debt payments are left out.' }));

    const byProduct = section('repByProduct', 'Sales by product',
      noSales ? emptyLine('No sales in this period.') : [
        chartBox(s.by_product.length),
        table('Sales by product', ['Product', 'Qty', 'Revenue'],
          s.by_product.map(p => ({ cells: [p.name, p.qty.toLocaleString(), peso(p.revenue)] })), { numeric: [1, 2] })
      ]);

    const byCashier = section('repByCashier', 'By cashier',
      noSales ? emptyLine('No sales in this period.') :
        table('Sales by cashier', ['Cashier', 'Sales', 'Gross', 'Net'],
          s.by_cashier.map(c => ({ cells: [c.name, c.count.toLocaleString(), peso(c.gross), peso(c.net)] })), { numeric: [1, 2, 3] }));

    const byType = section('repByType', 'By order type',
      noSales ? emptyLine('No sales in this period.') : [
        table('Sales by order type', ['Order type', 'Sales', 'Gross', 'Net'],
          s.by_order_type.map(t => ({ cells: [t.order_type, t.count.toLocaleString(), peso(t.gross), peso(t.net)] })), { numeric: [1, 2, 3] }),
        table('Sales by payment method', ['Payment', 'Sales', 'Gross', 'Net'],
          s.by_payment_method.map(t => ({ cells: [t.payment_method === 'Account' ? 'Charged to account' : t.payment_method, t.count.toLocaleString(), peso(t.gross), peso(t.net)] })), { numeric: [1, 2, 3] })
      ]);

    const c = d.collections;
    const collections = section('repCollections', 'Money collected',
      table('Money collected', ['Source', 'Amount'], [
        { cells: ['Cash sales', peso(c.sales_cash)] },
        { cells: ['Debt payments at the counter', peso(c.debt_payments)] },
        { cells: ['Rider remittances', peso(c.remittances)] },
        { cells: ['Total collected', peso(c.total)], cls: 'rep-strong' }
      ], { numeric: [1] }),
      h('p', { class: 'rep-hint', text: 'Same basis as the cash drawer. Sales charged to an account count when they are paid.' }));

    const exp = d.expenses;
    const expenseRows = Object.keys(exp.by_category).filter(k => num(exp.by_category[k]) !== 0)
      .sort((a, b) => num(exp.by_category[b]) - num(exp.by_category[a]))
      .map(k => ({ cells: [CATEGORY_LABELS[k] || k, peso(exp.by_category[k])] }));
    const expenses = section('repExpenses', 'Expenses by category',
      expenseRows.length
        ? table('Expenses by category', ['Category', 'Amount'], [...expenseRows, { cells: ['Total expenses', peso(exp.total)], cls: 'rep-strong' }], { numeric: [1] })
        : emptyLine('No expenses recorded in this period.'));

    const supplies = section('repSupplies', 'Supplies and purchases',
      table('Supplies and purchases', ['', 'Amount'], [
        { cells: ['Cost of supplies consumed', peso(d.cost_of_supplies.total)] },
        { cells: ['Purchases received (purchase orders)', peso(d.purchases_received.total)] }
      ], { numeric: [1] }),
      h('p', { class: 'rep-hint', text: 'Supplies are costed at the latest purchase price on or before each sale.' }));

    body.replaceChildren(
      profitBlock(d),
      h('div', { class: 'rep-grid' }, salesSummary, collections),
      byProduct,
      h('div', { class: 'rep-grid' }, byCashier, byType),
      h('div', { class: 'rep-grid' }, expenses, supplies));
    renderChart(noSales ? [] : s.by_product);
  }

  function renderNote() {
    const note = $('repCostNote');
    if (!note) return;
    const cost = state.data && state.data.cost_of_supplies;
    if (!costIncomplete(cost)) { note.hidden = true; note.textContent = ''; return; }
    const kids = [h('strong', { text: 'Missing supply costs. ' })];
    if ((cost.sales_without_consumption || 0) > 0) kids.push(h('span', { class: 'rep-note__line', text: unrecordedText(cost) + ' ' }));
    if (cost.items_without_cost > 0) kids.push(h('span', { class: 'rep-note__line', text: uncostedText(cost) + ' Record a purchase cost for them (receive a purchase order or a restock with a unit cost) to complete the operating margin.' }));
    note.replaceChildren(...kids);
    note.hidden = false;
  }

  function renderActions() {
    const ready = !!state.data && !state.loading;
    $('repExportBtn').disabled = !ready;
    $('repPrintBtn').disabled = !ready;
    $('repApplyBtn').disabled = state.loading;
  }

  function renderPresets() {
    document.querySelectorAll('#repPresets .rep-preset').forEach(btn => {
      const on = btn.dataset.preset === state.preset;
      btn.setAttribute('aria-checked', on ? 'true' : 'false');
      btn.tabIndex = on ? 0 : -1;
    });
  }

  function render() {
    renderStats();
    renderNote();
    renderSheet();
    renderActions();
    renderPresets();
  }

  // ---- load ---------------------------------------------------------------

  async function load(range, quiet) {
    const seq = ++state.seq;
    state.loading = true;
    if (!quiet) { state.data = null; state.error = null; }
    state.applied = range;
    render();
    try {
      const data = await API.reportSummary(range ? { from: range.from, to: range.to } : {});
      if (seq !== state.seq) return;
      state.data = data;
      state.error = null;
      state.generatedAt = new Date();
      state.applied = { from: data.period.from, to: data.period.to };
      if (!range && !state.today) state.today = data.period.to; // default range ends on station today
      $('repFrom').value = data.period.from;
      $('repTo').value = data.period.to;
      announce('Report loaded for ' + fmtPeriod(data.period.from, data.period.to) + '. Net sales ' + peso(data.sales.net) + '.');
    } catch (error) {
      if (seq !== state.seq) return;
      // A failed report never leaves older figures exportable.
      state.data = null;
      state.error = errorText(error, 'Please try again.');
      announce('The report could not be loaded.');
    } finally {
      if (seq === state.seq) { state.loading = false; render(); }
    }
  }

  function refresh() {
    return load(state.applied, !!state.data);
  }

  function showRangeMessage(text) {
    const box = $('repRangeMsg');
    box.textContent = text || '';
    box.hidden = !text;
  }

  function validRange(from, to) {
    if (!parseYmd(from) || !parseYmd(to)) return 'Choose both a start and an end date.';
    if (from > to) return 'The start date must be on or before the end date.';
    if (daysBetween(from, to) > MAX_DAYS) return 'Choose a period of at most ' + MAX_DAYS + ' days.';
    return '';
  }

  function applyCustom() {
    if (state.loading) return;
    const from = $('repFrom').value, to = $('repTo').value;
    const problem = validRange(from, to);
    showRangeMessage(problem);
    if (problem) return;
    state.preset = matchingPreset(from, to);
    load({ from, to });
  }

  function matchingPreset(from, to) {
    if (!state.today) return 'custom';
    for (const preset of ['today', 'week', 'month', 'last-month']) {
      const r = presetRange(preset, state.today);
      if (r && r.from === from && r.to === to) return preset;
    }
    return 'custom';
  }

  function choosePreset(preset) {
    if (state.loading) return;
    showRangeMessage('');
    state.preset = preset;
    if (preset === 'custom') {
      renderPresets();
      $('repFrom').focus();
      return;
    }
    const range = presetRange(preset, state.today);
    load(range);
  }

  // ---- export & print -----------------------------------------------------

  function exportCsv() {
    const d = state.data;
    if (!d || state.loading) return;
    const rows = [['Section', 'Item', 'Count or qty', 'Amount']];
    const add = (sectionName, item, count, amount) => rows.push([sectionName, item, count === '' ? '' : Number(count), amount === '' ? '' : Number(amount)]);
    rows.push(['Period', d.period.from + ' to ' + d.period.to, '', '']);
    add('Sales', 'Gross sales (VAT included)', d.sales.count, d.sales.gross);
    add('Sales', 'VAT', '', d.sales.vat);
    add('Sales', 'Net sales (without VAT)', '', d.sales.net);
    add('Sales', 'Discounts', '', d.sales.discounts);
    d.sales.by_product.forEach(p => add('Sales by product', p.name, p.qty, p.revenue));
    d.sales.by_cashier.forEach(c => add('Sales by cashier', c.name, c.count, c.gross));
    d.sales.by_order_type.forEach(t => add('Sales by order type', t.order_type, t.count, t.gross));
    d.sales.by_payment_method.forEach(t => add('Sales by payment method', t.payment_method, t.count, t.gross));
    add('Collections', 'Cash sales', '', d.collections.sales_cash);
    add('Collections', 'Debt payments', '', d.collections.debt_payments);
    add('Collections', 'Rider remittances', '', d.collections.remittances);
    add('Collections', 'Total collected', '', d.collections.total);
    Object.keys(d.expenses.by_category).forEach(k => add('Expenses', CATEGORY_LABELS[k] || k, '', d.expenses.by_category[k]));
    add('Expenses', 'Total expenses', '', d.expenses.total);
    add('Cost of supplies', 'Cost of supplies consumed', '', d.cost_of_supplies.total);
    add('Cost of supplies', 'Supply items without a cost', d.cost_of_supplies.items_without_cost, '');
    add('Cost of supplies', 'Sales with no supply use recorded', d.cost_of_supplies.sales_without_consumption || 0, '');
    add('Cost of supplies', 'Units without a cost', d.cost_of_supplies.units_without_cost, '');
    add('Purchases', 'Purchases received', '', d.purchases_received.total);
    add('Profit', 'Operating margin', '', d.operating_margin.value);
    rows.push(['Profit', 'Operating margin formula', '', d.operating_margin.formula]);
    add('Profit', 'Cash flow', '', d.cash_flow.value);
    rows.push(['Profit', 'Cash flow formula', '', d.cash_flow.formula]);
    downloadCSV(rows.map(r => r.map(csvCell).join(',')).join('\n'), 'report-' + d.period.from + '-to-' + d.period.to + '.csv');
  }

  function print() {
    if (!state.data || state.loading) return;
    window.print();
  }

  // ---- init ---------------------------------------------------------------

  function init() {
    const group = $('repPresets');
    group.addEventListener('click', event => {
      const btn = event.target.closest('.rep-preset');
      if (btn) choosePreset(btn.dataset.preset);
    });
    // Arrow keys move between presets, as in a radio group.
    group.addEventListener('keydown', event => {
      if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
      const buttons = [...group.querySelectorAll('.rep-preset')];
      const index = buttons.indexOf(document.activeElement);
      if (index < 0) return;
      event.preventDefault();
      const next = buttons[(index + (event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 1) + buttons.length) % buttons.length];
      next.focus();
      choosePreset(next.dataset.preset);
    });
    $('repApplyBtn').addEventListener('click', applyCustom);
    ['repFrom', 'repTo'].forEach(id => $(id).addEventListener('change', () => {
      state.preset = 'custom';
      renderPresets();
      showRangeMessage('');
    }));
    ['repFrom', 'repTo'].forEach(id => $(id).addEventListener('keydown', event => {
      if (event.key === 'Enter') { event.preventDefault(); applyCustom(); }
    }));
    window.addEventListener('beforeprint', () => { if (state.chart) state.chart.resize(); });
    render();
    // No dates: the server answers with this month so far, which also tells
    // us the station's today for the other presets.
    return load(null);
  }

  return { init, refresh, exportCsv, print, presetRange };
})();
