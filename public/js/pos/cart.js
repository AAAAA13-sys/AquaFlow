// AquaFlow CASHIER terminal - cart, customers and order totals.
//
// Money model: displayed prices already include 12% VAT, so the vatable sales
// and the tax portion are extracted from the gross total. There are no
// discounts.
//
// Payment model (MSME): the order type decides how it is paid. A walk-in pays
// CASH at the counter; a delivery is CHARGED TO THE CUSTOMER'S ACCOUNT. The
// cashier never picks a payment method.
//
// Containers: the station owns no jugs. A refill is the customer's own jug
// coming back filled (no liability, no deposit); a brand-new jug is ordinary
// merchandise that depletes stock once at checkout.

// `var` (not `let`) keeps `typeof POS` safe in the persistence layer no matter
// how the scripts are loaded, including concatenated builds.
// custId starts null: nothing is pre-selected.
var POS = { type: 'Walk-in', cart: [], custId: null, orderN: 1018 };

const VAT_RATE = 0.12;

// Maximum units allowed per order line.
const MAX_QTY_PER_ITEM = 20;

// icon() / SPRITE_URL now live in js/data/store.js so the owner portal can use
// them too — they used to be defined here and the admin pages threw
// "icon is not defined" when rendering the ledger.

// Resume order numbering past any persisted transaction so OR numbers never collide.
(function () {
  restorePOSState();
  const maxOr = DB.transactions.reduce((m, t) => {
    const n = parseInt((String(t.no).match(/\d+/) || [0])[0], 10);
    return Math.max(m, n || 0);
  }, 0);
  POS.orderN = Math.max(POS.orderN || 1018, maxOr + 1);
})();

/** The selected customer, or null when none has been chosen yet. */
function posCust() {
  if (POS.custId === null || POS.custId === undefined) return null;
  return DB.customers.find(c => c.id === POS.custId) || null;
}

function hasCustomer() {
  return posCust() !== null;
}

/** Payment is a consequence of the order type, never a cashier choice. */
function isDelivery() {
  return POS.type === 'Delivery';
}


const peso = n => '₱' + Number(n).toLocaleString();

async function confirmOrderType(type) {
  if (type === POS.type || confirmOrderType.busy) return;
  if (type === 'Delivery' && (!posCust() || posCust().is_guest)) {
    showStageError('Select a registered customer for delivery.'); return;
  }
  confirmOrderType.busy = true;
  try {
    if (await appConfirm('Change this order to ' + type + '?', 'Change order type')) setType(type);
  } finally { confirmOrderType.busy = false; }
}

async function confirmCartAction(message, action) {
  if (confirmCartAction.busy) return;
  confirmCartAction.busy = true;
  try { if (await appConfirm(message, 'Confirm change')) await action(); }
  finally { confirmCartAction.busy = false; }
}

function setType(t) {
  POS.type = t;

  const walk = document.getElementById('bWalk');
  const del = document.getElementById('bDel');
  if (walk) walk.className = 'seg-btn' + (t === 'Walk-in' ? ' active' : '');
  if (del) del.className = 'seg-btn' + (t === 'Delivery' ? ' active' : '');

  // Walk-in-only merchandise cannot ride a delivery, so drop it from the cart.
  if (t === 'Delivery') {
    const blocked = POS.cart.filter(i => i.walkInOnly);
    if (blocked.length) {
      POS.cart = POS.cart.filter(i => !i.walkInOnly);
      appNotice('Removed from the order (walk-in only): ' + blocked.map(i => i.name).join(', '));
    }
  }

  savePOSState();
  renderPOS();
}

// ---------- Customer list ----------

const WALK_IN_NAME = 'Walk-in Guest';

// Debt is the number a cashier scans for, so it gets a real badge rather than
// coloured text. Bands are deliberately simple: settled, outstanding, and a
// "high" tier for the balances worth chasing before they grow.
const DEBT_HIGH = 5000;

function debtBadge(debt) {
  const amount = Number(debt) || 0;

  if (amount <= 0) {
    return '<span class="badge badge-clear">' + icon('i-check-circle') + ' No balance</span>';
  }

  const tone = amount >= DEBT_HIGH ? 'badge-high' : 'badge-warn';
  return '<span class="badge ' + tone + '">' + icon('i-alert') + ' ' + peso(amount) + ' debt</span>';
}

/**
 * One-click fast track for a plain cash sale.
 *
 * The guest customer is a real seeded record, so this only reuses the normal
 * selection path - it does not bypass any server rule. Payment still follows the
 * order type (walk-in => cash), which the API re-derives regardless.
 */
