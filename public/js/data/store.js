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
  // 'meta' was missing from this list, so DB.meta was never populated from the
  // server even though BootstrapController returns it. That silently disabled
  // the freshness stamp and pushed the "today" filter in renderInsights() onto
  // the client's clock rather than the server's.
  ['products', 'inventory', 'advisories', 'customers', 'suppliers', 'transactions', 'queue', 'history', 'history30', 'forecast7', 'forecasts', 'model', 'settings', 'meta'].forEach(key => {
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

// ---- Stock status helpers ---------------------------------------------------
// These sit here, not next to statusOf() in initial-data.js, because
// tests/frontend-shared.cjs loads store.js but NOT initial-data.js and stubs
// statusOf() itself. Keeping them in a file the harness does load means
// needsReorder() resolves against whichever statusOf is in scope.
//
// An item with a reorder point of 0 has no threshold set at all. The order
// engine still emits an advisory for it with a meaningless quantity, so these
// give the UI a third state: "not configured", not "critical".
function isUnconfigured(inv) {
  return Number(inv.rop) <= 0 && Number(inv.ss) <= 0;
}

// True only for items that genuinely need buying. Unconfigured items are
// excluded so they stop inflating "Needs Your Attention" and the advisory list.
function needsReorder(inv) {
  return !isUnconfigured(inv) && statusOf(inv)[0] !== 'OK';
}

// ---- Currency ---------------------------------------------------------------
// One symbol for the whole app. Several views used to concatenate a literal
// 'P' by hand, so a single screen could show "₱28,180.00" in a KPI and "P210"
// in the table underneath it.
//
// `money()` at the top of this file is the canonical formatter and is now used
// everywhere, including for the values that previously hardcoded 'P'.
// `pesoShort` is the whole-peso variant for badge/label slots where cents are
// noise.
//
// NB: do NOT redeclare `peso` here. js/pos/cart.js already declares it at
// top-level script scope, and a second `const peso` would throw
// "Identifier 'peso' has already been declared", breaking every admin page.
const pesoShort = n => '₱' + Math.round(Number(n || 0)).toLocaleString();

// ---- Wide-table affordance --------------------------------------------------
// Flags a .data-table-wrapper that still has hidden columns so the fade and the
// "scroll for more" note can appear. Without this the overflow was silent.
function markScrollableTables(noteId) {
  // Called from renderInsights(), so it runs under the CI harness's minimal
  // document stub as well as in a real page.
  if (typeof document.querySelectorAll !== 'function') return;

  document.querySelectorAll('.data-table-wrapper').forEach(wrap => {
    const overflowing = wrap.scrollWidth > wrap.clientWidth + 2;
    wrap.classList.toggle('is-scrollable-x', overflowing);
    if (noteId) {
      const note = document.getElementById(noteId);
      if (note) note.classList.toggle('is-needed', overflowing);
    }
  });
}

// `window` can exist but not be a real window: the CI harness
// (tests/frontend-shared.cjs) runs these scripts in a vm context with
// `window:{}`, so guarding on `typeof window` alone passes and then
// `window.addEventListener` throws. Feature-detect the method instead.
const onWindow = (type, fn, opts) => {
  if (typeof window === 'undefined' || typeof window.addEventListener !== 'function') return;
  window.addEventListener(type, fn, opts);
};

let scrollHintFrame = 0;
onWindow('resize', () => {
  if (typeof requestAnimationFrame !== 'function') {
    markScrollableTables('ledgerScrollNote');
    return;
  }
  cancelAnimationFrame(scrollHintFrame);
  scrollHintFrame = requestAnimationFrame(() => markScrollableTables('ledgerScrollNote'));
});

// ---- Inline term tips -------------------------------------------------------
// A click/tap/keyboard-toggle popover for abbreviations and jargon.
//
// Markup contract (see resources/views/partials/tip.blade.php and tipHtml()
// below for the JS-rendered equivalent):
//
//   <span class="af-tip" data-tip="Explanation text…">
//     <button type="button" class="af-tip-btn" aria-expanded="false"
//             aria-label="What is ROP?">?</button>
//   </span>
//
// A SINGLE popover element is parked on <body> and moved to whichever trigger
// is active. Anchoring the popover inside the trigger instead was tried first
// and does not work: every table lives in a .data-table-wrapper with
// overflow:auto, so an absolutely-positioned child is clipped by the scroll
// container and the explanation is invisible. `position: fixed` on a body-level
// node escapes both that clipping and any ancestor stacking context.
//
// One open popover at a time. Escape and any outside click close it, and focus
// returns to the trigger that opened it so keyboard users are not stranded.
let tipLayer = null;
let openTipBtn = null;

function tipLayerEl() {
  if (tipLayer && tipLayer.isConnected) return tipLayer;
  if (!tipLayer) {
    tipLayer = document.createElement('div');
    tipLayer.className = 'af-tip-pop af-tip-layer';
    tipLayer.setAttribute('role', 'tooltip');
    tipLayer.hidden = true;
  }
  document.body.appendChild(tipLayer);
  return tipLayer;
}

function closeTip() {
  if (tipLayer) tipLayer.hidden = true;
  if (!openTipBtn) return;
  openTipBtn.setAttribute('aria-expanded', 'false');
  openTipBtn = null;
}

function openTip(btn) {
  const text = btn.parentElement.getAttribute('data-tip');
  if (!text) return;

  closeTip();

  const pop = tipLayerEl();
  pop.textContent = text;
  pop.hidden = false;

  btn.setAttribute('aria-expanded', 'true');
  openTipBtn = btn;

  // Measure, then place: fixed coordinates relative to the viewport.
  const trigger = btn.getBoundingClientRect();
  pop.style.left = '0px';
  pop.style.top = '0px';

  const width = pop.offsetWidth;
  const height = pop.offsetHeight;
  const margin = 8;

  let left = trigger.left;
  if (left + width + margin > window.innerWidth) left = window.innerWidth - width - margin;
  if (left < margin) left = margin;

  // Prefer below; flip above when there is not enough room underneath.
  let top = trigger.bottom + 6;
  if (trigger.bottom + height + margin > window.innerHeight && trigger.top > height + margin) {
    top = trigger.top - height - 6;
  }

  pop.style.left = Math.round(left) + 'px';
  pop.style.top = Math.round(top) + 'px';
}

function toggleTip(btn) {
  if (openTipBtn === btn) closeTip();
  else openTip(btn);
}

if (typeof document !== 'undefined') {
  document.addEventListener('click', event => {
    const btn = event.target.closest('.af-tip-btn');
    if (btn) {
      event.preventDefault();
      event.stopPropagation();
      toggleTip(btn);
      return;
    }
    if (openTipBtn) closeTip();
  });

  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape' || !openTipBtn) return;
    const btn = openTipBtn;
    closeTip();
    btn.focus();
  });

  // A fixed popover would drift away from its trigger on scroll or resize.
  onWindow('resize', closeTip);
  onWindow('scroll', closeTip, true);
}

