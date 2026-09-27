// AquaFlow CASHIER terminal - cart, customers and order totals
// Logic copied from the original AquaFlow prototype and extended so
// the split step pages share one live order.

// `var` (not `let`) keeps `typeof POS` safe in the persistence layer no matter
// how the scripts are loaded, including concatenated builds.
var POS = { type: 'Walk-in', pay: 'Cash', cart: [], custId: 9, orderN: 1018, vat: false, discount: 0, shape: 'S', intake: { oS: 0, iS: 0, oR: 0, iR: 0 }, dmg: false };

// Empty jug return deposit credit (paper Ch I): every empty jug handed back
// earns the customer a fixed credit.
const AUTO_RETURN_DEPOSIT = 5;

// Resume order numbering past any persisted transaction so OR numbers never collide.
(function () {
  restorePOSState();
  const maxOr = DB.transactions.reduce((m, t) => {
    const n = parseInt((String(t.no).match(/\d+/) || [0])[0], 10);
    return Math.max(m, n || 0);
  }, 0);
  POS.orderN = Math.max(POS.orderN || 1018, maxOr + 1);
})();

function posCust() { return DB.customers.find(c => c.id === POS.custId) || DB.customers[0]; }

function setType(t) {
  POS.type = t;
  const walk = document.getElementById('bWalk');
  const del = document.getElementById('bDel');
  if (walk) walk.className = 'btn ' + (t === 'Walk-in' ? 'btn-primary' : 'btn-ghost');
  if (del) del.className = 'btn ' + (t === 'Delivery' ? 'btn-primary' : 'btn-ghost');
  savePOSState();
}

function quickOrder(t) {
  setType(t);
  // Already on the Products & Intake page -> stay and jump to the grid.
  if (document.getElementById('posGrid')) {
    document.getElementById('posGrid').scrollIntoView({ behavior: 'smooth', block: 'start' });
    return;
  }
  // Otherwise continue to the order-building step.
  posStep(2);
}

function renderCustList() {
  const search = document.getElementById('custSearch');
  const list = document.getElementById('custList');
  if (search && list) {
    const q = (search.value || '').toLowerCase();
    const rows = DB.customers.filter(c => c.name.toLowerCase().includes(q)).slice(0, 6);
    list.innerHTML = rows.map(c => {
      const p = pending(c), sel = c.id === POS.custId, oob = p.s + p.r > 0;
      return '<button onclick="pickCust(' + c.id + ')" class="customer-option' + (sel ? ' selected' : '') + '">' +
        '<span class="cust-option-top"><b>' + c.name + '</b>' +
        '<span class="cust-option-oob ' + (oob ? 'text-red-600' : 'text-green-700') + '">' + (oob ? '+' + (p.s + p.r) + ' JUG' : '0 JUG') + '</span></span>' +
        '<span class="cust-option-sub">S:' + p.s + ' R:' + p.r + ' | ₱' + c.debt.toLocaleString() + '</span></button>';
    }).join('');
  }

  const c = posCust(), p = pending(c);
  const card = document.getElementById('custCard');
  if (card) {
    card.innerHTML =
      '<div class="flex-between"><b>' + c.name + '</b><span class="cust-option-sub">ID-' + c.id + '</span></div>' +
      '<p class="cust-option-sub">' + c.addr + ' | ' + c.contact + '</p>' +
      '<p class="cust-card-line">Cash debt: <b>₱' + c.debt.toLocaleString() + '</b> | Visits: ' + c.tx + '</p>';
  }

  const custody = document.getElementById('custodyBox');
  if (custody) {
    custody.innerHTML =
      '<div class="custody-row"><span>5-Gallon Slim (unreturned)</span><b class="' + (p.s > 0 ? 'text-red-600' : 'text-green-700') + '">' + (p.s > 0 ? '+' + p.s + ' JUG' : '0 JUG') + '</b></div>' +
      '<div class="custody-row"><span>5-Gallon Round (unreturned)</span><b class="' + (p.r > 0 ? 'text-red-600' : 'text-green-700') + '">' + (p.r > 0 ? '+' + p.r + ' JUG' : '0 JUG') + '</b></div>';
  }
}

function pickCust(id) {
  POS.custId = id;
  savePOSState();
  renderCustList();
  if (typeof custodyCheck === 'function') custodyCheck();
  if (typeof updateDebtPanel === 'function') updateDebtPanel();
  renderPOS();
}

