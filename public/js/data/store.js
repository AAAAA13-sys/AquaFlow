// AquaFlow CASHIER terminal - state persistence layer.
//
// The cart, the chosen customer, the order type and the payment method survive
// navigation and refreshes. There is no container-custody state any more: the
// station owns no jugs, so nothing is tracked against a liability.

function savePOSState() {
  if (typeof POS === 'undefined') return;
  try {
    localStorage.setItem('aquaflow_pos', JSON.stringify({
      orderN: POS.orderN,
      cart: POS.cart,
      custId: POS.custId,
      type: POS.type,
      pay: POS.pay
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
    ['orderN', 'cart', 'custId', 'type', 'pay'].forEach(k => {
      if (saved[k] !== undefined) POS[k] = saved[k];
    });
    if (!Array.isArray(POS.cart)) POS.cart = [];
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

/**
 * Replace the local snapshot with live data from MySQL.
 * The localStorage copy stays as an offline cache.
 */
function applyServerSnapshot(snapshot) {
  if (!snapshot || typeof snapshot !== 'object') return false;
  ['products', 'inventory', 'advisories', 'customers', 'suppliers', 'transactions', 'queue', 'history', 'history30', 'forecast7', 'forecasts', 'model', 'settings'].forEach(key => {
    if (snapshot[key] !== undefined) DB[key] = snapshot[key];
  });
  if (snapshot.users) DB.users = snapshot.users;
  saveDB();
  return true;
}

/**
 * Load the station snapshot from the API.
 * Throws when the server is unreachable so callers can fall back to the cache.
 */
async function loadFromServer() {
  const snapshot = await API.bootstrap();
  return applyServerSnapshot(snapshot);
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

// Normalize any legacy transactions that predate the date field so filters work.
DB.transactions.forEach((t, i) => {
  if (!t.date) {
    const d = new Date();
    d.setDate(d.getDate() - (i % 3));
    t.date = d.toISOString().split('T')[0];
  }
});

saveDB();
