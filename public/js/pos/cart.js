// AquaFlow CASHIER terminal - cart, customers and order totals.
//
// Money model: displayed prices already include 12% VAT, so the vatable sales
// and the tax portion are extracted from the gross total. There are no
// discounts.
//
// Containers: the station owns no jugs. A refill is the customer's own jug
// coming back filled (no liability, no deposit); a brand-new jug is ordinary
// merchandise that depletes stock and is walk-in only.

// `var` (not `let`) keeps `typeof POS` safe in the persistence layer no matter
// how the scripts are loaded, including concatenated builds.
// custId starts null: the spec requires nothing pre-selected.
var POS = { type: 'Walk-in', pay: 'Cash', cart: [], custId: null, orderN: 1018 };

const VAT_RATE = 0.12;

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

/** Jugs of the customer's that the station is still holding. */
function jugsInCustody(customer) {
  return Math.max(0, customer.issuedS - customer.returnedS) +
    Math.max(0, customer.issuedR - customer.returnedR);
}

function setType(t) {
  POS.type = t;
  const walk = document.getElementById('bWalk');
  const del = document.getElementById('bDel');
  if (walk) walk.className = 'btn ' + (t === 'Walk-in' ? 'btn-primary' : 'btn-ghost');
  if (del) del.className = 'btn ' + (t === 'Delivery' ? 'btn-primary' : 'btn-ghost');

  // Walk-in-only merchandise cannot ride a delivery, so drop it from the cart.
  if (t === 'Delivery') {
    const blocked = POS.cart.filter(i => i.walkInOnly);
    if (blocked.length) {
      POS.cart = POS.cart.filter(i => !i.walkInOnly);
      alert('Removed from the order (walk-in only): ' + blocked.map(i => i.name).join(', '));
    }
  }

  savePOSState();
  renderPOS();
}

// ---------- Customer list ----------

function renderCustList() {
  const search = document.getElementById('custSearch');
  const list = document.getElementById('custList');

  if (search && list) {
    const q = (search.value || '').toLowerCase();
    const rows = DB.customers
      .filter(c => c.name.toLowerCase().includes(q) || String(c.addr).toLowerCase().includes(q))
      .slice(0, 8);

    list.innerHTML = rows.map(c => {
      const sel = c.id === POS.custId;
      const custody = jugsInCustody(c);

      return '<button onclick="pickCust(' + c.id + ')" class="customer-option' + (sel ? ' selected' : '') + '">' +
        '<span class="cust-option-top"><b>' + c.name + '</b>' +
        '<span class="cust-option-oob ' + (custody > 0 ? 'text-red-600' : 'text-green-700') + '">' +
        custody + ' Jug' + (custody === 1 ? '' : 's') + ' in Custody</span></span>' +
        '<span class="cust-option-sub">' + c.addr + '</span></button>';
    }).join('');
  }

  const card = document.getElementById('custCard');
  if (!card) return;

  const c = posCust();
  if (c === null) {
    card.innerHTML = '<p class="cust-option-sub">No customer selected. Search above, or add a new one.</p>' +
      '<div class="debt-badge hidden"></div>';
    return;
  }

  const owes = Number(c.debt) > 0;
  const custody = jugsInCustody(c);

  card.innerHTML =
    '<div class="flex-between"><b>' + c.name + '</b><span class="cust-option-sub">ID-' + c.id + '</span></div>' +
    '<p class="cust-option-sub">' + c.addr + '</p>' +
    '<div class="custody-row"><span>Jugs in Custody</span><b>' + custody + '</b></div>' +
    '<div class="debt-badge ' + (owes ? '' : 'clear') + '">' +
    '<span>Cash debt</span><b>&#8369;' + Number(c.debt).toLocaleString() + '</b></div>';
}

function pickCust(id) {
  POS.custId = id;
  savePOSState();
  renderPOS();
}

// ---------- One-touch product tiles ----------

const PRODUCT_THEMES = {
  slim: 'prod-theme-blue',
  round: 'prod-theme-teal',
  caps: 'prod-theme-yellow',
  seals: 'prod-theme-yellow',
  soap: 'prod-theme-purple',
  newS: 'prod-theme-gray',
  newR: 'prod-theme-gray'
};

const PRODUCT_SUBTITLES = {
  slim: 'Bring your own jug',
  round: 'Bring your own jug',
  caps: 'Per pack of 50',
  seals: 'Per pack of 100',
  soap: 'Cleaning supplies',
  newS: 'For Sale - brand new',
  newR: 'For Sale - brand new'
};

function productTheme(product) {
  if (PRODUCT_THEMES[product.id]) return PRODUCT_THEMES[product.id];
  if (product.cat === 'refill') return product.kind === 'R' ? 'prod-theme-teal' : 'prod-theme-blue';
  if (product.cat === 'consumable') return 'prod-theme-yellow';
  if (product.cat === 'cleaning') return 'prod-theme-purple';
  return 'prod-theme-gray';
}

function productSubtitle(product) {
  if (PRODUCT_SUBTITLES[product.id]) return PRODUCT_SUBTITLES[product.id];
  if (product.cat === 'refill') return 'Refill';
  if (product.cat === 'container') return 'For Sale';
  return 'Supply';
}

/** Tile order: refills first, then containers, then supplies. */
const POS_TILE_ORDER = ['slim', 'round', 'newS', 'newR', 'caps', 'seals', 'soap'];