function pickWalkIn() {
  const guest = DB.customers.find(c => c.is_guest === true);
  if (!guest) {
    showStageError('Walk-in setup is incomplete. Ask the owner to run the store update.');
    return;
  }
  pickCust(guest.id);
}

// ---------- Customer search (server-driven) ----------
//
// Stage 1 used to filter the entire customer snapshot in the browser, which grew
// with the store and dumped up to 40 rows on screen. It is now a debounced server
// query. The page matches the server maximum so both orderings list every
// customer available. Results are merged back into the snapshot so the
// existing id-based lookups (posCust/pickCust) keep working untouched.

const CUST_PAGE_SIZE = 50;
let custResults = null;   // null = nothing searched yet
let custSeq = 0;          // stale-response guard
let custTimer = null;
let custFailed = false;

/** Debounced entry point - wired to the search box's input event. */
function queueCustSearch() {
  clearTimeout(custTimer);
  custTimer = setTimeout(searchCustomers, 220);
}

async function searchCustomers() {
  const search = document.getElementById('custSearch');
  const seq = ++custSeq;
  const term = ((search && search.value) || '').trim();
  const order = document.getElementById('customerOrder')?.value || 'oldest';
  const list = document.getElementById('custList');
  if (list) list.innerHTML = '<p class="cashier-empty" role="status">Searching customers…</p>';

  try {
    const data = await API.customers({
      q: term,
      order: order,
      limit: CUST_PAGE_SIZE,
      // The system guest anchors walk-in sales; it is not a person to search for
      // and stage 1 has a dedicated Quick Walk-In button.
      exclude_guest: 1
    });

    if (seq !== custSeq) return;   // a newer query has already answered

    const rows = Array.isArray(data.customers) ? data.customers : [];
    rows.forEach(customer => API.replaceCustomer(customer));
    custResults = rows;
    custFailed = false;
  } catch (error) {
    if (seq !== custSeq) return;
    custFailed = true;
    custResults = [];
  }

  saveDB();
  renderCustList();
}

function renderCustList() {
  const search = document.getElementById('custSearch');
  const list = document.getElementById('custList');

  if (list) {
    const term = ((search && search.value) || '').trim();

    if (custFailed) {
      list.innerHTML = '<p class="cashier-empty">Could not load customers. Check the connection and try again.</p>';
    } else if (custResults === null) {
      list.innerHTML = '<p class="cashier-empty">Type a name or address to find a customer.</p>';
    } else if (custResults.length === 0) {
      list.innerHTML = '<p class="cashier-empty">' + (term
        ? 'No customer matches "' + esc(term) + '".'
        : 'No customers yet. Use + New Customer to add one.') + '</p>';
    } else {
      list.innerHTML = custResults.map(c => {
        const sel = c.id === POS.custId;
        const debt = Number(c.debt);

        return '<button type="button" onclick="pickCust(' + c.id + ')" class="customer-option customer-row' + (sel ? ' selected' : '') + '">' +
          '<b class="cust-row-name">' + esc(c.name) + '</b>' +
          '<span class="cust-row-addr">' + esc(c.addr) + '</span>' +
          '<span class="cust-row-stat badge-host">' + debtBadge(debt) + '</span>' +
          '</button>';
      }).join('');
    }
  }

  const card = document.getElementById('custCard');
  if (!card) return;

  const c = posCust();

  if (c === null) {
    card.className = 'active-customer-banner empty';
    card.innerHTML = '<span class="cust-detail">No customer selected. Tap a customer below or choose Walk-in.</span>';
    return;
  }

  const debt = Number(c.debt);

  card.className = 'active-customer-banner';
  card.innerHTML =
    '<b class="cust-detail-name">' + esc(c.name) + '</b>' +
    '<span class="cust-detail">' + esc(c.addr) + '</span>' +
    '<span class="cust-detail ' + (debt > 0 ? 'text-red-600' : 'text-green-700') + '">' +
    (debt > 0 ? icon('i-alert') + ' ' + peso(debt) + ' debt' : icon('i-check-circle') + ' No balance') + '</span>';
}

async function pickCust(id) {
  if (POS.custId === id) return true;
  if (typeof Submissions !== 'undefined' && Submissions.pending()) {
    showStageError('Recover the pending receipt before changing customers.'); return false;
  }
  if (POS.cart.length && !await appConfirm('Discard the current order and switch customers?', 'Discard and switch')) return false;
  if (!POS.cart.length && !await appConfirm('Select ' + (DB.customers.find(c => c.id === id)?.name || 'this customer') + ' for this order?', 'Select customer')) return false;
  POS.cart = []; POS.custId = id; POS.type = 'Walk-in';
  ['tender', 'debtPay'].forEach(key => { const el = document.getElementById(key); if (el) el.value = 0; });
  const receipt = document.getElementById('receipt'); if (receipt) receipt.classList.add('hidden');
  showStageError(null); savePOSState(); renderPOS(); posStep(1);
  return true;
}

