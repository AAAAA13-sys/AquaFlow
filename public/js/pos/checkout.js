// AquaFlow CASHIER terminal - checkout, payment, debt settlement, registration,
// step navigation and receipt printing.
//
// Sales are priced by the server (POST /api/transactions). The client sends the
// cart, the customer and the order type; it never sets prices, VAT, totals or
// the payment method - the order type decides how the sale is settled.

// ---------- Step switching (one stage on screen at a time) ----------
let posCur = 1;
function getPosCur() { return posCur; }

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
    message: 'Add an item and wait for a successful stock check before continuing.',
    ok: () => POS.cart.length > 0 && typeof stockPreview !== 'undefined' && stockPreview?.valid === true,
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

  return isDelivery() ? 'Save Delivery & Print Slip' : 'Complete & Print Receipt';
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
  if (posCur > 1 && posChangeAllowed()) posStep(posCur - 1);
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
  startPortalClock('clock');

  if (session.must_change_password) { await openProfileModal(); return null; }

  try {
    await loadFromServer();
  } catch (error) {
    if (error.data?.code === 'password_change_required') { await openProfileModal(); return null; }
    console.warn('AquaFlow: API unavailable, using cached data. ' + error.message);
  }

  return session;
}

/** Quick denominations: Exact fills the total, the rest accumulate cash. */
const MAX_TENDER = 1000000;

function applyQuickCash(value) {
  const tender = document.getElementById('tender');
  if (!tender) return;

  if (value === 'exact') {
    tender.value = Math.round(posTotals().total * 100) / 100;
  } else {
    tender.value = (+tender.value || 0) + Number(value);
  }

  clampTender();
}

