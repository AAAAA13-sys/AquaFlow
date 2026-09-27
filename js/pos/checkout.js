// AquaFlow CASHIER terminal - checkout, payment, debt settlement, registration,
// step navigation and receipt printing.
// Logic copied from the original AquaFlow prototype, extended so the
// 3 split step pages behave like one continuous terminal.

// ---------- Step navigation (one HTML file per wizard step) ----------
const POS_STEP_FILES = { 1: 'customer-custody.html', 2: 'products-intake.html', 3: 'payment-print.html' };
let posCur = 1;

function posStep(n) {
  const file = POS_STEP_FILES[n];
  if (file) location.href = file;
}

function posPrev() {
  if (posCur > 1) posStep(posCur - 1);
}

function posNext() {
  if (posCur >= 3) completeSale(typeof SESSION !== 'undefined' ? SESSION.name : 'Cashier');
  else posStep(posCur + 1);
}

function initCashierPage(step) {
  posCur = step;
  document.querySelectorAll('#posSteps .pos-step').forEach(b => {
    b.classList.toggle('active', +b.dataset.step === step);
  });
  const back = document.getElementById('posBack');
  if (back) back.disabled = step === 1;
  const next = document.getElementById('posNext');
  if (next) next.textContent = step >= 3 ? 'Complete Sale' : 'Next';
  // The customer step hands off to the payment step when "Settle Utang" is pressed.
  if (step === 3 && /(?:^|[?&])settle=1(?:&|$)/.test(location.search || '')) {
    const box = document.getElementById('debtSettleBox');
    if (box) box.classList.remove('hidden');
  }
}

// Shared boot: role guard, active cashier name, live clock and step wiring.
function bootCashierPortal(step) {
  const session = guardCashier();
  if (!session) return null;
  const who = document.getElementById('who');
  if (who) who.textContent = session.name;
  const clock = document.getElementById('clock');
  const tick = () => { if (clock) clock.textContent = new Date().toLocaleString(); };
  tick();
  setInterval(tick, 1000);
  initCashierPage(step);
  return session;
}

// ---------- Payment method + tender ----------
function setPay(p) {
  POS.pay = p;
  const cash = document.getElementById('pCash');
  const acct = document.getElementById('pAccount');
  if (cash) cash.className = 'btn ' + (p === 'Cash' ? 'btn-primary' : 'btn-ghost');
  if (acct) acct.className = 'btn ' + (p === 'Account' ? 'btn-primary' : 'btn-ghost');
  const tenderWrap = document.getElementById('tenderWrap');
  if (tenderWrap) tenderWrap.style.display = p === 'Account' ? 'none' : 'block';
  savePOSState();
  renderPOS();
}

function restorePaymentInputs() {
  const disc = document.getElementById('disc');
  if (disc) disc.value = POS.discount || 0;
  const vat = document.getElementById('vatT');
  if (vat) vat.checked = !!POS.vat;
}

// ---------- Debt settlement (Utang) ----------
function toggleDebtSettle() {
  const box = document.getElementById('debtSettleBox');
  if (box) {
    box.classList.toggle('hidden');
    updateDebtPanel();
    return;
  }
  // The settlement panel lives on the Payment & Print step.
  location.href = 'payment-print.html?settle=1';
}

function updateDebtPanel() {
  const c = posCust();
  const pay = document.getElementById('debtPay');
  const view = document.getElementById('custDebtView');
  const after = document.getElementById('debtAfter');
  if (view) view.textContent = '₱' + c.debt.toLocaleString();
  if (after) after.textContent = '₱' + Math.max(0, c.debt - (+((pay && pay.value) || 0))).toLocaleString();
}

function settleDebt() {
  const c = posCust();
  const pay = document.getElementById('debtPay');
  const amount = +((pay && pay.value) || 0);
  if (amount <= 0) { alert('Enter a payment amount.'); return; }
  const applied = Math.min(amount, c.debt);
  c.debt -= applied;
  if (pay) pay.value = 0;
  saveDB();
  updateDebtPanel();
  renderCustList();
  renderPOS();
  alert('Debt payment recorded: ₱' + applied.toLocaleString() + '. Remaining balance ₱' + c.debt.toLocaleString() + '.');
}