// ---------- One-touch product tiles ----------

// Till presentation is derived from the product's category and container kind,
// never from hardcoded ids. The catalog is owner-managed now (Till & Prices), so
// an id this file has never seen must still render a correct, labelled button.
//
// Order is whatever the server sends (products.sort_order). The client does not
// re-sort, so the owner's arrangement is exactly what the cashier sees.

const PRODUCT_THEMES = {
  refill: 'prod-theme-blue',
  container: 'prod-theme-gray',
  consumable: 'prod-theme-yellow',
  cleaning: 'prod-theme-purple'
};

const PRODUCT_SUBTITLES = {
  refill: 'Bring your own jug',
  container: 'For Sale - brand new',
  consumable: 'Supply',
  cleaning: 'Supply'
};

function productTheme(product) {
  // Slim and round refills stay visually distinct.
  if (product.cat === 'refill') return product.kind === 'R' ? 'prod-theme-teal' : 'prod-theme-blue';

  return PRODUCT_THEMES[product.cat] || 'prod-theme-gray';
}

function productSubtitle(product) {
  return PRODUCT_SUBTITLES[product.cat] || 'For Sale';
}

function renderProducts() {
  const grid = document.getElementById('productGrid');
  if (!grid) return;

  const delivering = isDelivery();

  // Server order, no client-side re-sort.
  const items = (DB.products || []).filter(product => product.sold_at_pos !== false);

  grid.innerHTML = items.map(product => {
    const price = Number(product.price).toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });

    const stockLine = '<span class="product-info-stock">' + (stockPreview
      ? 'Available stock: ' + Number((stockPreview.stock && stockPreview.stock[product.id] !== undefined ? stockPreview.stock[product.id] : stockPreview.additional[product.id]) || 0)
      : 'Checking availability') + '</span>';

    const walkInBadge = product.walk_in_only
      ? '<span class="product-info-badge">Walk-in only</span>'
      : '';

    const disabled = product.walk_in_only && delivering;
    const outOfStock = !stockPreview || !stockPreview.valid || Number(stockPreview.additional[product.id] || 0) <= 0;

    return '<button type="button" class="product-item ' + productTheme(product) +
      (disabled || outOfStock ? ' is-disabled' : '') + '"' +
      (disabled || outOfStock ? ' disabled' : '') +
      ' onclick="addProduct(\'' + product.id + '\')">' +
      '<span>' +
      '<span class="product-info-name">' + esc(product.name) + '</span>' +
      '<span class="product-info-sub">' + productSubtitle(product) + '</span>' +
      stockLine +
      walkInBadge +
      '<span class="product-info-price">&#8369;' + price + '</span>' +
      '</span>' +
      '</button>';
  }).join('');
}

async function addProduct(pid) {
  const existing = POS.cart.find(i => i.id === pid);
  if (existing && existing.q >= MAX_QTY_PER_ITEM) {
    appNotice('Maximum ' + MAX_QTY_PER_ITEM + ' units per item.');
    return;
  }
  if (!await allowStockIncrement(pid, 1)) return;
  const p = DB.products.find(x => x.id === pid);
  if (!p) return;

  if (p.walk_in_only && isDelivery()) {
    appNotice(p.name + ' is for walk-in customers only.');
    return;
  }

  const f = POS.cart.find(i => i.id === pid);
  if (f) {
    f.q = Math.min(MAX_QTY_PER_ITEM, f.q + 1);
  } else {
    POS.cart.push({ id: p.id, name: p.name, price: p.price, q: 1, kind: p.kind, cat: p.cat, walkInOnly: !!p.walk_in_only });
  }

  savePOSState();
  renderPOS();
}

async function chgQty(i, d) {
  if (!POS.cart[i]) return;
  if (d > 0 && POS.cart[i].q >= MAX_QTY_PER_ITEM) {
    appNotice('Maximum ' + MAX_QTY_PER_ITEM + ' units per item.');
    return;
  }
  if (d > 0 && !await allowStockIncrement(POS.cart[i].id, d)) return;
  POS.cart[i].q = Math.min(MAX_QTY_PER_ITEM, POS.cart[i].q + d);
  if (POS.cart[i].q <= 0) POS.cart.splice(i, 1);
  savePOSState();
  renderPOS();
}