/** Input handler for the tender box: clamps to [0, 1,000,000] then re-renders. */
function clampTender() {
  const tender = document.getElementById('tender');
  if (tender) {
    let current = Number(tender.value) || 0;
    if (current > MAX_TENDER) current = MAX_TENDER;
    if (current < 0) current = 0;
    tender.value = String(Math.round(current * 100) / 100);
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

  if (pay) {
    pay.max = String(Math.max(0, debt));
    // Clamp typed values so the payment can never exceed the remaining balance.
    const current = Number(pay.value) || 0;
    if (current > debt) pay.value = String(Math.max(0, debt));
    if (current < 0) pay.value = '0';
  }
  if (view) view.textContent = peso(debt);
  if (after) after.textContent = peso(Math.max(0, debt - (+((pay && pay.value) || 0))));
}

/** Input handler for the debt payment box: clamps to [0, balance] then re-renders. */
function clampDebtPay() {
  const c = posCust();
  const pay = document.getElementById('debtPay');
  if (pay) {
    const debt = c === null ? 0 : Number(c.debt);
    let current = Number(pay.value) || 0;
    if (current > debt) current = debt;
    if (current < 0) current = 0;
    // Round to 2 decimals to match money handling.
    pay.value = String(Math.round(current * 100) / 100);
  }
  renderPOS();
}

async function settleDebt() {
  if (settleDebt.busy || completeSale.busy) return;
  settleDebt.busy = true;
  try {
    const c = posCust();
    if (c === null) {
      showStageError('Select a customer before settling a debt.');
      return;
    }

    const pay = document.getElementById('debtPay');
    const amount = +((pay && pay.value) || 0);
    if (!Number.isFinite(amount) || amount <= 0) { showStageError('Enter a payment amount.'); return; }
    if (Math.round(amount * 100) > Math.round(Number(c.debt) * 100)) { showStageError('Payment cannot exceed the outstanding balance.'); return; }

    if (!await appConfirm('Record ' + money(amount) + ' payment from ' + c.name + '?', 'Record payment')) return;
    try {
      const result = await API.settleDebt(c.id, amount);
      API.replaceCustomer(result.customer);
      if (pay) pay.value = 0;
      saveDB();
      updateDebtPanel();
      renderPOS();
      showStageError(null);
      API.replaceSnapshot('transactions', result.transaction, row => row.no);
      reprint(result.transaction.no);
      Submissions.clear();
    } catch (error) {
      showStageError(error.message || 'Could not record the payment.');
    }
  } finally { settleDebt.busy = false; }
}

// ---------- Quick customer registration (name + address) ----------

function openRegisterModal() {
  const m = document.getElementById('registerModal');
  if (!m) return;
  if (!m.open) m.showModal();
  const error = document.getElementById('registrationError'); if (error) error.textContent = '';
  const name = document.getElementById('regName');
  if (name) name.focus();
}

function closeRegisterModal() {
  const m = document.getElementById('registerModal');
  if (m) m.close();
}

async function requestCloseRegisterModal() {
  if (requestCloseRegisterModal.busy || saveNewCustomer.busy) return;
  requestCloseRegisterModal.busy = true;
  try {
    if (await appConfirm('Cancel customer registration? Your entered details will be kept until you leave this page.', 'Cancel registration')) closeRegisterModal();
  } finally { requestCloseRegisterModal.busy = false; }
}

async function confirmCashierLogout() {
  if (confirmCashierLogout.busy) return;
  confirmCashierLogout.busy = true;
  try {
    if (await appConfirm('Log out of the cashier portal?', 'Log out')) logout();
  } finally { confirmCashierLogout.busy = false; }
}

async function saveNewCustomer() {
  if (saveNewCustomer.busy) return;
  const nameEl = document.getElementById('regName');
  const addrEl = document.getElementById('regAddr');
  const contactEl = document.getElementById('regContact');
  const contact = (contactEl?.value || '').trim();
  const name = ((nameEl && nameEl.value) || '').trim();

  const registrationError = document.getElementById('registrationError');
  if (registrationError) registrationError.textContent = '';
  if (/\d/.test(name) || !/^(?=.*\p{L})[\p{L}\s.'-]+$/u.test(name) || name.length > 150) {
    if (registrationError) registrationError.textContent = 'Enter a customer name using letters only â€” numbers are not allowed (maximum 150 characters).';
    nameEl?.focus(); return;
  }
  if (!/^(09\d{9}|\+639\d{9})$/.test(contact)) {
    if (registrationError) registrationError.textContent = 'Enter a mobile number: 09XXXXXXXXX or +639XXXXXXXXX.';
    contactEl?.focus(); return;
  }
  saveNewCustomer.busy = true;
  if (!await appConfirm('Register ' + name + ' at ' + (addrEl?.value.trim() || 'no address') + '?', 'Register customer')) { saveNewCustomer.busy = false; return; }

  try {
    const result = await API.createCustomer({
      name: name,
      contact,
      address: ((addrEl && addrEl.value) || '').trim(),
    });

    API.replaceCustomer(result.customer);
    await pickCust(result.customer.id);
    saveDB();
    closeRegisterModal();
    if (nameEl) nameEl.value = '';
    if (addrEl) addrEl.value = '';
    if (contactEl) contactEl.value = '';
    renderPOS();
    showStageError('Customer registered: ' + result.customer.name);
  } catch (error) {
    const errorField = document.getElementById('registrationError');
    if (errorField) errorField.textContent = error.message || 'Could not register the customer.';
  } finally { saveNewCustomer.busy = false; }
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

function receiptContents(tx) {
  const line = (label, value, total = false) => '<div class="receipt-line' + (total ? ' total' : '') + '"><span>' + esc(label) + '</span><span>' + esc(value) + '</span></div>';
  const debtPayment = tx.type === 'Debt Payment';
  const isWalkIn = (!tx.cust || tx.cust === 'Walk-in Guest' || tx.type === 'Walk-in') && !tx.customer_id;
  return line('Customer', tx.cust || 'Walk-in Guest') + line('Order type', tx.type) +
    (tx.delivery_address ? line('Delivery address', tx.delivery_address) : '') +
    (tx.items || []).map(item => line(item.quantity + ' Ã— ' + item.name + ' @ ' + money(item.unit_price), money(item.line_total))).join('') +
    (debtPayment ? '' : line('Vatable sales', money(tx.vatable || 0)) + line('12% VAT (inclusive)', money(tx.vat || 0))) +
    line(debtPayment ? 'Payment received' : 'Total', money(tx.total), true) +
    (tx.pay === 'Cash' && tx.cash_tendered != null && Number(tx.cash_tendered) >= Number(tx.total) ? line('Cash tendered', money(tx.cash_tendered)) + line('Change', money(tx.cash_change || 0)) : '') +
    (tx.balance_after != null && !isWalkIn ? line('Account balance after transaction', money(tx.balance_after)) : '') +
    line('Cashier', tx.by || 'â€”');
}

function displayTransactionReceipt(tx, reprint = false) {
  const meta = document.getElementById('rMeta'), body = document.getElementById('rBody'), box = document.getElementById('receipt');
  if (!meta || !body || !box) return;
  const timeStr = [tx.date, tx.t].filter(Boolean).join(' ');
  meta.textContent = tx.no + (timeStr ? ' Â· ' + timeStr : '');
  body.innerHTML = receiptContents(tx);
  box.classList.remove('hidden');
  if (typeof Submissions !== 'undefined') Submissions.render();
}

function showServerReceipt(result) {
  displayTransactionReceipt({...result.transaction, ...result.totals});
}

async function completeSale(sessionName) {
  if (completeSale.busy || settleDebt.busy) return;
  completeSale.busy = true;
  try {
    // Same inline validation the stage gates use, so the cashier always sees one
    // consistent message instead of a browser alert.
    if (!hasCustomer()) { showStageError('Select a customer before completing the sale.'); return; }
    if (!POS.cart.length) { showStageError('Add at least one item before completing the sale.'); return; }

    const c = posCust();
    if (isDelivery() && (c.is_guest || c.name === 'Walk-in Guest')) {
      showStageError('Delivery requires a registered customer.');
      return;
    }

    if (isDelivery() && (!c.addr?.trim() || c.addr.trim() === '-')) {
      showStageError('Delivery needs a customer address. Ask the owner to update this customer before taking the order.'); return;
    }

    const tenderEl = document.getElementById('tender');
    const ten = tenderEl ? (+tenderEl.value || 0) : 0;
    const t = posTotals();

    const payToRider = isDelivery() && (POS.deliveryPay || 'rider') === 'rider';

    // If payment is collected now (Walk-in or Delivery paying cashier)
    if (!payToRider && ten < t.total) {
      showStageError('Cash tendered is less than the total due by ' + money(t.total - ten) + '.');
      if (tenderEl) tenderEl.focus();
      return;
    }

    if (!payToRider && ten > MAX_TENDER) {
      showStageError('Amount tendered cannot exceed â‚±1,000,000.');
      if (tenderEl) tenderEl.focus();
      return;
    }

    showStageError(null);

    const payload = {
      customer_id: POS.custId,
      order_type: POS.type,
      payment_option: payToRider ? 'rider' : 'cashier',
      cash_tendered: payToRider ? 0 : ten,
      items: POS.cart.map(i => ({ product_id: i.id, quantity: i.q })),
    };

    const confirmNote = isDelivery()
      ? (payToRider ? ': ' + money(t.total) + ' (charged to customer account)?' : ': ' + money(t.total) + ' paid in cash now?')
      : ': ' + money(t.total) + ' paid in cash?';

    if (!await appConfirm('Confirm ' + POS.type + ' order for ' + c.name + confirmNote, 'Confirm sale')) return;
    const preview = await refreshStockPreview();
    if (!preview?.valid) {
      showStageError(preview ? (preview.shortages || ['Not enough stock. Reduce the affected quantities.']).join(' ') : 'Stock cannot be checked. Reconnect and retry before taking payment.');
      return;
    }
    let result;
    try {
      result = await API.createTransaction(payload);
    } catch (error) {
      showStageError(error.message || 'Could not save the sale.');
      return;
    }

    API.replaceCustomer(result.customer);
    API.replaceSnapshot('transactions', result.transaction, row => row.no);
    DB.queue = result.queue;

    const nextNumber = parseInt(String(result.transaction.no).replace(/\D/g, ''), 10);
    if (!isNaN(nextNumber)) POS.orderN = nextNumber + 1;

    await syncInventory();
    showServerReceipt(result);
    saveDB();
    renderPOS();
  } finally { completeSale.busy = false; }
}

function closeReceipt() {
  const submission = Submissions.pending();
  if (submission && submission.result) Submissions.clear();
  const box = document.getElementById('receipt');
  if (box) box.classList.add('hidden');

  POS.cart = [];
  POS.custId = null;
  POS.type = 'Walk-in';
  POS.deliveryPay = 'rider';

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
  const tx = DB.transactions.find(t => t.no === no);
  if (tx) displayTransactionReceipt(tx, true);
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
