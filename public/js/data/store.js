const money = n => '₱' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// Shared CSV download transport; callers retain their own bytes and filenames.
function downloadCSV(csv, filename) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  a.download = filename;
  a.click();
}

// Shared HTML escaping for user-supplied values inserted via innerHTML.
function esc(value) {
  return String(value === undefined || value === null ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function startPortalClock(id) {
  const clock = document.getElementById(id);
  const tick = () => { if (clock) clock.textContent = new Date().toLocaleString(); };
  tick();
  setInterval(tick, 1000);
}

// AquaFlow CASHIER terminal - state persistence layer.
//
// The cart, the chosen customer, the order type and the payment method survive
// navigation and refreshes. There is no container-custody state any more: the
// station owns no jugs, so nothing is tracked against a liability.

// ---- Shared icon helper -----------------------------------------------------
// Lives here rather than in a page-specific module because scripts-base is
// loaded by BOTH the cashier terminal and the owner portal, before either
// page's own scripts. Defining it only in js/pos/cart.js left the owner portal
// throwing "icon is not defined" as soon as it rendered a ledger row.

// Absolute URL to the icon sprite. The layout sets window.SPRITE_URL via
// asset(); the fallback keeps this file usable if it is ever loaded on its own.
var SPRITE_URL = (typeof window !== 'undefined' && window.SPRITE_URL)
  ? window.SPRITE_URL
  : '/icons/sprite.svg';

// Renders one icon from the sprite. Inline markup (no fetch) so rows can be
// painted synchronously while a table or list is being built.
function icon(id, extraClass) {
  return '<svg class="icon' + (extraClass ? ' ' + extraClass : '') + '" aria-hidden="true"><use href="' +
    SPRITE_URL + '#' + id + '"></use></svg>';
}

function savePOSState() {
  if (typeof POS === 'undefined') return;
  try {
    localStorage.setItem('aquaflow_pos', JSON.stringify({
      orderN: POS.orderN,
      cart: POS.cart,
      custId: POS.custId,
      type: POS.type
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
    ['orderN', 'cart', 'custId', 'type'].forEach(k => {
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

// Shared search and chronological ordering for portal tables and directories.
function filterTableRows(rows, target) {
  const search = document.getElementById(target + 'Search');
  const order = document.getElementById(target + 'Order');
  if (!search && !order) return rows;
  const query = (search?.value || '').trim().toLowerCase();
  const stamp = row => {
    const date = row.created_at || row.sold_at || (row.date ? row.date + 'T' + (row.t || '00:00:00') : '');
    const parsed = Date.parse(date);
    return Number.isFinite(parsed) ? parsed : Number(row.id) || 0;
  };
  const direction = order?.value === 'oldest' ? 1 : -1;
  return rows.filter(row => Object.values(row).some(value =>
    value != null && typeof value !== 'object' && String(value).toLowerCase().includes(query)
  )).sort((a, b) => direction * (stamp(a) - stamp(b) || (Number(a.id) || 0) - (Number(b.id) || 0)));
}

function toggleAddForm(button) {
  const panel = document.getElementById(button.getAttribute('aria-controls'));
  if (!panel) return;
  const opening = panel.classList.contains('hidden');
  panel.classList.toggle('hidden', !opening);
  button.setAttribute('aria-expanded', String(opening));
  if (opening) panel.querySelector('input, select')?.focus();
}

function showTableEmpty(body, columns) {
  if (!body.innerHTML.trim()) body.innerHTML = '<tr><td colspan="' + columns + '" class="empty-cell">No matching records.</td></tr>';
}

function startPageRefresh(refresh) {
  return setInterval(() => { if (!document.hidden) Promise.resolve(refresh()).catch(error => console.warn(error.message)); }, 30000);
}
