// AquaFlow CASHIER terminal - state persistence layer
// Extends the original prototype store so the split step pages share one live POS state:
// the cart, customer, order type, payment method, intake counts and shape all
// survive navigation between the 3 CASHIER/ pages.

function savePOSState() {
  if (typeof POS === 'undefined') return;
  try {
    localStorage.setItem('aquaflow_pos', JSON.stringify({
      orderN: POS.orderN,
      cart: POS.cart,
      custId: POS.custId,
      type: POS.type,
      pay: POS.pay,
      vat: POS.vat,
      discount: POS.discount,
      shape: POS.shape,
      intake: POS.intake,
      dmg: POS.dmg
    }));
  } catch (e) {
    console.warn('Could not save POS state to localStorage', e);
  }
}

function restorePOSState() {
  if (typeof POS === 'undefined') return;
  try {
    const saved = JSON.parse(localStorage.getItem('aquaflow_pos') || 'null');
    if (!saved) return;
    ['orderN', 'cart', 'custId', 'type', 'pay', 'vat', 'discount', 'shape', 'intake', 'dmg'].forEach(k => {
      if (saved[k] !== undefined) POS[k] = saved[k];
    });
    if (!Array.isArray(POS.cart)) POS.cart = [];
    if (!POS.intake || typeof POS.intake !== 'object') POS.intake = { oS: 0, iS: 0, oR: 0, iR: 0 };
  } catch (e) {
    console.warn('Could not load POS state from localStorage', e);
  }
}

function saveDB() {
  try {
    localStorage.setItem('aquaflow_db', JSON.stringify(DB));
  } catch (e) {
    console.warn('Could not save state to localStorage', e);
  }
  savePOSState();
}

function loadDB() {
  try {
    const saved = localStorage.getItem('aquaflow_db');
    if (saved) {
      const parsed = JSON.parse(saved);
      delete parsed.users; // credentials always come from initial-data.js, never localStorage
      Object.assign(DB, parsed);
    }
  } catch (e) {
    console.warn('Could not load state from localStorage', e);
  }
}

loadDB();

// Normalize any legacy transactions that predate the date field so sales filters work.
DB.transactions.forEach((t, i) => {
  if (!t.date) {
    const d = new Date();
    d.setDate(d.getDate() - (i % 3));
    t.date = d.toISOString().split('T')[0];
  }
});

saveDB();