// ---------- Quick customer registration ----------
function openRegisterModal() {
  const m = document.getElementById('registerModal');
  if (!m) return;
  m.classList.remove('hidden');
  const name = document.getElementById('regName');
  if (name) name.focus();
}

function closeRegisterModal() {
  const m = document.getElementById('registerModal');
  if (m) m.classList.add('hidden');
}

function saveNewCustomer() {
  const nameEl = document.getElementById('regName');
  const addrEl = document.getElementById('regAddr');
  const contactEl = document.getElementById('regContact');
  const name = ((nameEl && nameEl.value) || '').trim();
  if (!name) { alert('Customer name is required.'); return; }
  const id = DB.customers.reduce((m, c) => Math.max(m, c.id), 0) + 1;
  DB.customers.push({
    id,
    name,
    addr: ((addrEl && addrEl.value) || '').trim() || '-',
    contact: ((contactEl && contactEl.value) || '').trim() || '-',
    issuedS: 0, returnedS: 0, issuedR: 0, returnedR: 0, debt: 0, tx: 0, last: '-'
  });
  POS.custId = id;
  saveDB();
  closeRegisterModal();
  if (nameEl) nameEl.value = '';
  if (addrEl) addrEl.value = '';
  if (contactEl) contactEl.value = '';
  renderPOS();
  alert('Customer registered: ' + name);
}

// ---------- Checkout ----------
function showReceipt(no, t, intake, ten, c, sessionName) {
  const meta = document.getElementById('rMeta');
  const body = document.getElementById('rBody');
  const box = document.getElementById('receipt');
  if (!meta || !body || !box) return;
  meta.textContent = no + ' | ' + new Date().toLocaleString() + ' | ' + (sessionName || '');
  body.innerHTML =
    POS.cart.map(i => '<div class="receipt-line"><span>' + i.q + 'x ' + i.name + '</span><span>₱' + (i.q * i.price).toLocaleString() + '</span></div>').join('') +
    '<div class="receipt-line"><span>Subtotal</span><span>₱' + t.sub.toLocaleString() + '</span></div>' +
    (POS.vat ? '<div class="receipt-line"><span>VAT 12%</span><span>₱' + t.vatAmt.toLocaleString() + '</span></div>' : '') +
    (POS.discount ? '<div class="receipt-line"><span>Manual discount</span><span>-₱' + POS.discount.toLocaleString() + '</span></div>' : '') +
    (t.autoDisc ? '<div class="receipt-line"><span>Auto return discount</span><span>-₱' + t.autoDisc.toLocaleString() + '</span></div>' : '') +
    '<div class="receipt-line total"><span>Total</span><span>₱' + t.total.toLocaleString() + '</span></div>' +
    '<div class="receipt-line"><span>Custody OUT/IN</span><span>S:' + intake.oS + '/' + intake.iS + ' R:' + intake.oR + '/' + intake.iR + '</span></div>' +
    '<div class="receipt-line"><span>Pending now</span><span>S:' + pending(c).s + ' R:' + pending(c).r + '</span></div>' +
    '<div class="receipt-line"><span>' + POS.pay + (POS.pay === 'Account' ? ' (posted)' : ' / Tendered ₱' + ten.toLocaleString()) + '</span><span>' +
    (POS.pay === 'Account' ? 'Debt ₱' + c.debt.toLocaleString() : 'Change ₱' + Math.max(0, ten - t.total).toLocaleString()) + '</span></div>';
  box.classList.remove('hidden');
}