// Renders the same markup from JavaScript (tables built via innerHTML).
// `label` is the accessible name for the trigger, e.g. 'What does ROP mean?'.
function tipHtml(text, label) {
  return '<span class="af-tip" data-tip="' + esc(text) + '">' +
    '<button type="button" class="af-tip-btn" aria-expanded="false" aria-label="' + esc(label || 'Show explanation') + '">?</button>' +
    '</span>';
}

// ---- Data freshness ---------------------------------------------------------
// The portal falls back to a localStorage cache when the API is unreachable,
// and silently so. An owner looking at a dashboard needs to know whether the
// numbers are live, so every portal stamp carries the snapshot's own timestamp.
function renderStamp() {
  const el = document.getElementById('dataStamp');
  if (!el) return;

  const raw = DB.meta && DB.meta.generated_at;
  if (!raw) {
    el.textContent = 'Offline copy';
    el.classList.add('af-stamp--stale');
    return;
  }

  const parsed = Date.parse(raw);
  if (!Number.isFinite(parsed)) {
    el.textContent = '';
    return;
  }

  const mins = Math.floor((Date.now() - parsed) / 60000);
  const fresh = mins < 5;

  el.classList.toggle('af-stamp--stale', !fresh);
  el.textContent = mins <= 0
    ? 'Updated just now'
    : mins === 1
      ? 'Updated 1 min ago'
      : fresh
        ? 'Updated ' + mins + ' min ago'
        : 'Last synced ' + new Date(parsed).toLocaleString();
}
