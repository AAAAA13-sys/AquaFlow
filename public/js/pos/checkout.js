// AquaFlow CASHIER terminal - checkout, payment, debt settlement, registration,
// step navigation and receipt printing.
//
// Sales are priced by the server (api/transactions.php). The client sends the
// cart, the customer, the order type and the payment method; it never sets
// prices, VAT or totals.

// ---------- Step switching (one panel at a time) ----------
let posCur = 1;

/**
 * What each stage needs before the cashier may move past it.
 *
 * Stage 3 has no gate of its own: Complete Sale re-checks everything and the
 * server validates the payload again.
 */
const STAGE_RULES = {
  1: {
    message: 'Select a customer before continuing.',
    ok: () => hasCustomer(),
    focus: () => document.getElementById('custSearch'),
  },
  2: {
    message: 'Add at least one item before continuing.',
    ok: () => POS.cart.length > 0,
    focus: () => document.getElementById('productGrid'),
  },
};

/** The reason a stage cannot be left yet, or null when it is satisfied. */
function stageProblem(step) {
  const rule = STAGE_RULES[step];
  if (!rule || rule.ok()) return null;

  return rule.message;
}

/** Shows or clears the inline validation message above Back/Next. */
function showStageError(message) {
  const el = document.getElementById('posStageError');
  if (!el) return;

  if (!message) {
    el.textContent = '';
    el.classList.add('hidden');
    return;
  }

  el.textContent = message;
  el.classList.remove('hidden');
}