function completeSale(sessionName) {
  if (!POS.cart.length) { alert('Cart is empty.'); return; }
  const c = posCust();
  if (POS.type === 'Delivery' && c.name === 'Walk-in Guest') { alert('Delivery requires a registered customer.'); return; }
  const t = posTotals();
  const intake = POS.intake || { oS: 0, iS: 0, oR: 0, iR: 0 };
  const tenderEl = document.getElementById('tender');
  const ten = tenderEl ? (+tenderEl.value || 0) : 0;
  if (POS.pay !== 'Account' && ten < t.total) { alert('Tendered amount is less than total.'); return; }

  const dS = Math.max(0, intake.oS - intake.iS), dR = Math.max(0, intake.oR - intake.iR);
  if (dS > 0) c.issuedS += dS;
  if (dR > 0) c.issuedR += dR;

  // Dynamic Damaged Bottle Inventory Deduction: Slim and Round stocks never merge.
  if (POS.dmg) {
    const hasRoundCart = POS.cart.some(it => it.id === 'round' || it.id === 'newR');
    const hasSlimCart = POS.cart.some(it => it.id === 'slim' || it.id === 'newS');
    const shapes = [];
    if (intake.oR > intake.iR) shapes.push('Round');
    if (intake.oS > intake.iS) shapes.push('Slim');
    if (!shapes.length) {
      if (hasRoundCart) shapes.push('Round');
      if (hasSlimCart) shapes.push('Slim');
      if (!shapes.length) shapes.push('Slim');
    }
    shapes.forEach(shape => {
      const jug = DB.inventory.find(v => v.item.includes(shape + ' Jugs'));
      if (jug) jug.on = Math.max(0, jug.on - 1);
    });
  }

  if (POS.pay === 'Account') c.debt += t.total;
  c.tx++; c.last = new Date().toISOString().split('T')[0];

  // Auto-deduct caps + seals per gallon
  const caps = DB.inventory.find(v => v.item === 'Non-Spill Caps');
  const seals = DB.inventory.find(v => v.item === 'Heat Shrink Seals');
  if (caps) caps.on = Math.max(0, caps.on - t.gal);
  if (seals) seals.on = Math.max(0, seals.on - t.gal);

  const no = 'OR-' + (POS.orderN++);
  DB.transactions.unshift({
    t: new Date().toLocaleTimeString(),
    date: new Date().toISOString().split('T')[0],
    no, cust: c.name, type: POS.type, gal: t.gal + ' gal', total: t.total, pay: POS.pay, by: sessionName
  });
  saveDB();
  showReceipt(no, t, intake, ten, c, sessionName);
  updateDebtPanel();
  renderCustList();
}

function closeReceipt() {
  const box = document.getElementById('receipt');
  if (box) box.classList.add('hidden');
  POS.cart = [];
  POS.discount = 0;
  POS.vat = false;
  POS.dmg = false;
  POS.intake = { oS: 0, iS: 0, oR: 0, iR: 0 };
  const tender = document.getElementById('tender');
  if (tender) tender.value = 0;
  const disc = document.getElementById('disc');
  if (disc) disc.value = 0;
  const vat = document.getElementById('vatT');
  if (vat) vat.checked = false;
  const dmg = document.getElementById('dmg');
  if (dmg) dmg.checked = false;
  ['outS', 'inS', 'outR', 'inR'].forEach(id => { const el = document.getElementById(id); if (el) el.value = 0; });
  saveDB();
  custodyCheck();
  renderPOS();
}

function reprint(no) {
  const x = DB.transactions.find(t => t.no === no);
  if (!x) return;
  const meta = document.getElementById('rMeta');
  const body = document.getElementById('rBody');
  const box = document.getElementById('receipt');
  if (!meta || !body || !box) return;
  meta.textContent = x.no + ' | ' + x.t + ' | ' + x.cust;
  body.innerHTML =
    '<div class="receipt-line"><span>' + x.type + ' ' + x.gal + '</span><span>₱' + x.total + '</span></div>' +
    '<div class="receipt-line"><span>Payment</span><span>' + x.pay + '</span></div>' +
    '<div class="receipt-line"><span>Cashier</span><span>' + x.by + '</span></div>';
  box.classList.remove('hidden');
}