function addProduct(pid) {
  const p = DB.products.find(x => x.id === pid);
  if (!p) return;
  const f = POS.cart.find(i => i.id === pid);
  if (f) f.q++; else POS.cart.push({ id: p.id, name: p.name, price: p.price, q: 1, kind: p.kind });
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

function cartGallons() {
  return POS.cart.filter(i => i.kind === 'S' || i.kind === 'R').reduce((a, i) => a + i.q, 0);
}

function posTotals() {
  const sub = POS.cart.reduce((a, i) => a + i.price * i.q, 0);
  const gal = cartGallons();
  const vatAmt = POS.vat ? sub * 0.12 : 0;
  const returns = (+POS.intake.iS || 0) + (+POS.intake.iR || 0);
  const autoDisc = returns * AUTO_RETURN_DEPOSIT;
  const total = Math.max(0, sub + vatAmt - (POS.discount || 0) - autoDisc);
  return { sub, gal, vatAmt, autoDisc, total, returns };
}

// Renders whichever part of the order the current step page contains.
function renderPOS() {
  renderCustList();

  const t = posTotals();
  const money = n => '₱' + Math.round(n).toLocaleString();

  const rNo = document.getElementById('rNo');
  if (rNo) rNo.textContent = 'OR-' + POS.orderN;

  const cart = document.getElementById('cart');
  if (cart) {
    cart.innerHTML = POS.cart.length ? POS.cart.map((it, x) =>
      '<div class="order-row">' +
      '<span>' + it.name + '</span><span class="text-right">₱' + it.price + '</span>' +
      '<span class="qty-ctrl"><button onclick="chgQty(' + x + ',-1)">-</button><b>x' + it.q + '</b><button onclick="chgQty(' + x + ',1)">+</button></span>' +
      '<span class="text-right"><b>₱' + (it.price * it.q).toLocaleString() + '</b></span></div>'
    ).join('') : '<p class="cust-option-sub">No items yet. Press + ADD on a product card.</p>';
  }

  const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
  set('tSub', money(t.sub));
  set('tVat', money(t.vatAmt));
  set('tDisc', money(t.autoDisc));
  set('tGal', t.gal);
  set('tTot', money(t.total));

  const tender = document.getElementById('tender');
  const ten = tender ? (+tender.value || 0) : 0;
  set('tChg', money(Math.max(0, ten - t.total)));

  const jugBox = document.getElementById('jugBox');
  if (jugBox) {
    const miss = Math.max(0, POS.intake.oS - POS.intake.iS) + Math.max(0, POS.intake.oR - POS.intake.iR);
    jugBox.textContent = t.returns + ' Empty Jugs Returned' + (miss > 0 ? ' | ' + miss + ' Missing (billed)' : ' | None Missing');
  }

  const deductNote = document.getElementById('deductNote');
  if (deductNote) {
    deductNote.textContent = t.gal > 0 ? 'Each gallon uses 1 cap and 1 seal - that many are deducted from station stock.' : 'No water refills added yet.';
  }

  const mini = document.getElementById('cartMini');
  if (mini) {
    const qty = POS.cart.reduce((a, i) => a + i.q, 0);
    mini.textContent = qty ? 'Current order: ' + qty + ' item(s) - ' + money(t.total) : 'No items yet - press + ADD on a product card.';
  }

  const payBox = document.getElementById('payCustBox');
  if (payBox) {
    const c = posCust(), p = pending(c);
    payBox.innerHTML =
      '<div class="flex-between"><b>' + c.name + '</b><span class="cust-option-sub">' + POS.type + '</span></div>' +
      '<p class="cust-card-line">Bottles owed: ' + p.s + 'S / ' + p.r + 'R | Cash debt: ₱' + c.debt.toLocaleString() + '</p>';
  }

  const txLog = document.getElementById('txLog');
  if (txLog) {
    txLog.innerHTML = DB.transactions.slice(0, 8).map(x =>
      '<button onclick="reprint(\'' + x.no + '\')" class="tx-row"><span><b>' + x.no + '</b> ' + x.cust + ' | ' + x.type + '</span><b>₱' + x.total + '</b></button>'
    ).join('');
  }

  const queue = document.getElementById('queue');
  if (queue) {
    queue.innerHTML = DB.queue.map(q =>
      '<div class="queue-card"><div class="flex-between"><b>' + q.no + '</b><span class="queue-meta">' + q.cust + ' | ' + q.mins + ' min elapsed</span></div>' +
      '<div class="queue-stages">' + STAGES.map((s, i) =>
        '<span class="pill ' + (i < q.stage ? 'pill-ok' : i === q.stage ? 'pill-info' : 'pill-neutral') + '">' + s + '</span>'
      ).join('') + '</div></div>'
    ).join('');
  }

  if (typeof updateDebtPanel === 'function') updateDebtPanel();
  savePOSState();
}