function renderProducts() {
  const grid = document.getElementById('productGrid');
  if (!grid) return;

  const delivering = POS.type === 'Delivery';

  const items = (DB.products || [])
    .filter(product => product.sold_at_pos !== false)
    .sort((a, b) => {
      const ai = POS_TILE_ORDER.indexOf(a.id);
      const bi = POS_TILE_ORDER.indexOf(b.id);
      return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi);
    });

  grid.innerHTML = items.map(product => {
    const price = Number(product.price).toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });

    // Brand-new jugs show live stock and are counter sales only.
    const stockLine = product.cat === 'container' && product.stock !== null
      ? '<span class="product-info-stock">STOCKS: ' + product.stock + '</span>'
      : '';

    const walkInBadge = product.walk_in_only
      ? '<span class="product-info-badge">Walk-in only</span>'
      : '';

    const disabled = product.walk_in_only && delivering;
    const outOfStock = product.cat === 'container' && product.stock !== null && product.stock <= 0;

    return '<button type="button" class="product-item ' + productTheme(product) +
      (disabled || outOfStock ? ' is-disabled' : '') + '"' +
      (disabled || outOfStock ? ' disabled' : '') +
      ' onclick="addProduct(\'' + product.id + '\')">' +
      '<span>' +
      '<span class="product-info-name">' + product.name + '</span>' +
      '<span class="product-info-sub">' + productSubtitle(product) + '</span>' +
      stockLine +
      walkInBadge +
      '<span class="product-info-price">&#8369;' + price + '</span>' +
      '</span>' +
      '<span class="product-add-button">+ ADD</span>' +
      '</button>';
  }).join('');
}

function addProduct(pid) {
  const p = DB.products.find(x => x.id === pid);
  if (!p) return;

  if (p.walk_in_only && POS.type === 'Delivery') {
    alert(p.name + ' is for walk-in customers only.');
    return;
  }

  const f = POS.cart.find(i => i.id === pid);
  if (f) f.q++; else POS.cart.push({ id: p.id, name: p.name, price: p.price, q: 1, kind: p.kind, cat: p.cat, walkInOnly: !!p.walk_in_only });
  savePOSState();
  renderPOS();
}

function chgQty(i, d) {
  if (!POS.cart[i]) return;
  POS.cart[i].q += d;
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

/** The bought-items rows, shared by the products panel and the payment panel. */
function cartRowsHtml() {
  if (!POS.cart.length) {
    return '<p class="cust-option-sub">No items yet - tap a product above.</p>';
  }

  return POS.cart.map((it, x) =>
    '<div class="order-row">' +
    '<span>' + it.name + '</span><span class="text-right">&#8369;' + it.price + '</span>' +
    '<span class="qty-ctrl"><button onclick="chgQty(' + x + ',-1)">-</button><b>x' + it.q + '</b><button onclick="chgQty(' + x + ',1)">+</button></span>' +
    '<span class="text-right"><b>&#8369;' + (it.price * it.q).toLocaleString() + '</b></span></div>'
  ).join('');
}

function renderPOS() {
  renderCustList();
  renderProducts();

  const t = posTotals();
  const money = n => '₱' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

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

  const tender = document.getElementById('tender');
  const ten = tender ? (+tender.value || 0) : 0;
  set('tChg', money(Math.max(0, ten - t.total)));

  const deductNote = document.getElementById('deductNote');
  if (deductNote) {
    deductNote.textContent = t.gal > 0
      ? 'Each refilled gallon also consumes 1 cap and 1 seal from station stock.'
      : '';
  }

  // Summary block: customer, address, existing debt
  const sumCustomer = document.getElementById('sumCustomer');
  if (sumCustomer) {
    const c = posCust();
    sumCustomer.innerHTML = c === null
      ? '<p class="cust-option-sub">No customer selected yet.</p>'
      : '<div class="flex-between"><b>' + c.name + '</b><span class="cust-option-sub">' + c.addr + '</span></div>' +
        '<p class="cust-option-sub">Existing cash debt: <b>&#8369;' + Number(c.debt).toLocaleString() + '</b></p>';
  }

  const payBadge = document.getElementById('payDebtBadge');
  if (payBadge) {
    const c = posCust();
    if (c === null || c.name === 'Walk-in Guest') {
      payBadge.className = 'debt-badge hidden';
    } else if (Number(c.debt) > 0) {
      payBadge.className = 'debt-badge';
      payBadge.innerHTML = '<span>Outstanding Debt</span><b>&#8369;' + Number(c.debt).toLocaleString() + '</b>';
    } else {
      payBadge.className = 'debt-badge clear';
      payBadge.innerHTML = '<span>Account balance</span><b>Cleared</b>';
    }
  }

  // Order-type hint on stage 2
  const orderNote = document.getElementById('orderTypeNote');
  if (orderNote) {
    orderNote.textContent = POS.type === 'Delivery'
      ? 'Delivery: brand-new jugs are hidden because they are walk-in only.'
      : '';
  }

  // Compact production queue on the payment stage
  const strip = document.getElementById('queueStrip');
  if (strip) {
    strip.innerHTML = DB.queue.length
      ? DB.queue.slice(0, 5).map(q =>
        '<div class="custody-row"><span><b>' + q.no + '</b> ' + q.cust + '</span><b>' + (STAGES[q.stage] || 'Done') + '</b></div>'
      ).join('')
      : '<p class="cust-option-sub">No orders in progress.</p>';
  }

  // Payment guardrails
  if (typeof enforcePaymentRules === 'function') enforcePaymentRules();
  if (typeof updatePayLock === 'function') updatePayLock();
  if (typeof updateDebtPanel === 'function') updateDebtPanel();
  // Clear a stage warning once the cashier has fixed what it complained about.
  if (typeof refreshStageValidation === 'function') refreshStageValidation();

  // A sale needs a customer and at least one item.
  const complete = document.getElementById('btnComplete');
  if (complete) {
    complete.disabled = !hasCustomer() || POS.cart.length === 0;
  }

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
    alert(error.message || 'Could not advance the queue stage.');
  }
}
