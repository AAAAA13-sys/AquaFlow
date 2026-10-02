// AquaFlow CASHIER terminal - checkout, payment, debt settlement, registration,
// step navigation and receipt printing.
//
// Sales are priced by the server (POST /api/transactions). The client sends the
// cart, the customer and the order type; it never sets prices, VAT, totals or
// the payment method - the order type decides how the sale is settled.

// ---------- Step switching (one stage on screen at a time) ----------
let posCur = 1;

/**
 * What each stage needs before the cashier may move past it.
 *
 * Stage 3 has no gate of its own: completing the sale re-checks everything and
 * the server validates the payload again.
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

/** The action bar's primary button changes label with the stage and order type. */
function primaryLabel(step) {
  if (step === 1) return 'Next';
  if (step === 2) return 'Proceed to Summary';

  return isDelivery() ? 'Dispatch Delivery & Print Slip' : 'Complete & Print Receipt';
}

/** Refreshes the action bar's primary button (its label depends on stage AND order type). */
function refreshPrimaryLabel() {
  const next = document.getElementById('posNext');
  if (next) next.textContent = primaryLabel(posCur);
}

/** Paints a stage. No validation - callers decide whether the move is legal. */
function renderStage(step) {
  posCur = step;
  showStageError(null);

  document.querySelectorAll('.pos-stage').forEach(section => {
    section.classList.toggle('pos-active', Number(section.dataset.step) === posCur);
  });
  document.querySelectorAll('#posSteps .pos-step').forEach(button => {
    button.classList.toggle('active', Number(button.dataset.step) === posCur);
  });

  const back = document.getElementById('posBack');
  if (back) back.disabled = posCur === 1;

  refreshPrimaryLabel();

  // Stage 3 shows either the cash tender panel or the account ledger notice.
  if (posCur === 3 && typeof renderPaymentPanel === 'function') renderPaymentPanel();
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

// ---------------------------------------------------------------------------
// Keyboard shortcuts
//
// Every key is delegated to the same functions the on-screen buttons call, so
// the hotkeys cannot bypass a stage-validity rule: Enter still runs
// validateStage(), and Esc still calls posPrev().
//
// Keys are ignored while the cashier is typing in a field, otherwise a customer
// name containing "enter"-adjacent keys would keep jumping the wizard.
// ---------------------------------------------------------------------------

// Each entry declares whether it may fire while the cashier is typing. Enter is
// blocked in a text field because it would otherwise advance the wizard while
// the cashier is still entering a customer name. F-keys and Escape are safe.
const POS_HOTKEYS = {
  Enter: { whileTyping: false, run: () => posNext() },
  Escape: { whileTyping: true, run: () => posPrev() },
  F2: { whileTyping: true, run: () => pickWalkIn() },
  F4: { whileTyping: true, run: () => setType(isDelivery() ? 'Walk-in' : 'Delivery') },
  F8: { whileTyping: true, run: () => { if (typeof toggleDebtSettle === 'function') toggleDebtSettle(); } },
};

/** True when focus is somewhere the cashier is typing text. */
function posTypingInField(target) {
  if (!target) return false;
  const tag = target.tagName;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
  return target.isContentEditable === true;
}

function initPosHotkeys() {
  document.addEventListener('keydown', event => {
    const binding = POS_HOTKEYS[event.key];
    if (!binding) return;

    if (event.ctrlKey || event.metaKey || event.altKey) return;
    if (!binding.whileTyping && posTypingInField(event.target)) return;

    event.preventDefault();
    binding.run();
  });
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

  // Keyboard shortcuts are a document-level listener, so they are installed
  // once per full page load (not per tab render) to avoid double-binding.
  if (!document.body.dataset.posHotkeys) {
    document.body.dataset.posHotkeys = '1';
    initPosHotkeys();
  }

  return session;
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

  if (view) view.textContent = peso(debt);
  if (after) after.textContent = peso(Math.max(0, debt - (+((pay && pay.value) || 0))));
}

async function settleDebt() {
  const c = posCust();
  if (c === null) {
    showStageError('Select a customer before settling a debt.');
    return;
  }

  const pay = document.getElementById('debtPay');
  const amount = +((pay && pay.value) || 0);
  if (amount <= 0) { showStageError('Enter a payment amount.'); return; }

  try {
    const result = await API.settleDebt(c.id, amount);
    API.replaceCustomer(result.customer);
    if (pay) pay.value = 0;
    saveDB();
    updateDebtPanel();
    renderPOS();
    showStageError(null);
    alert('Debt payment recorded: ' + peso(result.applied) +
      '. Remaining balance ' + peso(result.customer.debt) + '.');
  } catch (error) {
    showStageError(error.message || 'Could not record the payment.');
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
  const onAccount = tx.pay === 'Account';

  meta.textContent = tx.no + ' | ' + new Date().toLocaleString() + ' | ' + (tx.by || '');

  body.innerHTML =
    POS.cart.map(i => '<div class="receipt-line"><span>' + i.q + 'x ' + i.name + '</span><span>' + money(i.q * i.price) + '</span></div>').join('') +
    '<div class="receipt-line"><span>Vatable Sales</span><span>' + money(totals.vatable) + '</span></div>' +
    '<div class="receipt-line"><span>12% VAT (incl.)</span><span>' + money(totals.vat) + '</span></div>' +
    '<div class="receipt-line total"><span>Gross Total</span><span>' + money(totals.total) + '</span></div>' +
    '<div class="receipt-line"><span>Customer</span><span>' + tx.cust + '</span></div>' +
    '<div class="receipt-line"><span>Order type</span><span>' + tx.type + '</span></div>' +
    (onAccount
      ? '<div class="receipt-line"><span>CHARGED TO ACCOUNT</span><span>Balance ' + money(result.customer.debt) + '</span></div>'
      : '<div class="receipt-line"><span>Cash / Tendered ' + money(totals.cash_tendered) + '</span><span>Change ' + money(totals.cash_change) + '</span></div>');

  box.classList.remove('hidden');
}

async function completeSale(sessionName) {
  // Same inline validation the stage gates use, so the cashier always sees one
  // consistent message instead of a browser alert.
  if (!hasCustomer()) { showStageError('Select a customer before completing the sale.'); return; }
  if (!POS.cart.length) { showStageError('Add at least one item before completing the sale.'); return; }

  const c = posCust();
  if (isDelivery() && c.name === 'Walk-in Guest') {
    showStageError('Delivery requires a registered customer - the order is charged to their account.');
    return;
  }

  const tenderEl = document.getElementById('tender');
  const ten = tenderEl ? (+tenderEl.value || 0) : 0;
  const t = posTotals();

  // Only a walk-in handles cash; a delivery settles on the ledger.
  if (!isDelivery() && ten < t.total) {
    showStageError('Cash tendered is less than the total due by ' + money(t.total - ten) + '.');
    if (tenderEl) tenderEl.focus();
    return;
  }

  showStageError(null);

  // The server derives the payment method from the order type.
  const payload = {
    customer_id: POS.custId,
    order_type: POS.type,
    cash_tendered: isDelivery() ? 0 : ten,
    items: POS.cart.map(i => ({ product_id: i.id, quantity: i.q })),
  };

  let result;
  try {
    result = await API.createTransaction(payload);
  } catch (error) {
    showStageError(error.message || 'Could not save the sale.');
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

  const tender = document.getElementById('tender');
  if (tender) tender.value = 0;

  const debtBox = document.getElementById('debtSettleBox');
  if (debtBox) debtBox.classList.add('hidden');

  saveDB();
  setType('Walk-in');
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
    '<div class="receipt-line"><span>' + x.type + ' ' + x.gal + '</span><span>' + money(x.total) + '</span></div>' +
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
