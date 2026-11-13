// AquaFlow - Expenses page (admin/expenses).
//
// Records operating expenses, filters them by date range and category and
// exports the list. The server owns every rule (dates, amounts, idempotency,
// owner-only void); this file gives feedback sooner and hides the Void action
// from anyone who is not the owner.
//
// Dynamic content is built with DOM nodes and textContent, never innerHTML.

const EXP = (() => {
  const CATEGORIES = [
    ['utilities', 'Utilities'],
    ['rent', 'Rent'],
    ['salaries', 'Salaries & wages'],
    ['supplies', 'Supplies'],
    ['maintenance', 'Maintenance'],
    ['transport', 'Transport & fuel'],
    ['permits', 'Permits & fees'],
    ['other', 'Other']
  ];
  const LABEL = Object.fromEntries(CATEGORIES);
  const KEY_STORE = 'aquaflow_expense_attempt';
  const LIST_CAP = 500;

  const state = {
    list: [],
    totals: null,
    range: null,
    loaded: false,
    loading: false,
    error: null,
    seq: 0,
    formOpen: false,
    busy: false,
    voidId: null
  };

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

  const $ = id => document.getElementById(id);

  function fmtDate(ymd) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd || '');
    if (!m) return ymd || '—';
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return Number(m[3]) + ' ' + months[Number(m[2]) - 1] + ' ' + m[1];
  }

  function todayYmd() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function isOwner() {
    const s = typeof getSession === 'function' ? getSession() : null;
    return !!s && s.role === 'super_admin';
  }

  function errorText(error, fallback) {
    const errors = error && error.data && error.data.errors;
    if (errors && typeof errors === 'object') {
      const all = [...new Set(Object.values(errors).flat().filter(Boolean))];
      if (all.length) return all.join(' ');
    }
    return (error && error.message) || fallback;
  }

  // A failure where the server may or may not have acted on the request.
  function isUncertain(error) {
    return !!(error && (error.offline || error.status === undefined || error.status >= 500 || error.status === 408 || error.status === 429));
  }

  function announce(message) {
    const node = $('expStatus');
    if (node) node.textContent = message;
  }

  function setMsg(id, text, kind) {
    const box = $(id);
    if (!box) return;
    box.className = 'exp-msg' + (text ? ' exp-msg--' + (kind || 'error') : '');
    box.textContent = text || '';
  }

  // ---- stats --------------------------------------------------------------

  function renderStats() {
    const box = $('expStats');
    if (!box) return;
    box.replaceChildren();
    const loading = !state.loaded && !state.error;
    const stat = (label, value, sub, cls) => h('div', { class: 'stat' + (cls ? ' ' + cls : '') },
      h('span', { class: 'stat__label', text: label }),
      loading ? h('span', { class: 'skeleton skeleton--figure' }) : h('span', { class: 'stat__value', text: value }),
      h('span', { class: 'stat__sub', text: loading ? '' : (sub || '') }));

    const totals = state.totals;
    const ranked = totals
      ? CATEGORIES.map(([key, label]) => ({ key, label, amount: Number(totals.by_category[key]) || 0 }))
        .filter(c => c.amount > 0).sort((a, b) => b.amount - a.amount).slice(0, 3)
      : [];
    const total = totals ? Number(totals.total) : 0;
    const period = state.range ? fmtDate(state.range.from) + ' to ' + fmtDate(state.range.to) : '';

    box.append(stat('Period total', totals ? money(total) : '—', totals ? period : (state.error ? 'Could not load' : '')));
    for (let i = 0; i < 3; i++) {
      const c = ranked[i];
      const share = c && total > 0 ? Math.round((c.amount / total) * 100) + '% of total' : '';
      box.append(stat(i === 0 ? 'Top category' : (i === 1 ? 'Second' : 'Third'),
        c ? money(c.amount) : '—', c ? c.label + (share ? ' · ' + share : '') : (totals ? 'No spending' : '')));
    }
  }

  // ---- list ---------------------------------------------------------------

  function skeleton() {
    return h('div', { class: 'exp-skeleton', 'aria-hidden': 'true' },
      [1, 2, 3, 4].map(() => h('span', { class: 'skeleton skeleton--text' })));
  }

  function renderList() {
    const box = $('expList');
    if (!box) return;
    box.setAttribute('aria-busy', state.loading && !state.loaded ? 'true' : 'false');
    const exportBtn = $('expExportBtn');
    if (exportBtn) exportBtn.disabled = !state.list.length;

    if (!state.loaded) {
      if (state.error) {
        box.replaceChildren(h('div', { class: 'error-state', role: 'alert' },
          h('p', { class: 'error-state__message', text: 'Expenses could not be loaded. ' + state.error }),
          h('button', { type: 'button', class: 'btn btn-secondary btn-sm error-state__retry', text: 'Retry', onclick: () => refresh() })));
      } else {
        box.replaceChildren(skeleton());
      }
      return;
    }

    if (!state.list.length) {
      const filtered = !!$('expCategory').value;
      box.replaceChildren(h('div', { class: 'empty-state' },
        h('p', { class: 'empty-state__title', text: 'No expenses in this period' }),
        h('p', { class: 'empty-state__body', text: filtered ? 'Nothing was recorded in this category for these dates. Try another category or a wider range.' : 'Record the first one, or widen the date range.' }),
        h('button', { type: 'button', class: 'btn btn-secondary empty-state__action', text: 'Record expense', onclick: () => toggleForm(true) })));
      return;
    }

    const owner = isOwner();
    const head = h('tr', null,
      h('th', { scope: 'col', text: 'Date' }),
      h('th', { scope: 'col', text: 'Category' }),
      h('th', { scope: 'col', text: 'Payee' }),
      h('th', { scope: 'col', text: 'Reference' }),
      h('th', { scope: 'col', class: 'num', text: 'Amount' }),
      h('th', { scope: 'col', text: 'Recorded by' }),
      owner ? h('th', { scope: 'col', text: 'Action' }) : null);

    const rows = state.list.map(e => {
      const voided = !!e.voided_at;
      const strike = text => h('span', { class: voided ? 'exp-strike' : null, text });
      const payee = h('td', { 'data-label': 'Payee' }, strike(e.payee || '—'),
        e.notes ? h('span', { class: 'cell-sub', text: ' ' + e.notes }) : null);
      const status = voided
        ? h('span', { class: 'exp-void-reason' },
          h('span', { class: 'state-pill', 'data-state': 'voided', text: 'Voided' }),
          ' ' + (e.void_reason || '') + (e.voided_by && e.voided_by.name ? ' (by ' + e.voided_by.name + ')' : ''))
        : null;
      return h('tr', { class: 'exp-row', 'data-voided': voided ? 'true' : 'false', 'data-expense-id': e.id },
        h('td', { 'data-label': 'Date' }, strike(fmtDate(e.spent_on))),
        h('td', { 'data-label': 'Category' }, h('div', { class: 'exp-cat' }, h('span', { class: 'exp-main' }, strike(e.category_label || LABEL[e.category] || e.category)), status)),
        payee,
        h('td', { 'data-label': 'Reference' }, strike(e.reference || '—')),
        h('td', { class: 'num', 'data-label': 'Amount' }, strike(money(e.amount))),
        h('td', { 'data-label': 'Recorded by', text: (e.recorded_by && e.recorded_by.name) || '—' }),
        owner ? h('td', { 'data-label': 'Action' }, voided ? null : h('button', {
          type: 'button', class: 'btn btn-ghost btn-sm action-destructive', text: 'Void',
          'aria-label': 'Void expense of ' + money(e.amount) + ' on ' + fmtDate(e.spent_on),
          onclick: () => openVoid(e)
        })) : null);
    });

    box.replaceChildren(...[
      h('div', { class: 'exp-table-wrap' },
        h('table', { class: 'clean exp-table' },
          h('caption', { class: 'visually-hidden', text: 'Expenses for the selected period' }),
          h('thead', null, head), h('tbody', null, rows))),
      state.list.length >= LIST_CAP ? h('p', { class: 'exp-foot', text: 'Showing the latest ' + LIST_CAP + ' expenses. Narrow the dates to see the rest.' }) : null].filter(Boolean));
  }

  // ---- load ---------------------------------------------------------------

  function query() {
    const q = {};
    const from = $('expFrom').value, to = $('expTo').value, category = $('expCategory').value;
    if (from) q.from = from;
    if (to) q.to = to;
    if (category) q.category = category;
    return q;
  }

  async function refresh() {
    const seq = ++state.seq;
    state.loading = true;
    try {
      const data = await API.expenses(query());
      if (seq !== state.seq) return;
      state.list = data.expenses || [];
      state.totals = data.totals || null;
      state.range = data.range || null;
      state.loaded = true;
      state.error = null;
      // First load has no dates: adopt the server's default (this month).
      if (state.range) {
        if (!$('expFrom').value) $('expFrom').value = state.range.from;
        if (!$('expTo').value) $('expTo').value = state.range.to;
      }
      announce(state.list.length + ' expenses, total ' + (state.totals ? money(state.totals.total) : ''));
    } catch (error) {
      if (seq !== state.seq) return;
      state.error = errorText(error, 'Please try again.');
      // Keep stale rows on screen during a background refresh failure.
      if (!state.loaded) announce('Expenses could not be loaded.');
    } finally {
      if (seq === state.seq) state.loading = false;
    }
    renderStats();
    renderList();
  }

  async function apply() {
    const from = $('expFrom').value, to = $('expTo').value;
    if (from && to && from > to) {
      announce('The start date must be on or before the end date.');
      $('expFrom').setCustomValidity('Start date is after the end date');
      $('expFrom').reportValidity();
      $('expFrom').setCustomValidity('');
      return;
    }
    state.loaded = false;
    state.error = null;
    renderStats();
    renderList();
    await refresh();
  }

  // ---- record form --------------------------------------------------------

  // One submission key per distinct entry. The same key is resent after a
  // network error, timeout or 5xx so an expense that did land is not recorded
  // twice. A new key is made only after success, a 422, or when the entry
  // changes. Mirrored to sessionStorage so a reload can still retry safely.
  const Attempt = {
    get() { try { return JSON.parse(sessionStorage.getItem(KEY_STORE) || 'null'); } catch (e) { return this.mem || null; } },
    set(value) { this.mem = value; try { sessionStorage.setItem(KEY_STORE, JSON.stringify(value)); } catch (e) { /* memory only */ } },
    clear() { this.mem = null; try { sessionStorage.removeItem(KEY_STORE); } catch (e) { /* memory only */ } },
    keyFor(signature) {
      const current = this.get() || this.mem;
      if (current && current.signature === signature) return current.key;
      const next = { signature, key: newSubmissionKey() };
      this.set(next);
      return next.key;
    }
  };

  function toggleForm(open) {
    const want = typeof open === 'boolean' ? open : !state.formOpen;
    state.formOpen = want;
    const card = $('expFormCard');
    $('expNewBtn').setAttribute('aria-expanded', want ? 'true' : 'false');
    if (!want) { card.hidden = true; card.replaceChildren(); return; }
    renderForm();
    card.hidden = false;
    const first = $('expAmount');
    if (first) first.focus();
  }

  function renderForm() {
    const card = $('expFormCard');
    const field = (id, label, control, wide, hint) => h('div', { class: 'exp-field' + (wide ? ' is-wide' : '') },
      h('label', { for: id, text: label }), control, hint ? h('p', { class: 'exp-hint', text: hint }) : null);

    const today = todayYmd();
    const minDate = (() => { const d = new Date(); d.setDate(d.getDate() - 366); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); })();
    const submit = h('button', { type: 'submit', class: 'btn btn-primary btn-sm', id: 'expSaveBtn', text: 'Save expense' });
    const form = h('form', { class: 'exp-form', novalidate: true, 'aria-labelledby': 'expFormTitle' },
      h('div', { class: 'exp-msg', id: 'expFormMsg', role: 'status', 'aria-live': 'polite' }),
      h('div', { class: 'exp-form-grid' },
        field('expDate', 'Date', h('input', { id: 'expDate', class: 'form-input', type: 'date', value: today, max: today, min: minDate, required: true })),
        field('expCat', 'Category', h('select', { id: 'expCat', class: 'form-input', required: true },
          h('option', { value: '', text: 'Choose a category' }), CATEGORIES.map(([k, l]) => h('option', { value: k, text: l })))),
        field('expAmount', 'Amount', h('div', { class: 'exp-money' }, h('span', { class: 'exp-money__sym', 'aria-hidden': 'true', text: '₱' }),
          h('input', { id: 'expAmount', class: 'form-input', type: 'number', min: '0.01', step: '0.01', inputmode: 'decimal', placeholder: '0.00', required: true }))),
        field('expPayee', 'Payee', h('input', { id: 'expPayee', class: 'form-input', type: 'text', maxlength: 100, autocomplete: 'off' })),
        field('expRef', 'Reference', h('input', { id: 'expRef', class: 'form-input', type: 'text', maxlength: 60, autocomplete: 'off', placeholder: 'Invoice or receipt number' })),
        field('expNotes', 'Notes', h('textarea', { id: 'expNotes', class: 'form-input', maxlength: 500, rows: 2 }), true)),
      h('div', { class: 'exp-actions' }, submit,
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm', text: 'Cancel', onclick: () => { if (!state.busy) toggleForm(false); } })));
    form.addEventListener('submit', event => { event.preventDefault(); submitForm(submit); });
    card.replaceChildren(h('h3', { class: 'exp-form-title', id: 'expFormTitle', text: 'Record expense' }), form);
  }

  function readForm() {
    return {
      spent_on: $('expDate').value,
      category: $('expCat').value,
      amount: $('expAmount').value.trim(),
      payee: $('expPayee').value.trim(),
      reference: $('expRef').value.trim(),
      notes: $('expNotes').value.trim()
    };
  }

  function validate(v) {
    const problems = [];
    if (!/^\d{4}-\d{2}-\d{2}$/.test(v.spent_on)) problems.push('Choose the date.');
    else if (v.spent_on > todayYmd()) problems.push('The date cannot be in the future.');
    if (!v.category) problems.push('Choose a category.');
    if (!/^\d+(\.\d{1,2})?$/.test(v.amount) || Number(v.amount) <= 0) problems.push('Enter an amount above zero, with at most 2 decimals.');
    return problems;
  }

  async function submitForm(button) {
    if (state.busy) return;
    const v = readForm();
    const problems = validate(v);
    if (problems.length) { setMsg('expFormMsg', problems.join(' '), 'error'); return; }

    state.busy = true;
    button.disabled = true;
    button.textContent = 'Saving…';
    setMsg('expFormMsg', '', '');
    const key = Attempt.keyFor(JSON.stringify(v));
    try {
      await API.createExpense({ submission_key: key, spent_on: v.spent_on, category: v.category, amount: v.amount,
        payee: v.payee || null, reference: v.reference || null, notes: v.notes || null });
    } catch (error) {
      state.busy = false;
      button.disabled = false;
      if (isUncertain(error)) {
        button.textContent = 'Retry';
        setMsg('expFormMsg', 'We could not confirm whether this expense was saved (' + errorText(error, 'no answer from the server') + '). Press Retry. The same request is sent again, so it is not recorded twice.', 'warn');
      } else {
        Attempt.clear();
        button.textContent = 'Save expense';
        setMsg('expFormMsg', errorText(error, 'The expense could not be saved.'), 'error');
      }
      return;
    }
    Attempt.clear();
    state.busy = false;
    toggleForm(false);
    announce('Expense recorded.');
    // Make sure the new row is inside the shown period.
    if ($('expTo').value && v.spent_on > $('expTo').value) $('expTo').value = v.spent_on;
    if ($('expFrom').value && v.spent_on < $('expFrom').value) $('expFrom').value = v.spent_on;
    if ($('expCategory').value && $('expCategory').value !== v.category) $('expCategory').value = '';
    await refresh();
  }

  // ---- void ---------------------------------------------------------------

  function closeVoid() {
    state.voidId = null;
    const card = $('expVoidCard');
    card.hidden = true;
    card.replaceChildren();
  }

  function openVoid(expense) {
    if (!isOwner() || state.busy) return;
    state.voidId = expense.id;
    const card = $('expVoidCard');
    const reason = h('input', { id: 'expVoidReason', class: 'form-input', type: 'text', maxlength: 255, minlength: 3, autocomplete: 'off', required: true });
    const submit = h('button', { type: 'submit', class: 'btn btn-primary btn-sm', text: 'Void expense' });
    const form = h('form', { class: 'exp-form', novalidate: true },
      h('p', { class: 'cell-sub', text: 'Voiding keeps the row on the list, struck through, and removes it from the totals. This cannot be undone.' }),
      h('div', { class: 'exp-msg', id: 'expVoidMsg', role: 'status', 'aria-live': 'polite' }),
      h('div', { class: 'exp-field' }, h('label', { for: 'expVoidReason', text: 'Reason (3 to 255 characters)' }), reason),
      h('div', { class: 'exp-actions' }, submit,
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm', text: 'Cancel', onclick: () => { if (!state.busy) closeVoid(); } })));
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (state.busy) return;
      const text = reason.value.trim();
      if (text.length < 3 || text.length > 255) { setMsg('expVoidMsg', 'Enter a reason between 3 and 255 characters.', 'error'); return; }
      state.busy = true;
      submit.disabled = true;
      submit.textContent = 'Voiding…';
      try {
        await API.voidExpense(expense.id, text);
      } catch (error) {
        state.busy = false;
        submit.disabled = false;
        submit.textContent = 'Void expense';
        setMsg('expVoidMsg', isUncertain(error)
          ? 'We could not confirm the void (' + errorText(error, 'no answer from the server') + '). Press the button to check again; an expense can only be voided once.'
          : errorText(error, 'The expense could not be voided.'), isUncertain(error) ? 'warn' : 'error');
        return;
      }
      state.busy = false;
      closeVoid();
      announce('Expense voided.');
      await refresh();
    });
    card.replaceChildren(h('h3', { class: 'exp-form-title', text: 'Void ' + money(expense.amount) + ' · ' + (expense.category_label || '') + ' · ' + fmtDate(expense.spent_on) }), form);
    card.hidden = false;
    reason.focus();
  }

  // ---- export -------------------------------------------------------------

  function exportCsv() {
    if (!state.list.length) return;
    const header = ['Date', 'Category', 'Amount', 'Payee', 'Reference', 'Notes', 'Recorded by', 'Status', 'Void reason'];
    const lines = state.list.map(e => [
      e.spent_on, e.category_label || e.category, Number(e.amount), e.payee || '', e.reference || '', e.notes || '',
      (e.recorded_by && e.recorded_by.name) || '', e.voided_at ? 'Voided' : 'Recorded', e.void_reason || ''
    ].map(csvCell).join(','));
    const from = (state.range && state.range.from) || 'all';
    const to = (state.range && state.range.to) || 'all';
    downloadCSV([header.join(','), ...lines].join('\n'), 'expenses-' + from + '-to-' + to + '.csv');
  }

  // ---- init ---------------------------------------------------------------

  function init() {
    const select = $('expCategory');
    select.replaceChildren(h('option', { value: '', text: 'All categories' }), ...CATEGORIES.map(([k, l]) => h('option', { value: k, text: l })));
    $('expApplyBtn').addEventListener('click', apply);
    ['expFrom', 'expTo', 'expCategory'].forEach(id => $(id).addEventListener('change', apply));
    renderStats();
    renderList();
    return refresh();
  }

  return { init, refresh, toggleForm, exportCsv };
})();