/** Bulk quantity step for commercial orders: +1 / +5 / +10 in one click. */
async function addQty(i, step) {
  if (!POS.cart[i]) return;
  const amount = Number(step) || 0;
  if (amount > 0 && POS.cart[i].q >= MAX_QTY_PER_ITEM) {
    appNotice('Maximum ' + MAX_QTY_PER_ITEM + ' units per item.');
    return;
  }
  if (amount > 0) {
    const allowed = Math.min(amount, MAX_QTY_PER_ITEM - POS.cart[i].q);
    if (allowed <= 0) {
      appNotice('Maximum ' + MAX_QTY_PER_ITEM + ' units per item.');
      return;
    }
    if (!await allowStockIncrement(POS.cart[i].id, allowed)) return;
    POS.cart[i].q += allowed;
  } else {
    POS.cart[i].q += amount;
  }
  if (POS.cart[i].q <= 0) POS.cart.splice(i, 1);
  savePOSState();
  renderPOS();
}

/** Only refilled gallons consume caps and seals; containers are merchandise. */
function cartGallons() {
  return POS.cart.filter(item => item.cat === 'refill').reduce((sum, item) => sum + item.q, 0);
}

/**
 * Gross prices with inclusive VAT.
 *
 *   vatable = total / (1 + rate)
 *   vat     = total - vatable
 */
function posTotals() {
  const total = POS.cart.reduce((sum, i) => sum + i.price * i.q, 0);
  const rounded = Math.round(total * 100) / 100;
  const vatable = Math.round((rounded / (1 + VAT_RATE)) * 100) / 100;
  const vat = Math.round((rounded - vatable) * 100) / 100;

  return { total: rounded, vatable, vat, gal: cartGallons() };
}

/** The bought-items rows, shared by the tray and the summary. */
function cartRowsHtml() {
  if (!POS.cart.length) {
    return '<p class="cust-option-sub">No items yet - tap a product above.</p>';
  }

  return POS.cart.map((it, x) =>
    '<div class="order-row">' +
    '<span>' + esc(it.name) + '</span><span class="text-right">' + money(it.price) + '</span>' +
    '<span class="qty-ctrl">' +
      '<button type="button" onclick="chgQty(' + x + ',-1)">-</button>' +
      '<b>x' + it.q + '</b>' +
      '<button type="button" onclick="chgQty(' + x + ',1)">+</button>' +
    '</span>' +
    '<span class="text-right"><b>' + peso(it.price * it.q) + '</b></span></div>'
  ).join('');
}

function setDeliveryPayOption(opt) {
  POS.deliveryPay = opt;
  renderPaymentPanel();
  renderPOS();
}

/** Switches the stage-3 payment panel to match the order type and delivery payment choice. */
function renderPaymentPanel() {
  const cash = document.getElementById('cashPanel');
  const delivery = document.getElementById('deliveryPanel');
  const deliveryChoice = document.getElementById('deliveryPaymentChoice');
  const badge = document.getElementById('payBadge');
  const optCashier = document.getElementById('payOptCashier');
  const optRider = document.getElementById('payOptRider');

  POS.deliveryPay = POS.deliveryPay || 'rider';
  const delivering = isDelivery();

  if (deliveryChoice) deliveryChoice.classList.toggle('hidden', !delivering);

  if (delivering) {
    const payToCashier = POS.deliveryPay === 'cashier';
    if (optCashier) optCashier.classList.toggle('active', payToCashier);
    if (optRider) optRider.classList.toggle('active', !payToCashier);

    if (cash) cash.classList.toggle('hidden', !payToCashier);
    if (delivery) delivery.classList.toggle('hidden', payToCashier);
    if (badge) badge.textContent = payToCashier ? 'CASH (COLLECT NOW)' : 'PAY TO RIDER';

    const c = posCust();
    const t = posTotals();
    const balance = c === null ? 0 : Number(c.debt);

    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('deliveryBalance', money(balance));
    set('deliveryThis', money(t.total));
    set('deliveryAfter', money(payToCashier ? balance : (balance + t.total)));
  } else {
    if (cash) cash.classList.remove('hidden');
    if (delivery) delivery.classList.add('hidden');
    if (badge) badge.textContent = 'CASH';
  }
}

