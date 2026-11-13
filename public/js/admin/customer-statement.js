// AquaFlow - Customer account statement (admin/customers).
//
// openCustomerStatement(customerId) loads GET api/customers/{id}/statement and
// shows it in #statementSheet as a printable document: station, customer,
// period, opening balance, a line per charge or payment, totals and the
// closing balance. The server owns every figure (including the running
// balance, which reconciles with the customer's debt balance); this file only
// lays them out.
//
// Dynamic content is built with DOM nodes and textContent, never innerHTML.

const CSTM = (() => {
  const MAX_DAYS = 366;
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  const state = {
    id: null,
    opener: null,
    data: null,
    from: '',
    to: '',
    loading: false,
    error: null,
    notice: '',
    seq: 0
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

  function fmtDate(ymd) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd || '');
    if (!m) return ymd || '—';
    return Number(m[3]) + ' ' + MONTHS[Number(m[2]) - 1] + ' ' + m[1];
  }

  function fmtTime(hms) {
    const m = /^(\d{2}):(\d{2})/.exec(hms || '');
    if (!m) return '';
    const hour = Number(m[1]);
    return ((hour % 12) || 12) + ':' + m[2] + (hour < 12 ? ' AM' : ' PM');
  }

  function errorText(error, fallback) {
    const errors = error && error.data && error.data.errors;
    if (errors && typeof errors === 'object') {
      const all = [...new Set(Object.values(errors).flat().filter(Boolean))];
      if (all.length) return all.join(' ');
    }
    return (error && error.message) || fallback;
  }

  function dayDiff(fromYmd, toYmd) {
    return Math.round((Date.parse(toYmd + 'T00:00:00Z') - Date.parse(fromYmd + 'T00:00:00Z')) / 86400000);
  }

  // ---- rendering ----------------------------------------------------------

  function announce(message) {
    const node = $('stmtStatus');
    if (node) node.textContent = message;
  }

  function toolbar() {
    const busy = state.loading;
    return h('div', { class: 'stmt-toolbar no-print' },
      h('div', { class: 'stmt-period', role: 'group', 'aria-label': 'Statement period' },
        h('div', { class: 'stmt-field' },
          h('label', { for: 'stmtFrom', text: 'From' }),
          h('input', { id: 'stmtFrom', type: 'date', class: 'form-input', value: state.from, onkeydown: applyOnEnter })),
        h('div', { class: 'stmt-field' },
          h('label', { for: 'stmtTo', text: 'To' }),
          h('input', { id: 'stmtTo', type: 'date', class: 'form-input', value: state.to, onkeydown: applyOnEnter })),
        h('button', { type: 'button', id: 'stmtApply', class: 'btn btn-secondary', text: busy ? 'Loading…' : 'Apply', disabled: busy, onclick: apply })),
      h('div', { class: 'stmt-actions' },
        h('button', { type: 'button', id: 'stmtPrint', class: 'btn btn-primary', text: 'Print', disabled: !state.data || busy, onclick: () => window.print() }),
        h('button', { type: 'button', id: 'stmtClose', class: 'btn btn-ghost', text: 'Close', onclick: close })),
      h('p', { id: 'stmtNotice', class: 'stmt-notice', role: 'alert', text: state.notice }));
  }

  function applyOnEnter(event) {
    if (event.key === 'Enter') { event.preventDefault(); apply(); }
  }

  function head(data) {
    const station = ($('statementSheet') && $('statementSheet').dataset.station) || 'AquaFlow';
    const period = data ? fmtDate(data.period.from) + ' to ' + fmtDate(data.period.to) : '—';
    return h('header', { class: 'sheet__head' },
      h('div', null,
        h('p', { class: 'sheet__station', text: station }),
        h('h2', { class: 'sheet__title', id: 'stmtTitle', tabindex: '-1', text: 'Statement of Account' })),
      h('dl', { class: 'sheet__meta' },
        h('dt', { text: 'Period' }), h('dd', { text: period }),
        h('dt', { text: 'Printed' }), h('dd', { text: fmtDate(new Date().toLocaleDateString('en-CA')) })));
  }

  function customerBlock(data) {
    if (!data) return h('div', { class: 'stmt-customer' }, h('span', { class: 'skeleton skeleton--text' }));
    const c = data.customer;
    return h('div', { class: 'stmt-customer' },
      h('p', { class: 'stmt-customer__name', text: c.name }),
      c.address ? h('p', { class: 'stmt-customer__line', text: c.address }) : null,
      c.contact ? h('p', { class: 'stmt-customer__line', text: c.contact }) : null);
  }

  function body() {
    if (state.error && !state.data) {
      return h('div', { class: 'error-state', role: 'alert' },
        h('p', { class: 'error-state__message', text: 'The statement could not be loaded. ' + state.error }),
        h('button', { type: 'button', class: 'btn btn-secondary btn-sm error-state__retry no-print', text: 'Retry', onclick: () => load() }));
    }
    if (!state.data) {
      return h('div', { class: 'stmt-skeleton', 'aria-hidden': 'true' },
        [1, 2, 3, 4].map(() => h('span', { class: 'skeleton skeleton--text' })));
    }

    const data = state.data;
    const rows = data.lines.map(line => h('tr', null,
      h('td', { 'data-label': 'Date' }, fmtDate(line.date), line.time ? h('span', { class: 'stmt-time', text: ' ' + fmtTime(line.time) }) : null),
      h('td', { 'data-label': 'Receipt', text: line.receipt_number || '—' }),
      h('td', { 'data-label': 'Description', text: line.description || '' }),
      h('td', { class: 'num', 'data-label': 'Charge', text: Number(line.charge) > 0 ? money(line.charge) : '' }),
      h('td', { class: 'num', 'data-label': 'Payment', text: Number(line.payment) > 0 ? money(line.payment) : '' }),
      h('td', { class: 'num', 'data-label': 'Balance', text: money(line.balance_after) })));

    const opening = h('tr', { class: 'stmt-opening' },
      h('td', { colspan: '5', text: 'Opening balance' }),
      h('td', { class: 'num', text: money(data.opening_balance) }));

    const table = h('div', { class: 'stmt-table-wrap' },
      h('table', { class: 'stmt-table' },
        h('caption', { class: 'visually-hidden', text: 'Account activity for the selected period' }),
        h('thead', null, h('tr', null,
          h('th', { scope: 'col', text: 'Date' }),
          h('th', { scope: 'col', text: 'Receipt' }),
          h('th', { scope: 'col', text: 'Description' }),
          h('th', { scope: 'col', class: 'num', text: 'Charge' }),
          h('th', { scope: 'col', class: 'num', text: 'Payment' }),
          h('th', { scope: 'col', class: 'num', text: 'Balance' }))),
        h('tbody', null, opening, rows)));

    const empty = data.lines.length ? null : h('div', { class: 'empty-state' },
      h('p', { class: 'empty-state__title', text: 'No account activity in this period' }),
      h('p', { class: 'empty-state__body', text: 'No charges or payments were recorded for these dates. Choose a wider range to see earlier activity.' }));

    const totals = h('table', { class: 'sheet__totals' },
      h('tbody', null,
        h('tr', null, h('td', { text: 'Total charges' }), h('td', { class: 'num', text: money(data.totals.charges) })),
        h('tr', null, h('td', { text: 'Total payments' }), h('td', { class: 'num', text: money(data.totals.payments) })),
        h('tr', null, h('td', { text: 'Closing balance' }), h('td', { class: 'num', text: money(data.closing_balance) }))));

    const differs = Math.abs(Number(data.current_balance) - Number(data.closing_balance)) >= 0.005;
    const foot = h('p', { class: 'sheet__foot' },
      differs ? 'Balance today: ' + money(data.current_balance) + '. ' : '',
      'Cash sales are not listed; only charges to the account and payments received.');

    return h('div', null, table, empty, totals, foot);
  }

  function render() {
    const sheet = $('statementSheet');
    if (!sheet) return;
    const hadFocus = sheet.contains(document.activeElement) ? document.activeElement.id : '';
    sheet.setAttribute('aria-busy', state.loading ? 'true' : 'false');
    sheet.replaceChildren(
      h('div', { id: 'stmtStatus', class: 'visually-hidden', role: 'status', 'aria-live': 'polite' }),
      toolbar(),
      head(state.data),
      customerBlock(state.data),
      body());
    if (hadFocus && $(hadFocus) && !$(hadFocus).disabled) $(hadFocus).focus();
  }

  // ---- loading ------------------------------------------------------------

  async function load() {
    const seq = ++state.seq;
    state.loading = true;
    state.error = null;
    render();
    const params = {};
    if (state.from) params.from = state.from;
    if (state.to) params.to = state.to;
    try {
      const data = await API.customerStatement(state.id, params);
      if (seq !== state.seq) return;
      state.data = data;
      state.from = data.period.from;
      state.to = data.period.to;
      state.notice = '';
      state.loading = false;
      render();
      announce('Statement loaded: ' + data.lines.length + (data.lines.length === 1 ? ' entry.' : ' entries.'));
    } catch (error) {
      if (seq !== state.seq) return;
      state.loading = false;
      const message = errorText(error, 'Please try again.');
      if (state.data) state.notice = message; else state.error = message;
      render();
      announce('The statement could not be loaded.');
    }
  }

  function apply() {
    if (state.loading) return;
    const from = $('stmtFrom').value;
    const to = $('stmtTo').value;
    let problem = '';
    if (!from || !to) problem = 'Choose both a start and an end date.';
    else if (from > to) problem = 'The start date must be on or before the end date.';
    else if (dayDiff(from, to) >= MAX_DAYS) problem = 'Choose a range of at most ' + MAX_DAYS + ' days.';
    if (problem) {
      state.notice = problem;
      const notice = $('stmtNotice');
      if (notice) notice.textContent = problem;
      return;
    }
    state.from = from;
    state.to = to;
    state.notice = '';
    load();
  }

  // ---- open / close -------------------------------------------------------

  function trapKeys(event) {
    const sheet = $('statementSheet');
    if (!sheet || sheet.hidden) return;
    if (event.key === 'Escape') {
      // Close only the statement; the drawer underneath stays open.
      event.preventDefault();
      event.stopImmediatePropagation();
      close();
      return;
    }
    if (event.key !== 'Tab') return;
    const focusable = [...sheet.querySelectorAll('button, input, [tabindex="-1"]#stmtTitle')]
      .filter(node => !node.disabled && node.offsetParent !== null);
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && (document.activeElement === first || document.activeElement === sheet)) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  }

  function open(customerId, opener) {
    const sheet = $('statementSheet');
    if (!sheet) return;
    state.id = customerId;
    state.opener = opener || document.activeElement;
    state.data = null;
    state.from = '';
    state.to = '';
    state.error = null;
    state.notice = '';
    sheet.hidden = false;
    sheet.setAttribute('role', 'dialog');
    sheet.setAttribute('aria-modal', 'true');
    sheet.setAttribute('aria-labelledby', 'stmtTitle');
    document.body.classList.add('has-statement');
    document.addEventListener('keydown', trapKeys, true);
    render();
    const title = $('stmtTitle');
    if (title) title.focus();
    load();
  }

  function close() {
    const sheet = $('statementSheet');
    if (!sheet || sheet.hidden) return;
    state.seq++;
    state.loading = false;
    sheet.hidden = true;
    sheet.replaceChildren();
    document.body.classList.remove('has-statement');
    document.removeEventListener('keydown', trapKeys, true);
    const opener = state.opener && state.opener.isConnected ? state.opener : $('custStatementBtn');
    if (opener && typeof opener.focus === 'function') opener.focus();
  }

  return { open, close };
})();

function openCustomerStatement(customerId) {
  CSTM.open(Number(customerId), document.getElementById('custStatementBtn'));
}
