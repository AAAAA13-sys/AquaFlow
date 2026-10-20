// A cashier's counter collection is a request, not a receipt.
//
// The POS must not print a receipt, must not move the customer's balance and
// must reserve the cash it just took - otherwise the same pesos could be
// collected twice before a manager approves the first collection.
//
// `showStageError` is defined in checkout.js itself and writes to the
// `posStageError` element, so the inline message is read from that node rather
// than captured from the context.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');

(async () => {
  const notices = [];
  let prints = 0, snapshots = 0, replaced = null;
  const elements = {
    debtPay: { value: '150' },
    posStageError: { textContent: '', classList: { add() {}, remove() {} } }
  };

  const ctx = vm.createContext({
    document: { getElementById: id => elements[id] || null, querySelectorAll: () => [] },
    POS: { cart: [], custId: 7, type: 'Walk-in' },
    DB: { customers: [] },
    posChangeAllowed: () => true,
    hasCustomer: () => true,
    posCust: () => ({
      id: 7, name: 'Santos Family', debt: 150,
      settlement_pending: 0, collectible: 150
    }),
    isDelivery: () => false,
    posTotals: () => ({ total: 0 }),
    money: n => 'P' + Number(n).toFixed(2),
    appConfirm: async () => true,
    appNotice: m => notices.push(m),
    saveDB: () => {},
    updateDebtPanel: () => {},
    renderPOS: () => {},
    reprint: () => { prints++; },
    API: {
      settleDebt: async () => ({
        replayed: false, applied: 0, pending_approval: true,
        message: 'Collection recorded. A manager must approve it before the customer\'s balance changes.',
        request: { id: 1, type: 'settlement.create', state: 'pending' },
        customer: { id: 7, name: 'Santos Family', debt: 150, settlement_pending: 150, collectible: 0 }
      }),
      replaceCustomer: c => { replaced = c; },
      replaceSnapshot: () => { snapshots++; }
    },
    Submissions: { clear: () => {} }
  });

  vm.runInContext(fs.readFileSync('public/js/pos/checkout.js', 'utf8'), ctx);

  await ctx.settleDebt();

  // The balance must not move, and no receipt may be issued for money a manager
  // has not yet approved.
  assert.equal(prints, 0, 'a pending collection must not print a receipt');
  assert.equal(snapshots, 0, 'a pending collection must not post a transaction snapshot');
  assert.equal(replaced.settlement_pending, 150, 'the reservation from the server must be reflected');
  assert.equal(replaced.collectible, 0, 'nothing is collectible while the collection waits');
  assert.ok(notices.some(n => /manager must approve/i.test(n)), 'the cashier must be told approval is required');
  assert.ok(!notices.some(n => /payment recorded|settled/i.test(n)), 'the debt must not be reported as settled');

  // With cash already reserved, the counter must refuse a second collection of
  // the same balance before it reaches the server.
  let calls = 0;
  ctx.posCust = () => ({ id: 7, name: 'Santos Family', debt: 150, settlement_pending: 150, collectible: 0 });
  ctx.API.settleDebt = async () => { calls++; return { pending_approval: true, customer: replaced }; };
  // The first collection zeroed the field; a fresh attempt must re-enter an
  // amount for the reservation guard to be what blocks it.
  elements.debtPay.value = '150';
  elements.posStageError.textContent = '';
  await ctx.settleDebt();
  assert.equal(calls, 0, 'a reserved balance must not be collected again');
  assert.match(elements.posStageError.textContent, /already waiting for a manager/i, 'the cashier must be told why the collection is blocked');

  console.log('PASS: cashier collections are reserved requests with no receipt, and a reserved balance cannot be collected twice.');
})().catch(error => { console.error(error); process.exitCode = 1; });