/** Paints a stage. No validation - callers decide whether the move is legal. */
function renderStage(step) {
  posCur = step;
  showStageError(null);

  document.querySelectorAll('.pos-col').forEach(col => {
    col.classList.toggle('pos-active', Number(col.dataset.step) === posCur);
  });
  document.querySelectorAll('#posSteps .pos-step').forEach(button => {
    button.classList.toggle('active', Number(button.dataset.step) === posCur);
  });

  const back = document.getElementById('posBack');
  if (back) back.disabled = posCur === 1;
  const next = document.getElementById('posNext');
  if (next) next.textContent = posCur >= 3 ? 'Complete Sale' : 'Next';

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

/** Puts the caret where the cashier has to act. */
function focusStageField(step) {
  const rule = STAGE_RULES[step];
  const target = rule && rule.focus ? rule.focus() : null;

  if (target && typeof target.focus === 'function') target.focus();
}

/**
 * Guards the move out of a stage. Returns true when the cashier may proceed.
 */
function validateStage(step) {
  const problem = stageProblem(step);
  showStageError(problem);

  if (problem === null) return true;

  focusStageField(step);

  return false;
}

/** Clears a stale message once the cashier has fixed the problem. */
function refreshStageValidation() {
  const el = document.getElementById('posStageError');
  if (el && !el.classList.contains('hidden') && stageProblem(posCur) === null) {
    showStageError(null);
  }
}

/**
 * Shows a stage and, for forward moves, validates every stage being passed.
 * When a stage is incomplete the terminal lands on that stage and says why,
 * rather than leaving the cashier on a panel that is already finished.
 *
 * `force` is for genuine shortcuts such as jumping to settle a debt.
 */
function posStep(n, force) {
  const target = Math.min(3, Math.max(1, n));

  if (!force && target > posCur) {
    for (let step = posCur; step < target; step++) {
      const problem = stageProblem(step);

      if (problem !== null) {
        if (step !== posCur) renderStage(step);
        showStageError(problem);
        focusStageField(step);

        return;
      }
    }
  }

  renderStage(target);
}

function posPrev() {
  if (posCur > 1) posStep(posCur - 1);
}

function posNext() {
  // Leaving a stage requires its content to be valid.
  if (!validateStage(posCur)) return;

  if (posCur >= 3) completeSale(typeof SESSION !== 'undefined' ? SESSION.name : 'Cashier');
  else posStep(posCur + 1);
}

// Shared boot: role guard, session name, live clock and live data.
async function bootCashierPortal() {
  const session = guardCashier();
  if (!session) return null;

  const who = document.getElementById('who');
  if (who) who.textContent = session.name;
  const clock = document.getElementById('clock');
  const tick = () => { if (clock) clock.textContent = new Date().toLocaleString(); };
  tick();
  setInterval(tick, 1000);

  try {
    await loadFromServer();
  } catch (error) {
    console.warn('AquaFlow: API unavailable, using cached data. ' + error.message);
  }

  return session;
}

// ---------- Payment method ----------

/** Charging to account needs a registered customer, and so does delivery. */
function walkInSelected() {
  const c = posCust();
  return c === null || c.name === 'Walk-in Guest';
}

function setPay(p) {
  if (p === 'Account' && walkInSelected()) {
    alert('Charging to account requires a registered customer.');
    p = 'Cash';
  }

  POS.pay = p;

  const methods = { Cash: 'pCash', GCash: 'pGCash', Account: 'pAccount' };
  Object.keys(methods).forEach(key => {
    const el = document.getElementById(methods[key]);
    if (el) el.className = 'btn ' + (p === key ? 'btn-primary' : 'btn-ghost');
  });

  const tenderWrap = document.getElementById('tenderWrap');
  if (tenderWrap) tenderWrap.style.display = p === 'Cash' ? 'block' : 'none';

  const qr = document.getElementById('gcashBox');
  if (qr) qr.classList.toggle('hidden', p !== 'GCash');

  savePOSState();
  renderPOS();
}

/** Explains why account payment is unavailable. */
function updatePayLock() {
  const locked = walkInSelected();
  const acct = document.getElementById('pAccount');
  if (acct) acct.classList.toggle('is-locked', locked);

  const note = document.getElementById('payLockNote');
  if (note) {
    note.textContent = locked
      ? 'Charging to account is available for registered customers only.'
      : '';
  }
}

/** Keeps state honest if the customer changes back to a walk-in. */
function enforcePaymentRules() {
  if (POS.pay !== 'Account' || !walkInSelected()) {
    return;
  }

  POS.pay = 'Cash';
  const cash = document.getElementById('pCash');
  const acct = document.getElementById('pAccount');
  if (cash) cash.className = 'btn btn-primary';
  if (acct) acct.className = 'btn btn-ghost';
}

/** Quick denominations: Exact fills the total, the rest accumulate cash. */
function applyQuickCash(value) {
  const tender = document.getElementById('tender');
  if (!tender) return;

  if (value === 'exact') {
    tender.value = Math.round(posTotals().total * 100) / 100;
  } else {
    tender.value = (+tender.value || 0) + Number(value);
  }

  renderPOS();
}

// ---------- Debt settlement ----------

function toggleDebtSettle() {
  const box = document.getElementById('debtSettleBox');
  if (!box) return;

  if (!hasCustomer()) {
    showStageError('Select a customer before settling a debt.');
    return;
  }

  box.classList.toggle('hidden');
  updateDebtPanel();

  if (!box.classList.contains('hidden')) {
    // Settling a debt is a genuine shortcut: it does not need a cart.
    posStep(3, true);
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const pay = document.getElementById('debtPay');
    if (pay) pay.focus();
  }
}

function updateDebtPanel() {
  const c = posCust();
  const pay = document.getElementById('debtPay');
  const view = document.getElementById('custDebtView');
  const after = document.getElementById('debtAfter');
  const debt = c === null ? 0 : Number(c.debt);

  if (view) view.textContent = '₱' + debt.toLocaleString();
  if (after) after.textContent = '₱' + Math.max(0, debt - (+((pay && pay.value) || 0))).toLocaleString();
}

async function settleDebt() {
  const c = posCust();
  if (c === null) {
    showStageError('Select a customer before settling a debt.');
    return;
  }

  const pay = document.getElementById('debtPay');
  const amount = +((pay && pay.value) || 0);
  if (amount <= 0) { alert('Enter a payment amount.'); return; }

  try {
    const result = await API.settleDebt(c.id, amount);
    API.replaceCustomer(result.customer);
    if (pay) pay.value = 0;
    saveDB();
    updateDebtPanel();
    renderPOS();
    alert('Debt payment recorded: ₱' + Number(result.applied).toLocaleString() +
      '. Remaining balance ₱' + Number(result.customer.debt).toLocaleString() + '.');
  } catch (error) {
    alert(error.message || 'Could not record the payment.');
  }
}

// ---------- Quick customer registration (name + address) ----------

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

async function saveNewCustomer() {
  const nameEl = document.getElementById('regName');
  const addrEl = document.getElementById('regAddr');
  const name = ((nameEl && nameEl.value) || '').trim();

  if (!name) { alert('Customer name is required.'); return; }

  try {
    const result = await API.createCustomer({
      name: name,
      address: ((addrEl && addrEl.value) || '').trim(),
    });

    API.replaceCustomer(result.customer);
    POS.custId = result.customer.id;
    saveDB();
    closeRegisterModal();
    if (nameEl) nameEl.value = '';
    if (addrEl) addrEl.value = '';
    renderPOS();
    alert('Customer registered: ' + result.customer.name);
  } catch (error) {
    alert(error.message || 'Could not register the customer.');
  }
}

// ---------- Checkout ----------

async function syncInventory() {
  try {
    const data = await API.inventory();
    DB.inventory = data.inventory;
  } catch (error) {
    console.warn('AquaFlow: could not refresh inventory. ' + error.message);
  }
}

function showServerReceipt(result) {
  const meta = document.getElementById('rMeta');
  const body = document.getElementById('rBody');
  const box = document.getElementById('receipt');
  if (!meta || !body || !box) return;

  const tx = result.transaction;
  const totals = result.totals;
  const money = n => '₱' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  meta.textContent = tx.no + ' | ' + new Date().toLocaleString() + ' | ' + (tx.by || '');

  body.innerHTML =
    POS.cart.map(i => '<div class="receipt-line"><span>' + i.q + 'x ' + i.name + '</span><span>' + money(i.q * i.price) + '</span></div>').join('') +
    '<div class="receipt-line"><span>Vatable Sales</span><span>' + money(totals.vatable) + '</span></div>' +
    '<div class="receipt-line"><span>12% VAT (incl.)</span><span>' + money(totals.vat) + '</span></div>' +
    '<div class="receipt-line total"><span>Total</span><span>' + money(totals.total) + '</span></div>' +
    '<div class="receipt-line"><span>Customer</span><span>' + tx.cust + '</span></div>' +
    '<div class="receipt-line"><span>Order type</span><span>' + tx.type + '</span></div>' +
    '<div class="receipt-line"><span>' + tx.pay + (tx.pay === 'Cash' ? ' / Tendered ' + money(totals.cash_tendered) : '') + '</span><span>' +
    (tx.pay === 'Cash' ? 'Change ' + money(totals.cash_change)
      : tx.pay === 'Account' ? 'Debt ' + money(result.customer.debt) : 'Paid') + '</span></div>';

  box.classList.remove('hidden');
}

async function completeSale(sessionName) {
  // Same inline validation the stage gates use, so the cashier always sees one
  // consistent message instead of a browser alert.
  if (!hasCustomer()) { showStageError('Select a customer before completing the sale.'); return; }
  if (!POS.cart.length) { showStageError('Add at least one item before completing the sale.'); return; }

  const c = posCust();
  if (POS.type === 'Delivery' && c.name === 'Walk-in Guest') {
    showStageError('Delivery requires a registered customer.');
    return;
  }

  const tenderEl = document.getElementById('tender');
  const ten = tenderEl ? (+tenderEl.value || 0) : 0;
  const t = posTotals();

  if (POS.pay === 'Cash' && ten < t.total) {
    // Say exactly how much is still owed, and put the caret back in the field.
    showStageError('Cash tendered is less than the total due by ₱' + (t.total - ten).toFixed(2) + '.');
    if (tenderEl) tenderEl.focus();
    return;
  }

  showStageError(null);

  const payload = {
    customer_id: POS.custId,
    order_type: POS.type,
    payment_method: POS.pay,
    cash_tendered: POS.pay === 'Cash' ? ten : 0,
    items: POS.cart.map(i => ({ product_id: i.id, quantity: i.q })),
  };

  let result;
  try {
    result = await API.createTransaction(payload);
  } catch (error) {
    alert(error.message || 'Could not save the sale.');
    return;
  }

  API.replaceCustomer(result.customer);
  DB.transactions.unshift(result.transaction);
  DB.queue = result.queue;

  const nextNumber = parseInt(String(result.transaction.no).replace(/\D/g, ''), 10);
  if (!isNaN(nextNumber)) POS.orderN = nextNumber + 1;

  await syncInventory();
  showServerReceipt(result);
  saveDB();
  renderPOS();
}

function closeReceipt() {
  const box = document.getElementById('receipt');
  if (box) box.classList.add('hidden');

  POS.cart = [];
  POS.custId = null;
  POS.type = 'Walk-in';
  POS.pay = 'Cash';

  const tender = document.getElementById('tender');
  if (tender) tender.value = 0;

  saveDB();
  setType('Walk-in');
  setPay('Cash');
  renderPOS();
  posStep(1);
}

/** Used by the history page, where the receipt is only a viewer. */
function closeDrawerReceipt() {
  const box = document.getElementById('receipt');
  if (box) box.classList.add('hidden');
}

function reprint(no) {
  const x = DB.transactions.find(t => t.no === no);
  if (!x) return;

  const meta = document.getElementById('rMeta');
  const body = document.getElementById('rBody');
  const box = document.getElementById('receipt');
  if (!meta || !body || !box) return;

  meta.textContent = x.no + ' | ' + x.date + ' ' + x.t + ' | ' + x.cust;
  body.innerHTML =
    '<div class="receipt-line"><span>' + x.type + ' ' + x.gal + '</span><span>₱' + x.total + '</span></div>' +
    '<div class="receipt-line"><span>Payment</span><span>' + x.pay + '</span></div>' +
    '<div class="receipt-line"><span>Cashier</span><span>' + x.by + '</span></div>';

  box.classList.remove('hidden');
}

/**
 * Prints the receipt only. `printing-receipt` switches on the print stylesheet
 * that hides the rest of the terminal; it has no on-screen effect.
 */
function printReceipt() {
  document.body.classList.add('printing-receipt');
  window.addEventListener('afterprint', () => {
    document.body.classList.remove('printing-receipt');
  }, { once: true });

  window.print();
}
