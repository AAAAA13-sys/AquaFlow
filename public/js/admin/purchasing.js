// AquaFlow - Purchase orders page (admin/purchasing).
//
// Lists purchase orders, builds drafts (optionally from reorder suggestions),
// marks them ordered, records deliveries and cancels. The server owns every
// rule (status changes, role checks, totals, idempotency); this file only
// gives feedback sooner and hides actions the signed-in role cannot use.
//
// Dynamic content is built with DOM nodes and textContent, never innerHTML.

const PO = (() => {
  const STATUS_LABEL = {
    draft: 'Draft',
    ordered: 'Ordered',
    partially_received: 'Part received',
    received: 'Received',
    cancelled: 'Cancelled'
  };
  const TABS = [
    { id: 'draft', label: 'Draft', match: ['draft'] },
    { id: 'ordered', label: 'Ordered', match: ['ordered', 'partially_received'] },
    { id: 'received', label: 'Received', match: ['received'] },
    { id: 'cancelled', label: 'Cancelled', match: ['cancelled'] },
    { id: 'all', label: 'All', match: null }
  ];
  const LIST_CAP = 200;
  const RECEIVE_KEY_STORE = 'aquaflow_po_receive';

  const state = {
    list: [],
    loaded: false,
    loading: false,
    error: null,
    tab: 'all',
    suggestions: null,
    suggestionsError: null,
    listSeq: 0
  };
  // Drawer state: which view is showing and whether a write is in flight.
  const view = { mode: null, id: null, po: null, form: null, dirty: false, busy: false };

  // ---- small helpers ------------------------------------------------------

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

  function meter(value, threshold, target, markName) {
    const m = levelMeterState(value, threshold, target, markName);
    return m ? h('span', { class: 'level-meter' + (m.empty ? ' is-empty' : m.low ? ' is-low' : ''), role: 'img', 'aria-label': m.label },
      h('span', { class: 'level-meter__fill', style: '--level:' + m.level }),
      h('span', { class: 'level-meter__mark', style: '--mark:' + m.mark })) : null;
  }

  const $ = id => document.getElementById(id);

  function unitMoney(value) {
    const n = Number(value) || 0;
    return Math.abs(n * 100 - Math.round(n * 100)) > 1e-6
      ? '₱' + n.toLocaleString(undefined, { minimumFractionDigits: 4, maximumFractionDigits: 4 })
      : money(n);
  }

  function fmtDate(ymd) {
    if (!ymd) return '—';
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd);
    if (!m) return ymd;
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return Number(m[3]) + ' ' + months[Number(m[2]) - 1] + ' ' + m[1];
  }

  function fmtStamp(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d) ? '—' : d.toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' });
  }

  function pill(status) {
    return h('span', { class: 'state-pill', 'data-state': status, text: STATUS_LABEL[status] || status });
  }

  function isOwner() {
    const s = typeof getSession === 'function' ? getSession() : null;
    return !!s && s.role === 'super_admin';
  }

  // The session holds no user id, so a manager's own-draft check compares
  // display names. It only decides whether to show the button; the server
  // still refuses a cancel by anyone but the creator or the owner.
  function canCancel(po) {
    if (po.status === 'draft') {
      const s = getSession();
      return isOwner() || !!(s && po.created_by && po.created_by.name && po.created_by.name === s.name);
    }
    if (po.status === 'ordered' || po.status === 'partially_received') return isOwner();
    return false;
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
    const node = $('poStatus');
    if (node) node.textContent = message;
  }

  // ---- list ---------------------------------------------------------------

  function matches(po, tab) {
    const def = TABS.find(t => t.id === tab);
    return !def || !def.match || def.match.includes(po.status);
  }

  function renderStats() {
    const box = $('poStats');
    if (!box) return;
    box.replaceChildren();
    const loading = !state.loaded && !state.error;
    const open = state.list.filter(p => p.status === 'ordered' || p.status === 'partially_received');
    const drafts = state.list.filter(p => p.status === 'draft');
    const low = state.suggestions ? state.suggestions.length : null;

    box.textContent = state.error ? 'Purchase orders are unavailable.' : loading ? 'Loading purchase orders…' :
      open.length + (open.length === 1 ? ' order awaits' : ' orders await') + ' delivery; ' + drafts.length + (drafts.length === 1 ? ' draft.' : ' drafts.') +
      (low === null ? (state.suggestionsError ? ' Stock suggestions are unavailable.' : '') : ' ' + low + (low === 1 ? ' item needs' : ' items need') + ' restocking.');
    if (low) box.append(h('button', { type: 'button', class: 'po-linkbtn', text: ' See suggestions', onclick: openSuggestions }));
  }

  function renderTabs() {
    const box = $('poTabs');
    if (!box) return;
    box.replaceChildren(...TABS.map(t => {
      const count = state.list.filter(p => matches(p, t.id)).length;
      return h('button', {
        type: 'button',
        class: 'filter-tab' + (state.tab === t.id ? ' is-active' : ''),
        'aria-pressed': state.tab === t.id ? 'true' : 'false',
        onclick: () => { state.tab = t.id; renderTabs(); renderList(); }
      }, t.label, state.loaded ? h('span', { class: 'po-tab-count', text: String(count) }) : null);
    }));
  }

  function listSkeleton() {
    return h('div', { class: 'po-skeleton', 'aria-hidden': 'true' },
      [1, 2, 3, 4].map(() => h('span', { class: 'skeleton skeleton--text' })));
  }

  function renderList() {
    const box = $('poList');
    if (!box) return;
    box.setAttribute('aria-busy', state.loading && !state.loaded ? 'true' : 'false');

    if (!state.loaded) {
      if (state.error) {
        box.replaceChildren(h('div', { class: 'error-state', role: 'alert' },
          h('p', { class: 'error-state__message', text: 'Purchase orders could not be loaded. ' + state.error }),
          h('button', { type: 'button', class: 'btn btn-secondary btn-sm error-state__retry', text: 'Retry', onclick: () => refresh() })));
      } else {
        box.replaceChildren(listSkeleton());
      }
      return;
    }

    const rows = state.list.filter(p => matches(p, state.tab));
    if (!rows.length) {
      const tabLabel = (TABS.find(t => t.id === state.tab) || {}).label || '';
      const none = state.tab === 'all';
      box.replaceChildren(h('div', { class: 'empty-state' },
        h('p', { class: 'empty-state__title', text: none ? 'No purchase orders yet' : 'No ' + tabLabel.toLowerCase() + ' purchase orders' }),
        h('p', { class: 'empty-state__body', text: none ? 'Create one from scratch or start from the items that are running low.' : 'Orders with this status will show up here.' }),
        none ? h('button', { type: 'button', class: 'btn btn-secondary empty-state__action', text: 'Start from reorder suggestions', onclick: openSuggestions }) : null));
      return;
    }

    const head = h('tr', null,
      h('th', { scope: 'col', text: 'PO number' }),
      h('th', { scope: 'col', text: 'Supplier' }),
      h('th', { scope: 'col', text: 'Status' }),
      h('th', { scope: 'col', class: 'num', text: 'Lines' }),
      h('th', { scope: 'col', class: 'num', text: 'Ordered value' }),
      h('th', { scope: 'col', text: 'Expected' }));

    const body = rows.map(po => {
      const tr = h('tr', { class: 'po-row', 'data-state': po.status, 'data-po-id': po.id });
      const open = () => openDetail(po.id);
      tr.addEventListener('click', event => { if (!event.target.closest('button')) open(); });
      tr.append(
        h('td', { class: 'po-num', 'data-label': 'PO number' },
          h('button', { type: 'button', class: 'po-linkbtn po-open', text: po.po_number, 'aria-label': 'Open ' + po.po_number, onclick: open })),
        h('td', { 'data-label': 'Supplier', text: (po.supplier && po.supplier.name) || '—' }),
        h('td', { 'data-label': 'Status' }, pill(po.status)),
        h('td', { class: 'num', 'data-label': 'Lines', text: String(po.lines.length) }),
        h('td', { class: 'num', 'data-label': 'Ordered value', text: money(po.total_cost) }),
        h('td', { 'data-label': 'Expected', text: fmtDate(po.expected_date) }));
      return tr;
    });

    const nodes = [h('table', { class: 'clean po-table' },
      h('caption', { class: 'visually-hidden', text: 'Purchase orders' }),
      h('thead', null, head), h('tbody', null, body))];
    if (state.list.length >= LIST_CAP) {
      nodes.push(h('p', { class: 'po-foot', text: 'Showing the latest ' + LIST_CAP + ' purchase orders.' }));
    }
    box.replaceChildren(...nodes);
  }

  function renderAll() {
    renderStats();
    renderTabs();
    renderList();
  }

  // Loads the list. A newer call makes an older, slower response irrelevant.
  async function loadList() {
    const seq = ++state.listSeq;
    state.loading = true;
    try {
      const data = await API.purchaseOrders();
      if (seq !== state.listSeq) return;
      state.list = Array.isArray(data.purchase_orders) ? data.purchase_orders : [];
      state.loaded = true;
      state.error = null;
    } catch (error) {
      if (seq !== state.listSeq) return;
      // Keep showing the last good list on a failed background refresh.
      if (!state.loaded) state.error = error.message || 'Please try again.';
      else console.warn('Purchase order refresh failed: ' + error.message);
      throw error;
    } finally {
      if (seq === state.listSeq) { state.loading = false; renderAll(); }
    }
  }

  async function loadSuggestions() {
    try {
      const data = await API.purchaseOrderSuggestions();
      state.suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
      state.suggestionsError = null;
      state.suggestions.forEach(s => {
        if (s.last_unit_cost !== null && s.last_unit_cost !== undefined) lastCost[s.inventory_item_id] = s.last_unit_cost;
      });
    } catch (error) {
      state.suggestionsError = error.message || 'failed';
      throw error;
    } finally {
      renderStats();
    }
  }
  const lastCost = {};

  async function refresh() {
    if (!state.loaded) state.error = null;
    renderList();
    const results = await Promise.allSettled([loadList(), loadSuggestions()]);
    const failed = results.find(r => r.status === 'rejected');
    if (failed && !state.loaded) return;
    if (state.loaded) announce(state.list.length + ' purchase orders');
  }

  function replaceInList(po) {
    const at = state.list.findIndex(p => p.id === po.id);
    if (at >= 0) state.list[at] = po; else state.list.unshift(po);
    renderAll();
  }

  // ---- drawer shell -------------------------------------------------------

  function drawerEl() { return $('poDrawer'); }

  function show(mode, title, nodes) {
    view.mode = mode;
    const drawer = drawerEl();
    const heading = $('poDrawerTitle');
    if (heading) heading.textContent = title;
    $('poBody').replaceChildren(h('div', { class: 'po-msg', id: 'poMsg', role: 'status', 'aria-live': 'polite' }), ...nodes.filter(Boolean));
    drawer.classList.remove('hidden');
    if (!drawer.contains(document.activeElement)) drawer.focus();
    drawer.scrollTop = 0;
  }

  function setMsg(text, kind, action) {
    const box = $('poMsg');
    if (!box) return;
    box.replaceChildren();
    box.className = 'po-msg' + (text ? ' po-msg--' + (kind || 'info') : '');
    box.setAttribute('role', kind === 'error' || kind === 'warn' ? 'alert' : 'status');
    if (!text) return;
    box.append(h('span', { text }));
    if (action) box.append(h('button', { type: 'button', class: 'btn btn-secondary btn-sm', text: action.label, onclick: action.run }));
    box.scrollIntoView({ block: 'nearest' });
  }

  async function closeDrawer() {
    if (view.busy) return;
    if (view.dirty && !(await appConfirm('Discard your unsaved changes?', 'Discard'))) return;
    view.dirty = false;
    view.mode = null;
    view.form = null;
    drawerEl().classList.add('hidden');
  }

  function busyButton(button, pending, label, busyLabel) {
    button.disabled = pending;
    button.setAttribute('aria-disabled', pending ? 'true' : 'false');
    button.textContent = pending ? busyLabel : label;
  }

  // ---- detail -------------------------------------------------------------

  async function openDetail(id) {
    if (view.busy) return;
    if (view.dirty && !(await appConfirm('Discard your unsaved changes?', 'Discard'))) return;
    view.dirty = false;
    view.id = id;
    const cached = state.list.find(p => p.id === id);
    show('detail', cached ? cached.po_number : 'Purchase order', [h('p', { class: 'cell-sub', text: 'Loading…' })]);
    try {
      const data = await API.purchaseOrder(id);
      if (view.id !== id || view.mode !== 'detail') return;
      replaceInList(data.purchase_order);
      renderDetail(data.purchase_order);
    } catch (error) {
      if (view.id !== id || view.mode !== 'detail') return;
      show('detail', cached ? cached.po_number : 'Purchase order', [
        h('div', { class: 'error-state', role: 'alert' },
          h('p', { class: 'error-state__message', text: 'This purchase order could not be loaded. ' + errorText(error, '') }),
          h('button', { type: 'button', class: 'btn btn-secondary btn-sm error-state__retry', text: 'Retry', onclick: () => openDetail(id) }))
      ]);
    }
  }

  function linesTable(po) {
    const head = h('tr', null,
      h('th', { scope: 'col', text: 'Item' }),
      h('th', { scope: 'col', class: 'num', text: 'Ordered' }),
      h('th', { scope: 'col', class: 'num', text: 'Received' }),
      h('th', { scope: 'col', class: 'num', text: 'Left' }),
      h('th', { scope: 'col', class: 'num', text: 'Unit cost' }),
      h('th', { scope: 'col', class: 'num', text: 'Line total' }));
    const rows = po.lines.map(l => h('tr', null,
      h('td', null, h('span', { class: 'po-item', text: l.item_name || 'Item no longer available' }), l.unit ? h('span', { class: 'cell-sub', text: ' ' + l.unit }) : null),
      h('td', { class: 'num', text: String(l.qty_ordered) }),
      h('td', { class: 'num' }, String(l.qty_received), meter(l.qty_received, l.qty_ordered, l.qty_ordered, 'ordered')),
      h('td', { class: 'num', text: String(l.qty_remaining) }),
      h('td', { class: 'num', text: unitMoney(l.unit_cost) }),
      h('td', { class: 'num', text: money(l.line_total) })));
    return h('div', { class: 'po-lines-wrap' }, h('table', { class: 'clean po-detail-lines' },
      h('caption', { class: 'visually-hidden', text: 'Lines on ' + po.po_number }),
      h('thead', null, head), h('tbody', null, rows),
      h('tfoot', null, h('tr', null,
        h('th', { scope: 'row', colspan: 5, class: 'num', text: 'Ordered value' }),
        h('td', { class: 'num po-total', text: money(po.total_cost) })))));
  }

  function renderDetail(po, banner) {
    view.po = po;
    view.id = po.id;
    const meta = (label, value) => [h('dt', { text: label }), h('dd', null, value)];
    const dl = h('dl', { class: 'po-meta' },
      meta('Status', pill(po.status)),
      meta('Supplier', (po.supplier && po.supplier.name) || '—'),
      meta('Expected', fmtDate(po.expected_date)),
      meta('Created by', (po.created_by && po.created_by.name) || '—'),
      po.ordered_at ? meta('Ordered', fmtStamp(po.ordered_at)) : null,
      po.received_at ? meta('Fully received', fmtStamp(po.received_at)) : null,
      po.cancelled_at ? meta('Cancelled', fmtStamp(po.cancelled_at)) : null,
      po.cancel_reason ? meta('Cancel reason', po.cancel_reason) : null,
      po.notes ? meta('Notes', po.notes) : null);

    const actions = h('div', { class: 'drawer-actions po-actions' });
    const open = po.status === 'ordered' || po.status === 'partially_received';
    if (po.status === 'draft') {
      actions.append(
        h('button', { type: 'button', class: 'btn btn-primary btn-sm', id: 'poOrderBtn', text: 'Mark as ordered', onclick: () => markOrdered(po) }),
        h('button', { type: 'button', class: 'btn btn-secondary btn-sm', text: 'Edit draft', onclick: () => openEdit(po) }));
    }
    if (open) {
      actions.append(h('button', { type: 'button', class: 'btn btn-primary btn-sm', id: 'poReceiveBtn', text: 'Receive stock', onclick: () => openReceive(po) }));
    }
    if (canCancel(po)) {
      actions.append(h('button', { type: 'button', class: 'btn btn-ghost btn-sm action-destructive', id: 'poCancelBtn', text: po.status === 'draft' ? 'Cancel draft' : 'Cancel order', onclick: () => cancelPo(po) }));
    }
    const hint = (open && !isOwner()) ? h('p', { class: 'cell-sub', text: 'Only the owner can cancel an order that has been placed.' })
      : (po.status === 'draft' && !canCancel(po)) ? h('p', { class: 'cell-sub', text: 'Only the person who created this draft, or the owner, can cancel it.' }) : null;

    show('detail', po.po_number, [dl, linesTable(po), actions.children.length ? actions : null, hint]);
    if (banner) setMsg(banner.text, banner.kind, banner.action);
  }

  async function markOrdered(po) {
    if (view.busy) return;
    if (!(await appConfirm('Mark ' + po.po_number + ' as ordered? Its lines can no longer be edited after this.', 'Mark as ordered'))) return;
    const btn = $('poOrderBtn');
    view.busy = true;
    if (btn) busyButton(btn, true, 'Mark as ordered', 'Marking…');
    try {
      const data = await API.markPurchaseOrderOrdered(po.id);
      view.busy = false;
      replaceInList(data.purchase_order);
      renderDetail(data.purchase_order, { text: po.po_number + ' is now ordered.', kind: 'ok' });
    } catch (error) {
      view.busy = false;
      if (btn) busyButton(btn, false, 'Mark as ordered', '');
      setMsg(errorText(error, 'Could not mark the order as ordered.'), 'error');
      if (error.status === 422) reloadDetailQuietly(po.id);
    }
  }

  // After a refused write the order may have changed elsewhere; show the truth.
  async function reloadDetailQuietly(id) {
    try {
      const data = await API.purchaseOrder(id);
      replaceInList(data.purchase_order);
      view.po = data.purchase_order;
    } catch (error) { /* the visible error message already explains the failure */ }
  }

  async function cancelPo(po) {
    if (view.busy) return;
    const reason = await appPrompt('Why is ' + po.po_number + ' being cancelled? (3 to 255 characters)', 'Reason', 'Cancel order');
    if (reason === null) return;
    if (reason.length < 3 || reason.length > 255) {
      setMsg('Enter a reason between 3 and 255 characters.', 'error');
      return;
    }
    const btn = $('poCancelBtn');
    view.busy = true;
    if (btn) busyButton(btn, true, '', 'Cancelling…');
    try {
      const data = await API.cancelPurchaseOrder(po.id, reason);
      view.busy = false;
      replaceInList(data.purchase_order);
      renderDetail(data.purchase_order, { text: po.po_number + ' was cancelled. Stock that already arrived stays on hand.', kind: 'ok' });
    } catch (error) {
      view.busy = false;
      if (btn) busyButton(btn, false, po.status === 'draft' ? 'Cancel draft' : 'Cancel order', '');
      setMsg(errorText(error, 'Could not cancel the order.'), 'error');
      if (error.status === 422) reloadDetailQuietly(po.id);
    }
  }

  // ---- create / edit form -------------------------------------------------

  function blankLine() { return { inventory_item_id: '', qty: '', unit_cost: '' }; }

  function openCreate(prefill) {
    startForm({
      id: null,
      supplier_id: prefill && prefill.supplier_id ? String(prefill.supplier_id) : '',
      expected_date: '',
      notes: '',
      lines: prefill && prefill.lines && prefill.lines.length ? prefill.lines : [blankLine()]
    }, 'New purchase order');
  }

  function openEdit(po) {
    startForm({
      id: po.id,
      po_number: po.po_number,
      supplier_id: String(po.supplier.id || ''),
      expected_date: po.expected_date || '',
      notes: po.notes || '',
      lines: po.lines.map(l => ({
        inventory_item_id: String(l.inventory_item_id), qty: String(l.qty_ordered), unit_cost: String(l.unit_cost),
        fallback_name: l.item_name
      }))
    }, 'Edit ' + po.po_number);
  }

  async function startForm(form, title) {
    if (view.busy) return;
    if (view.dirty && !(await appConfirm('Discard your unsaved changes?', 'Discard'))) return;
    view.form = form;
    view.dirty = false;
    renderForm(title);
  }

  function itemOptions(selected, fallbackName) {
    const items = Array.isArray(DB.inventory) ? DB.inventory : [];
    const opts = [h('option', { value: '', text: 'Choose an item' })];
    let found = false;
    items.slice().sort((a, b) => String(a.item).localeCompare(String(b.item))).forEach(i => {
      if (String(i.id) === String(selected)) found = true;
      opts.push(h('option', { value: String(i.id), text: i.item + (i.unit ? ' (' + i.unit + ')' : '') }));
    });
    if (selected && !found) opts.push(h('option', { value: String(selected), text: (fallbackName || 'Item #' + selected) + ' (unavailable)' }));
    return opts;
  }

  function formTotal(form) {
    return form.lines.reduce((sum, l) => {
      const q = Number(l.qty), c = Number(l.unit_cost);
      return sum + (Number.isFinite(q) && Number.isFinite(c) ? q * c : 0);
    }, 0);
  }

  function renderForm(title) {
    const form = view.form;
    const suppliers = Array.isArray(DB.suppliers) ? DB.suppliers : [];

    const supplierSel = h('select', { id: 'poSupplier', class: 'form-input', required: true },
      h('option', { value: '', text: 'Choose a supplier' }),
      suppliers.map(s => h('option', { value: String(s.id), text: s.name })));
    supplierSel.value = form.supplier_id;
    if (supplierSel.value !== form.supplier_id) supplierSel.value = '';
    supplierSel.addEventListener('change', () => { form.supplier_id = supplierSel.value; view.dirty = true; });

    const dateIn = h('input', { id: 'poExpected', type: 'date', class: 'form-input', value: form.expected_date });
    dateIn.addEventListener('input', () => { form.expected_date = dateIn.value; view.dirty = true; });

    const notesIn = h('textarea', { id: 'poNotes', class: 'form-input', rows: 2, maxlength: 500 });
    notesIn.value = form.notes;
    notesIn.addEventListener('input', () => { form.notes = notesIn.value; view.dirty = true; });

    const linesBox = h('div', { class: 'po-lines', id: 'poLines' });
    const totalOut = h('output', { class: 'po-total', id: 'poFormTotal', 'aria-live': 'polite' });
    const updateTotal = () => { totalOut.textContent = money(formTotal(form)); };

    const drawLines = () => {
      linesBox.replaceChildren(...form.lines.map((line, index) => {
        const item = h('select', { id: 'poItem' + index, class: 'form-input', required: true }, itemOptions(line.inventory_item_id, line.fallback_name));
        item.value = line.inventory_item_id;
        item.addEventListener('change', () => {
          line.inventory_item_id = item.value;
          view.dirty = true;
          if (!line.unit_cost && lastCost[item.value] !== undefined) {
            line.unit_cost = String(lastCost[item.value]);
            cost.value = line.unit_cost;
          }
          updateTotal();
        });
        const qty = h('input', { id: 'poQty' + index, type: 'number', class: 'form-input', min: 1, max: 100000, step: 1, inputmode: 'numeric', required: true, value: line.qty });
        qty.addEventListener('input', () => { line.qty = qty.value; view.dirty = true; updateTotal(); });
        const cost = h('input', { id: 'poCost' + index, type: 'number', class: 'form-input', min: 0, max: 1000000, step: '0.01', inputmode: 'decimal', required: true, value: line.unit_cost });
        cost.addEventListener('input', () => { line.unit_cost = cost.value; view.dirty = true; updateTotal(); });
        const remove = h('button', {
          type: 'button', class: 'btn btn-ghost btn-sm action-destructive po-line-remove', text: 'Remove',
          'aria-label': 'Remove line ' + (index + 1),
          disabled: form.lines.length === 1,
          onclick: () => { form.lines.splice(index, 1); view.dirty = true; drawLines(); updateTotal(); }
        });
        return h('div', { class: 'po-line' },
          h('div', { class: 'po-line__item' }, h('label', { for: 'poItem' + index, text: 'Item' }), item),
          h('div', { class: 'po-line__qty' }, h('label', { for: 'poQty' + index, text: 'Quantity' }), qty),
          h('div', { class: 'po-line__cost' }, h('label', { for: 'poCost' + index, text: 'Unit cost (₱)' }), cost),
          h('div', { class: 'po-line__x' }, remove));
      }));
    };
    drawLines();
    updateTotal();

    const addLine = h('button', {
      type: 'button', class: 'btn btn-secondary btn-sm', text: 'Add another item',
      onclick: () => {
        if (form.lines.length >= 50) { setMsg('An order can have at most 50 lines.', 'error'); return; }
        form.lines.push(blankLine()); view.dirty = true; drawLines();
        const last = $('poItem' + (form.lines.length - 1));
        if (last) last.focus();
      }
    });

    const saveBtn = h('button', { type: 'submit', class: 'btn btn-primary btn-sm', id: 'poSaveBtn', text: form.id ? 'Save changes' : 'Save as draft' });
    const formEl = h('form', { class: 'po-form', novalidate: true, id: 'poForm' },
      h('div', { class: 'po-field' }, h('label', { for: 'poSupplier', text: 'Supplier' }), supplierSel),
      h('div', { class: 'po-field' }, h('label', { for: 'poExpected', text: 'Expected delivery date (optional)' }), dateIn),
      h('div', { class: 'po-field' }, h('label', { for: 'poNotes', text: 'Notes (optional)' }), notesIn),
      h('fieldset', { class: 'po-fieldset' }, h('legend', { text: 'Items to order' }), linesBox,
        h('div', { class: 'po-form-foot' }, addLine, h('p', { class: 'po-total-line' }, 'Ordered value ', totalOut))),
      h('div', { class: 'drawer-actions po-actions' }, saveBtn,
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm', text: 'Cancel', onclick: closeFormToDetail })));
    formEl.addEventListener('submit', event => { event.preventDefault(); saveForm(saveBtn); });

    show('form', title, [formEl]);
  }

  async function closeFormToDetail() {
    if (view.busy) return;
    if (view.dirty && !(await appConfirm('Discard your unsaved changes?', 'Discard'))) return;
    view.dirty = false;
    const id = view.form && view.form.id;
    view.form = null;
    if (id && view.po && view.po.id === id) renderDetail(view.po);
    else { view.mode = null; drawerEl().classList.add('hidden'); }
  }

  function buildPayload(form) {
    const problems = [];
    if (!form.supplier_id) problems.push('Choose a supplier.');
    if (!form.lines.length) problems.push('Add at least one item.');
    const seen = new Set();
    const lines = form.lines.map((l, i) => {
      const n = i + 1;
      const id = Number(l.inventory_item_id);
      const qty = Number(l.qty);
      const cost = Number(l.unit_cost);
      if (!l.inventory_item_id) problems.push('Line ' + n + ': choose an item.');
      else if (seen.has(id)) problems.push('Line ' + n + ': each item can appear only once.');
      seen.add(id);
      if (!Number.isInteger(qty) || qty < 1 || qty > 100000) problems.push('Line ' + n + ': quantity must be a whole number from 1 to 100,000.');
      if (l.unit_cost === '' || !Number.isFinite(cost) || cost < 0 || cost > 1000000) problems.push('Line ' + n + ': enter a unit cost from 0 to 1,000,000.');
      else if (Math.abs(cost * 10000 - Math.round(cost * 10000)) > 1e-6) problems.push('Line ' + n + ': unit cost can have at most 4 decimal places.');
      return { inventory_item_id: id, qty, unit_cost: cost };
    });
    return { problems, payload: { supplier_id: Number(form.supplier_id), expected_date: form.expected_date || null, notes: form.notes.trim() || null, lines } };
  }

  async function saveForm(button) {
    if (view.busy) return;
    const form = view.form;
    const { problems, payload } = buildPayload(form);
    if (problems.length) { setMsg(problems.join(' '), 'error'); return; }
    view.busy = true;
    const label = button.textContent;
    busyButton(button, true, label, 'Saving…');
    try {
      const data = form.id ? await API.updatePurchaseOrder(form.id, payload) : await API.createPurchaseOrder(payload);
      view.busy = false;
      view.dirty = false;
      view.form = null;
      replaceInList(data.purchase_order);
      renderDetail(data.purchase_order, { text: form.id ? 'Draft updated.' : data.purchase_order.po_number + ' was saved as a draft.', kind: 'ok' });
      announce('Purchase order saved');
    } catch (error) {
      view.busy = false;
      busyButton(button, false, label, '');
      let text = errorText(error, 'Could not save the purchase order.');
      if (isUncertain(error) && !form.id) {
        text += ' Check the list before saving again, in case the order was created.';
      }
      setMsg(text, 'error');
    }
  }

  // ---- reorder suggestions ------------------------------------------------

  async function openSuggestions() {
    if (view.busy) return;
    if (view.dirty && !(await appConfirm('Discard your unsaved changes?', 'Discard'))) return;
    view.dirty = false;
    view.form = null;
    show('suggestions', 'Reorder suggestions', [h('p', { class: 'cell-sub', text: 'Checking stock levels…' })]);
    try {
      await loadSuggestions();
    } catch (error) {
      if (view.mode !== 'suggestions') return;
      show('suggestions', 'Reorder suggestions', [
        h('div', { class: 'error-state', role: 'alert' },
          h('p', { class: 'error-state__message', text: 'Reorder suggestions could not be loaded. ' + errorText(error, '') }),
          h('button', { type: 'button', class: 'btn btn-secondary btn-sm error-state__retry', text: 'Retry', onclick: openSuggestions }))]);
      return;
    }
    if (view.mode !== 'suggestions') return;
    renderSuggestions();
  }

  function renderSuggestions() {
    const list = state.suggestions || [];
    if (!list.length) {
      show('suggestions', 'Reorder suggestions', [h('div', { class: 'empty-state' },
        h('p', { class: 'empty-state__title', text: 'Nothing needs reordering' }),
        h('p', { class: 'empty-state__body', text: 'No item is at or below its reorder point.' }),
        h('button', { type: 'button', class: 'btn btn-secondary empty-state__action', text: 'Start a blank order', onclick: () => openCreate() }))]);
      return;
    }
    const groups = new Map();
    list.forEach(s => {
      const key = s.supplier_id === null || s.supplier_id === undefined ? 'none' : String(s.supplier_id);
      if (!groups.has(key)) groups.set(key, { supplier_id: s.supplier_id, name: s.supplier_name || 'No supplier set', items: [] });
      groups.get(key).items.push(s);
    });
    const sections = [h('p', { class: 'cell-sub', text: 'Items at or below their reorder point, grouped by supplier. Each order goes to one supplier, so pick a group to start a draft. You can change every quantity and cost before saving.' })];
    groups.forEach(group => {
      const rows = group.items.map(s => h('tr', null,
        h('td', { text: s.item_name }),
        h('td', { class: 'num' }, s.stock_on_hand + ' / ' + s.reorder_point, meter(s.stock_on_hand, s.reorder_point, Math.max(Number(s.target_stock) || 0, Number(s.reorder_point) * 2))),
        h('td', { class: 'num', text: String(s.suggested_qty) }),
        h('td', { class: 'num', text: s.last_unit_cost === null || s.last_unit_cost === undefined ? 'Not set' : unitMoney(s.last_unit_cost) })));
      sections.push(h('section', { class: 'po-group', 'aria-label': group.name },
        h('div', { class: 'po-group__head' },
          h('h4', { class: 'po-group__title', text: group.name }),
          h('button', {
            type: 'button', class: 'btn btn-primary btn-sm', text: 'Create draft',
            'aria-label': 'Create a draft for ' + group.name,
            onclick: () => openCreate({
              supplier_id: group.supplier_id,
              lines: group.items.map(s => ({
                inventory_item_id: String(s.inventory_item_id), qty: String(s.suggested_qty),
                unit_cost: s.last_unit_cost === null || s.last_unit_cost === undefined ? '' : String(s.last_unit_cost)
              }))
            })
          })),
        h('table', { class: 'clean po-sugg' },
          h('thead', null, h('tr', null,
            h('th', { scope: 'col', text: 'Item' }),
            h('th', { scope: 'col', class: 'num', text: 'On hand / reorder' }),
            h('th', { scope: 'col', class: 'num', text: 'Suggested' }),
            h('th', { scope: 'col', class: 'num', text: 'Last cost' }))),
          h('tbody', null, rows))));
    });
    show('suggestions', 'Reorder suggestions', sections);
  }

  // ---- receive ------------------------------------------------------------

  // One submission key per receive attempt. The same key is resent for the
  // same quantities after a network error, timeout or 5xx, so a delivery that
  // did land is never counted twice. A new key is made only after a success or
  // a 422, or when the quantities change. Mirrored to sessionStorage so a
  // reload during an uncertain attempt can still be retried safely.
  const Attempts = {
    map: null,
    load() {
      if (this.map) return this.map;
      try { this.map = JSON.parse(sessionStorage.getItem(RECEIVE_KEY_STORE) || '{}') || {}; } catch (e) { this.map = {}; }
      return this.map;
    },
    save() { try { sessionStorage.setItem(RECEIVE_KEY_STORE, JSON.stringify(this.map)); } catch (e) { /* memory only */ } },
    keyFor(poId, signature) {
      const map = this.load();
      const current = map[poId];
      if (current && current.signature === signature) return current.key;
      map[poId] = { signature, key: newSubmissionKey() };
      this.save();
      return map[poId].key;
    },
    clear(poId) { const map = this.load(); delete map[poId]; this.save(); }
  };

  function openReceive(po) {
    const lines = po.lines.filter(l => l.qty_remaining > 0);
    if (!lines.length) { setMsg('Every line on this order is already received.', 'info'); return; }
    const inputs = [];
    const rows = lines.map(l => {
      const input = h('input', {
        id: 'poRecv' + l.id, type: 'number', class: 'form-input po-recv-qty', min: 0, max: l.qty_remaining, step: 1,
        inputmode: 'numeric', value: l.qty_remaining, 'aria-label': 'Quantity received for ' + (l.item_name || 'item')
      });
      inputs.push({ line: l, input });
      return h('tr', null,
        h('td', { 'data-label': 'Item' }, h('span', { class: 'po-item', text: l.item_name || 'Item no longer available' }), l.unit ? h('span', { class: 'cell-sub', text: ' ' + l.unit }) : null),
        h('td', { class: 'num', 'data-label': 'Ordered', text: String(l.qty_ordered) }),
        h('td', { class: 'num', 'data-label': 'Already received', text: String(l.qty_received) }),
        h('td', { class: 'num', 'data-label': 'Left', text: String(l.qty_remaining) }),
        h('td', { class: 'num', 'data-label': 'Receiving now' }, input));
    });
    const submit = h('button', { type: 'submit', class: 'btn btn-primary btn-sm', id: 'poRecvBtn', text: 'Record delivery' });
    const form = h('form', { class: 'po-form', novalidate: true },
      h('p', { class: 'cell-sub', text: 'Enter what actually arrived. Stock on hand goes up by these amounts. You can receive the rest later.' }),
      h('div', { class: 'po-lines-wrap' }, h('table', { class: 'clean po-recv' },
        h('caption', { class: 'visually-hidden', text: 'Quantities to receive for ' + po.po_number }),
        h('thead', null, h('tr', null,
          h('th', { scope: 'col', text: 'Item' }), h('th', { scope: 'col', class: 'num', text: 'Ordered' }),
          h('th', { scope: 'col', class: 'num', text: 'Received' }), h('th', { scope: 'col', class: 'num', text: 'Left' }),
          h('th', { scope: 'col', class: 'num', text: 'Receiving now' }))),
        h('tbody', null, rows))),
      h('div', { class: 'drawer-actions po-actions' }, submit,
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm', text: 'Back', onclick: () => { if (!view.busy) renderDetail(po); } })));
    form.addEventListener('submit', event => { event.preventDefault(); submitReceive(po, inputs, submit); });
    view.dirty = false;
    show('receive', 'Receive stock: ' + po.po_number, [form]);
  }

  async function submitReceive(po, inputs, button) {
    if (view.busy) return;
    const lines = [];
    const problems = [];
    inputs.forEach(({ line, input }) => {
      const raw = input.value.trim();
      const qty = raw === '' ? 0 : Number(raw);
      if (!Number.isInteger(qty) || qty < 0) problems.push((line.item_name || 'Item') + ': enter a whole number, 0 or more.');
      else if (qty > line.qty_remaining) problems.push((line.item_name || 'Item') + ': only ' + line.qty_remaining + ' left to receive.');
      else if (qty > 0) lines.push({ line_id: line.id, qty });
    });
    if (!problems.length && !lines.length) problems.push('Enter a received quantity for at least one item.');
    if (problems.length) { setMsg(problems.join(' '), 'error'); return; }

    lines.sort((a, b) => a.line_id - b.line_id);
    const key = Attempts.keyFor(po.id, JSON.stringify(lines));
    view.busy = true;
    busyButton(button, true, 'Record delivery', 'Recording…');
    setMsg('', '');

    let result;
    try {
      result = await API.receivePurchaseOrder(po.id, { submission_key: key, lines });
    } catch (error) {
      view.busy = false;
      if (error.status === 422) {
        Attempts.clear(po.id);
        busyButton(button, false, 'Record delivery', '');
        setMsg(errorText(error, 'The delivery could not be recorded.') + ' Nothing was received. If an item on this order has been retired, its line cannot be received.', 'error');
        reloadDetailQuietly(po.id);
      } else if (isUncertain(error)) {
        busyButton(button, false, 'Retry record delivery', '');
        setMsg('We could not confirm whether this delivery was recorded (' + errorText(error, 'no answer from the server') + '). Press Retry. The same request is sent again, so stock is not counted twice.', 'warn');
      } else {
        busyButton(button, false, 'Record delivery', '');
        setMsg(errorText(error, 'The delivery could not be recorded.'), 'error');
      }
      return;
    }

    // The server committed the receipt. Never show the receive form again for it.
    Attempts.clear(po.id);
    view.busy = false;
    const updated = result.purchase_order;
    replaceInList(updated);
    const total = (result.received || []).reduce((s, r) => s + (Number(r.qty) || 0), 0);
    renderDetail(updated, { text: 'Delivery recorded for ' + updated.po_number + (total ? ' (' + total + ' units added to stock).' : '.'), kind: 'ok' });
    announce('Delivery recorded');
    afterReceiveRefresh(po.id);
  }

  // Re-read the order, the list and the stock snapshot. A failure here is
  // reported as "received, but not refreshed", never as a failed receive.
  async function afterReceiveRefresh(id) {
    const results = await Promise.allSettled([
      API.purchaseOrder(id),
      loadList(),
      typeof loadFromServer === 'function' ? loadFromServer() : Promise.resolve()
    ]);
    const detail = results[0];
    if (detail.status === 'fulfilled' && view.id === id && view.mode === 'detail') {
      replaceInList(detail.value.purchase_order);
      renderDetail(detail.value.purchase_order, { text: 'Delivery recorded. Stock has been updated.', kind: 'ok' });
    }
    if (typeof renderSidebarBadges === 'function') { try { renderSidebarBadges(); } catch (e) { /* cosmetic */ } }
    if (results.some(r => r.status === 'rejected')) {
      if (view.mode === 'detail' && view.id === id) {
        setMsg('The delivery was recorded, but the latest figures could not be refreshed.', 'warn', {
          label: 'Refresh now', run: async () => {
            try { await afterReceiveRefreshOnce(id); } catch (e) { setMsg('Still could not refresh. The delivery is recorded; reload the page to see current figures.', 'warn'); }
          }
        });
      }
    }
  }

  async function afterReceiveRefreshOnce(id) {
    const data = await API.purchaseOrder(id);
    await loadList();
    try { if (typeof loadFromServer === 'function') await loadFromServer(); } catch (e) { /* stock snapshot will refresh on its own */ }
    replaceInList(data.purchase_order);
    renderDetail(data.purchase_order, { text: 'Up to date.', kind: 'ok' });
  }

  // ---- boot ---------------------------------------------------------------

  function init() {
    renderAll();
    return refresh();
  }

  return { init, refresh, openCreate: () => openCreate(), openSuggestions, closeDrawer };
})();