function renderPOS() {
  scheduleStockPreview();
  renderCustList();
  renderProducts();

  const t = posTotals();

  const rNo = document.getElementById('rNo');
  if (rNo) rNo.textContent = 'OR-' + POS.orderN;

  const cart = document.getElementById('cart');
  if (cart) cart.innerHTML = cartRowsHtml();

  const bought = document.getElementById('boughtItems');
  if (bought) bought.innerHTML = cartRowsHtml();

  const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
  set('boughtTotal', money(t.total));
  set('tVatable', money(t.vatable));
  set('tVat', money(t.vat));
  set('tTot', money(t.total));
  set('tGal', t.gal);

  const boughtCount = document.getElementById('boughtCount');
  if (boughtCount) {
    const count = POS.cart.reduce((sum, item) => sum + item.q, 0);
    boughtCount.textContent = count + (count === 1 ? ' item' : ' items');
  }

  // Cash change, shown large and green on the walk-in panel.
  const tender = document.getElementById('tender');
  const ten = tender ? (+tender.value || 0) : 0;
  set('tChg', money(Math.max(0, ten - t.total)));

  const deductNote = document.getElementById('deductNote');
  if (deductNote) {
    deductNote.textContent = t.gal > 0
      ? 'Each refilled gallon also consumes 1 cap and 1 seal from station stock.'
      : '';
  }

  renderPaymentPanel();

  // Summary block: customer, address, order type, existing debt
  const sumCustomer = document.getElementById('sumCustomer');
  if (sumCustomer) {
    const c = posCust();
    sumCustomer.className = 'active-customer-banner' + (c === null ? ' empty' : '');
    sumCustomer.innerHTML = c === null
      ? '<span class="cust-detail">No customer selected yet.</span>'
      : '<b class="cust-detail-name">' + esc(c.name) + '</b>' +
        '<span class="cust-detail">' + esc(c.addr) + '</span>' +
        '<span class="cust-detail">' + (isDelivery() ? icon('i-truck') + ' Delivery' : icon('i-walkin') + ' Walk-in') + '</span>' +
        '<span class="cust-detail">Existing debt: ' + money(Number(c.debt)) + '</span>';
  }

  // Order-type hint on stage 2
  const orderNote = document.getElementById('orderTypeNote');
  if (orderNote) {
    orderNote.textContent = isDelivery()
      ? 'Delivery orders are charged to the customer account. Refills and new jugs can both be delivered.'
      : '';
  }

  if (typeof updateDebtPanel === 'function') updateDebtPanel();
  // The primary button label depends on the order type as well as the stage.
  if (typeof refreshPrimaryLabel === 'function') refreshPrimaryLabel();
  // Clear a stage warning once the cashier has fixed what it complained about.
  if (typeof refreshStageValidation === 'function') refreshStageValidation();

  savePOSState();
}

/** Production queue advancement (Unload -> Wash -> Fill -> Seal). */
async function advanceQueue(id) {
  try {
    const result = await API.advanceQueue(id);
    DB.queue = result.queue;
    saveDB();
    renderPOS();
    if (typeof renderQueuePage === 'function' && document.getElementById('queue')) renderQueuePage();
  } catch (error) {
    appNotice(error.message || 'Could not advance the queue stage.');
  }
}

var stockPreview = null, stockSignature = null, stockSequence = 0, stockBusy = false;
function cartSignature() { return JSON.stringify(POS.cart.map(i => [i.id, i.q])); }
async function refreshStockPreview() {
  const seq = ++stockSequence, signature = cartSignature();
  stockPreview = null;
  renderProducts();
  try {
    const result = await API.request('cart/availability', {method:'POST', body:{items:POS.cart.map(i => ({product_id:i.id, quantity:i.q}))}});
    if (seq !== stockSequence || signature !== cartSignature()) return null;
    stockPreview = result;
    renderProducts();
    return result;
  } catch (error) {
    if (seq === stockSequence) {
      stockSignature = null;
    }
    return null;
  }
}
function scheduleStockPreview() {
  const signature = cartSignature();
  if (signature === stockSignature) return;
  stockSignature = signature; refreshStockPreview();
}
async function allowStockIncrement(id, quantity) {
  if (stockBusy) return false;
  stockBusy = true;
  try {
    const preview = await refreshStockPreview();
    if (!preview || !preview.valid || Number(preview.additional[id] || 0) < quantity) {
      showStageError(preview ? 'Not enough stock for that quantity. Reduce the quantity or refresh stock.' : 'Stock cannot be checked. Reconnect and retry.');
      return false;
    }
    return true;
  } finally { stockBusy = false; }
}
window.addEventListener('focus', () => { if (document.getElementById('productGrid')) refreshStockPreview(); });
window.addEventListener('online', () => { if (document.getElementById('productGrid')) refreshStockPreview(); });
