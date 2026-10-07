// Aggregation & Rate Helpers
function avg(a) { return a.reduce((x, y) => x + y, 0) / Math.max(1, a.length); }
function sum(a) { return a.reduce((x, y) => x + y, 0); }

// Days-of-cover now arrives from the backend (InventoryEngine::daysRemaining).
//
// This used to be recomputed here as `fAvg * 0.8` for caps/seals and a set of
// hard-coded rates for everything else, which disagreed with the advisory API on
// the same screen: "Non-Spill Caps" showed 2.2 days in this panel and 1.7 in the
// advisory. The engine is now the only source; these helpers only format it.
// A missing/null figure means "no forecast for this item yet", not infinity.
function daysCover(inv) {
  const raw = inv.days_left;
  if (raw === null || raw === undefined || isNaN(raw)) return 999;
  return Number(raw);
}

function dailyUse(inv) {
  const daily = Number(inv.daily_demand);
  return isNaN(daily) || daily <= 0 ? 1 : daily;
}

function orderByDate(inv) {
  const d = new Date();
  d.setDate(d.getDate() + Math.floor(daysCover(inv) - inv.lead));
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

// The dashboard used to compute its own order quantity as (rop - on + ss), which
// double-counted safety stock and disagreed with InventoryEngine. Ordering is the
// backend's job; these panels only show urgency and point at the advisories.
function orderQtyFrom(v) {
  return Number(v.target_stock || 0) > 0
    ? Math.max(0, v.target_stock - v.on)
    : Math.max(0, v.rop - v.on);
}


// Metrics Computation Engine
function computeInsights() {
  const h = (DB.history || DB.history30 || []).slice(-30), f = DB.forecast7 || [];
  const avg30 = avg(h), last7 = avg(h.slice(-7)), prev7 = avg(h.slice(-14, -7));
  const trend = (last7 - prev7) / Math.max(1, prev7) * 100;
  const peak = h.length ? Math.max(...h) : 0, peakDay = h.indexOf(peak) + 1;
  const low = h.length ? Math.min(...h) : 0;
  const fTotal = sum(f), fAvg = avg(f);
  const weekend = avg(h.filter((_, i) => (i + 1) % 7 === 0 || (i + 1) % 7 === 6));
  const weekday = avg(h.filter((_, i) => (i + 1) % 7 !== 0 && (i + 1) % 7 !== 6));
  const uplift = (weekend - weekday) / Math.max(1, weekday) * 100;

  const runway = DB.inventory.map(v => ({
    item: v.item, on: v.on, unit: v.unit, days: daysCover(v),
    status: statusOf(v)[0], rop: v.rop, lead: v.lead, supplier: v.supplier,
    // Items with no reorder point carry no actionable urgency, so they are
    // reported separately instead of inflating the attention count.
    unconfigured: isUnconfigured(v),
    orderQty: orderQtyFrom(v), orderBy: orderByDate(v)
  })).sort((a, b) => (a.unconfigured === b.unconfigured ? a.days - b.days : a.unconfigured ? 1 : -1));

  const custs = DB.customers.filter(c => c.name !== 'Walk-in Guest').map(c => {
    const p = pending(c);
    const bottles = 0;
    return {
      id: c.id, name: c.name, contact: c.contact, debt: c.debt, tx: c.tx, pS: 0, pR: 0, bottles, leakage: bottles * 200, exposure: c.debt + bottles * 200,
      returnRate: (c.issuedS + c.issuedR) ? Math.round((c.returnedS + c.returnedR) / (c.issuedS + c.issuedR) * 100) : 100, last: c.last
    };
  }).sort((a, b) => b.exposure - a.exposure);

  const totalDebt = sum(custs.map(c => c.debt));
  const totalBottles = sum(custs.map(c => c.bottles));
  const totalExposure = totalDebt + totalBottles * 200;
  const topShare = custs.length ? Math.round(custs[0].exposure / Math.max(1, totalExposure) * 100) : 0;
  const worstReturn = [...custs].sort((a, b) => a.returnRate - b.returnRate)[0];

  const cash = DB.transactions.filter(t => t.pay === 'Cash').length;
  const acct = DB.transactions.filter(t => t.pay === 'Account').length;

  // Show the forecast as a band, not a single number. The engine already reports a
  // backtest MAPE; hiding it would overstate how certain a 7-day projection is.
  const mape = (() => {
    const fc = DB.forecasts;
    // forecasts arrive keyed by series name, with accuracy under .model.mape.
    const list = Array.isArray(fc) ? fc : (fc && typeof fc === 'object' ? Object.values(fc) : []);
    const vals = list
      .map(f => Number(f && f.model && f.model.mape != null ? f.model.mape : NaN))
      .filter(v => !isNaN(v) && v > 0);
    return vals.length ? avg(vals) : 0;
  })();
  const band = n => Math.round(n * (1 - mape / 100)) + '-' + Math.round(n * (1 + mape / 100));

  return { avg30, last7, prev7, trend, peak, peakDay, low, fTotal, fAvg, weekend, weekday, uplift, runway, custs, totalDebt, totalBottles, totalExposure, topShare, worstReturn, cash, acct, mape, band };
}


/**
 * Sidebar urgency badges.
 *
 * Nine equally weighted nav items gave a busy owner no way to tell which pages
 * had pending work. Stock & Supplies now carries the actionable reorder count
 * and Customer Balances carries the number of customers carrying a balance, so
 * the overview can be skipped entirely on a busy morning.
 */
function renderSidebarBadges() {
  const set = (id, value) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = value > 0 ? String(value) : '';
    el.hidden = !(value > 0);
  };

  set('badgeStock', DB.inventory.filter(needsReorder).length);
  set('badgeBalances', DB.customers.filter(c => Number(c.debt) > 0 && c.name !== 'Walk-in Guest').length);
}

// ---- Insight callout markup ------------------------------------------------
// Progressive disclosure: the single action line is always visible; the
// interpretation and the risk live behind a disclosure. This replaced one grey
// paragraph per panel, which read as a wall of text.
function callout(actionHtml, detailLines) {
  const body = detailLines
    .filter(Boolean)
    .map(l => '<p class="insight-line' + (l.risk ? ' insight-line--risk' : '') + '">' +
      '<span class="insight-k' + (l.risk ? ' insight-k--risk' : '') + '">' + (l.risk ? 'Risk' : 'Means') + '</span>' +
      '<span>' + l.text + '</span></p>')
    .join('');

  return '<details class="insight"><summary class="insight-summary">' +
    '<span class="insight-k insight-k--do">To do</span>' +
    '<span class="insight-v">' + actionHtml + '</span></summary>' +
    (body ? '<div class="insight-detail">' + body + '</div>' : '') +
    '</details>';
}


// Dashboard Views & Cards Rendering
function renderInsights() {
  const I = computeInsights();
  const setText = (id, txt) => { const el = document.getElementById(id); if (el) el.textContent = txt; };

  // The KPI grid ships as skeletons with aria-busy. Setting textContent on each
  // value/subtext above discards the skeleton spans; this releases the busy
  // state once the real figures are in place.
  const grid = document.getElementById('kpiGrid');
  if (grid) grid.removeAttribute('aria-busy');

  const today = (DB.meta?.generated_at || new Date().toISOString()).slice(0, 10);
  const sales = (DB.transactions || []).filter(t => t.date === today && t.type !== 'Debt Payment');
  setText('kRev', money(DB.meta?.sales_today_total ?? sum(sales.map(t => Number(t.total)))));
  setText('kRevSub', (DB.meta?.sales_today_count ?? sales.length) + ' sales today');
  setText('kGal', Math.round(I.fTotal) + ' gal');
  setText('kGalSub', '30d avg ' + Math.round(I.avg30) + ' | 7d forecast ' + I.band(I.fAvg) + ' gal/day');
  // Counts only genuinely actionable items. Unconfigured items are surfaced as
// a separate "set a threshold" prompt so they never read as emergencies.
const crit = I.runway.filter(r => !r.unconfigured && r.status !== 'OK').length;
  const unset = I.runway.filter(r => r.unconfigured).length;
  setText('invHealth', String(crit));
  setText('kStockSub', crit
    ? 'items at or below their reorder point'
    : unset
      ? unset + (unset === 1 ? ' item needs' : ' items need') + ' a reorder point set'
      : 'Stock is above reorder thresholds');
  setText('kLia', money(I.totalDebt));
  setText('kLiaSub', I.custs.filter(c => c.debt > 0).length + ' customers with outstanding balances');

  // Name the single biggest debtor. "₱40,495 owed" is a number; naming the
  // customer turns it into somewhere to go.
  const topDebtor = I.custs.length ? I.custs[0] : null;
  setText('kLiaTop', topDebtor
    ? 'Largest balance: ' + topDebtor.name + ' — ' + money(topDebtor.debt)
    : '');

  const insDemand = document.getElementById('insDemand');
  if (insDemand) {
    const hasHistory = (DB.history || DB.history30 || []).length > 0;
    insDemand.innerHTML = hasHistory ?
      '<div class="insight-row"><span>Sales trend · last 7 vs previous 7 days</span><b class="' + (I.trend >= 0 ? 'text-green-700' : 'text-red-600') + '">' + (I.trend >= 0 ? '+' : '−') + Math.abs(I.trend).toFixed(1) + '%</b></div>' +
      '<div class="insight-row"><span>Daily average · last 30 days</span><b>' + Math.round(I.avg30).toLocaleString() + ' gal</b></div>' +
      '<div class="insight-row"><span>Busiest / quietest day</span><b>' + I.peak.toLocaleString() + ' / ' + I.low.toLocaleString() + ' gal</b></div>' +
      '<p class="insight-note">Forecasts are estimates' + tipHtml(
        'A forecast is a projection, not a promise. The model is back-tested against your own past sales, and the range shown is its typical error margin. Treat it as a planning aid, not a guarantee.',
        'What does it mean that forecasts are estimates?'
      ) + '. Review the demand forecast before planning filling and delivery work.</p>' :
      '<p class="overview-empty">No sales history yet. Sales patterns will appear as transactions are recorded.</p>';
  }

  const insRunway = document.getElementById('insRunway');
  if (insRunway && !I.runway.length) insRunway.innerHTML = '<p class="overview-empty">No inventory items recorded yet.</p>';
  if (insRunway && I.runway.length) {
    insRunway.innerHTML = I.runway.filter(r => !r.unconfigured).slice(0, 4).map(r =>
      '<div class="insight-row"><span>' + esc(r.item) + ' <span class="insight-sub">' + r.on.toLocaleString() + ' ' + esc(r.unit) + ' remaining</span>' + tipHtml(
        'Days of stock cover. The system compares what you hold against how fast this item has been selling recently, then shows how long that should last. Red means you have less time than your supplier needs to deliver.',
        'What do the days here mean?'
      ) + '</span>' +
      '<b class="' + (r.days <= r.lead ? 'text-red-600' : r.status !== 'OK' ? 'text-yellow-700' : 'text-green-700') + '">' + (r.days === 999 ? 'No estimate' : r.days.toFixed(1) + ' days') + '</b></div>'
    ).join('') || '<p class="overview-empty">Set a reorder point on each item to see stock runway.</p>';
  }

  const insCollect = document.getElementById('insCollect');
  if (insCollect) {
    const customers = I.custs.filter(c => c.debt > 0).slice(0, 3);
    insCollect.innerHTML = customers.map((c, i) => '<div class="insight-row"><span>' + (i + 1) + '. ' + esc(c.name) + '</span><b>' + money(c.debt) + '</b></div>').join('') || '<p class="insight-note">No outstanding customer balances.</p>';
  }

  const insActions = document.getElementById('insActions');
  if (insActions) {
    const urgent = I.runway.find(item => !item.unconfigured && item.status !== 'OK');
    const unset = I.runway.find(item => item.unconfigured);
    const debtor = I.custs.find(customer => customer.debt > 0);
    const action = (tone, label, title, detail, href, button) =>
      '<article class="overview-action overview-action--' + tone + '"><span class="overview-action-label">' + label + '</span><h4>' + esc(title) + '</h4><p>' + esc(detail) + '</p><a class="btn btn-secondary btn-sm" href="' + href + '">' + button + ' &rarr;</a></article>';
    insActions.innerHTML = action(urgent ? 'danger' : unset ? 'watch' : 'ok', 'STOCK',
      urgent ? 'Review low stock' : unset ? 'Set reorder points' : 'No stock alerts',
      urgent
        ? urgent.item + ' has ' + urgent.on.toLocaleString() + ' ' + urgent.unit + ' remaining.'
        : unset
          ? unset.item + ' has no reorder point, so its stock cannot be tracked.'
          : 'Review stock quantities and reorder thresholds in Stock & Supplies.',
      '/admin/inventory', urgent ? 'View stock' : 'Set thresholds') +
      action(debtor ? 'watch' : 'ok', 'COLLECTIONS', debtor ? debtor.name : 'No outstanding balances',
      debtor ? money(debtor.debt) + ' outstanding. Review the customer ledger before collection.' : 'Customer balances are clear.', '/admin/customers', 'View balances') +
      action('info', 'PLANNING', I.fTotal > 0 ? Math.round(I.fTotal).toLocaleString() + ' gal forecast' : 'Forecast not available',
      I.fTotal > 0 ? 'Projected refill demand over the next seven days. Check the forecast before planning.' : 'Record sales and update the forecast to start planning demand.', '/admin/arima', 'View forecast');
  }

  renderAdvisories();
  renderLedger();
  renderStamp();
  renderSidebarBadges();
  markScrollableTables();
}


// Restock Advisories
function renderAdvisories() {
  const el = document.getElementById('advisory');
  if (!el) return;

  if (Array.isArray(DB.advisories) && DB.advisories.length) {
    // Advisories come from the engine, which still lists items whose reorder
    // point is 0. Those have no meaningful order quantity, so they are shown
    // as a configuration prompt rather than as a critical restock.
    const real = DB.advisories.filter(a => Number(a.reorder_at) > 0);
    const unconfigured = DB.advisories.filter(a => Number(a.reorder_at) <= 0);

    const cards = real.slice(0, 3).map(advisory => {
      const critical = advisory.severity === 'critical';
      return '<article class="advisory-card ' + (critical ? 'advisory-critical' : 'advisory-watch') + '">' +
        '<div class="overview-advisory-head"><b>' + esc(advisory.item) + '</b><span>' + (critical ? 'Critical' : 'Low stock') + '</span></div>' +
        '<p>' + Number(advisory.on_hand).toLocaleString() + ' ' + esc(advisory.unit) + ' on hand · Reorder at ' + Number(advisory.reorder_at).toLocaleString() + '</p>' +
        '<p><strong>Suggested order: ' + Number(advisory.order_quantity).toLocaleString() + ' ' + esc(advisory.unit) + '</strong>' + tipHtml(
        'This is how much the system thinks you should buy: enough to reach the target stock level after accounting for what you already hold. It is a suggestion based on your recent selling rate, not a fixed rule.',
        'How is the suggested order calculated?'
      ) + '</p>' +
        '<p class="overview-advisory-supplier">' + (advisory.supplier && advisory.supplier !== '-' ? esc(advisory.supplier) : 'Assign a supplier in Stock & Supplies') + '</p></article>';
    }).join('');

    const prompt = unconfigured.length
      ? '<article class="advisory-card advisory-info"><div class="overview-advisory-head"><b>' +
        unconfigured.length + (unconfigured.length === 1 ? ' item needs' : ' items need') + ' a reorder point</b><span>Not set</span></div>' +
        '<p>' + esc(unconfigured.map(a => a.item).join(', ')) + '</p>' +
        '<p class="overview-advisory-supplier">Until a threshold is set, these items cannot be tracked for stockouts.</p></article>'
      : '';

    el.innerHTML = cards + prompt;
    return;
  }

  el.innerHTML = '<div class="overview-empty"><b>No restock advisories</b><p>Stock alerts will appear here when an item reaches its reorder point.</p></div>';
}


// Normalises a stored contact into a dialable E.164-ish string. Seeded data
// looks like "0917-111-2233"; Philippine mobiles become +639171111223 so both
// sms: and wa.me links behave. Anything unrecognised falls back to the raw
// digits so we never emit a dead link.
function dialNumber(raw) {
  const digits = String(raw || '').replace(/\D/g, '');
  if (!digits) return '';
  if (digits.length === 11 && digits[0] === '0') return '+63' + digits.slice(1);
  if (digits.length === 10 && digits[0] === '9') return '+63' + digits;
  return digits;
}

function remindLink(c) {
  const num = dialNumber(c.contact);
  if (!num) return '';
  const body = encodeURIComponent(
    'Hi ' + c.name + ', this is AquaFlow Station. A balance of ' + money(c.debt) +
    ' is still outstanding' +
    (c.bottles ? ', along with ' + c.bottles + ' container(s)' : '') +
    '. Thank you!'
  );
  return num.startsWith('+63')
    ? 'https://wa.me/' + num.replace('+', '') + '?text=' + body
    : 'sms:' + num + '?body=' + body;
}

// The exact wording requested for the reminder drawer. Kept in one place so the
// drawer preview, the copy button and the outbound link never drift apart.
function reminderTemplate(c, station) {
  return 'Hi ' + c.name + ', this is ' + (station || 'AquaFlow Station').trim() + '. Your outstanding balance is ' + money(c.debt) + '. Please let us know when we can arrange payment.';
}

let remindCustomer = null;

function openRemindDrawer(id) {
  const c = computeInsights().custs.find(x => String(x.id) === String(id));
  const drawer = document.getElementById('remindDrawer');
  if (!c || !drawer) return;

  remindCustomer = c;
  const station = (DB.settings && (DB.settings.station_name || DB.settings.stationName)) || 'AquaFlow Station';
  const text = reminderTemplate(c, station);

  drawer.querySelector('[data-field="name"]').textContent = c.name;
  drawer.querySelector('[data-field="contact"]').textContent = c.contact || 'No contact on file';
  drawer.querySelector('[data-field="summary"]').textContent =
    money(c.debt) + ' outstanding';
  drawer.querySelector('[data-field="template"]').textContent = text;

  const send = drawer.querySelector('[data-action="send"]');
  const link = remindLink(c);
  if (link) {
    send.href = link;
    send.classList.remove('is-disabled');
    send.removeAttribute('aria-disabled');
  } else {
    send.removeAttribute('href');
    send.classList.add('is-disabled');
    send.setAttribute('aria-disabled', 'true');
  }

  drawer.classList.add('is-open');
  drawer.setAttribute('aria-hidden', 'false');
  const first = drawer.querySelector('[data-action="copy"]');
  if (first) first.focus();
}

function closeRemindDrawer() {
  const drawer = document.getElementById('remindDrawer');
  if (!drawer) return;
  drawer.classList.remove('is-open');
  drawer.setAttribute('aria-hidden', 'true');
  remindCustomer = null;
}

async function copyReminder() {
  const drawer = document.getElementById('remindDrawer');
  const text = drawer && drawer.querySelector('[data-field="template"]').textContent;
  if (!text) return;
  try {
    await navigator.clipboard.writeText(text);
    flashDrawer('Copied to clipboard');
  } catch (e) {
    // Clipboard API needs a secure context; fall back to a manual selection.
    const range = document.createRange();
    range.selectNodeContents(drawer.querySelector('[data-field="template"]'));
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    flashDrawer('Selected - press Ctrl+C');
  }
}

function flashDrawer(message) {
  const btn = document.getElementById('remindDrawer').querySelector('[data-action="copy"]');
  if (!btn) return;
  const original = btn.textContent;
  btn.textContent = message;
  setTimeout(() => { btn.textContent = original; }, 1800);
}


// Custody Ledger
function renderLedger() {
  const slim = DB.inventory.find(v => v.item.includes('Slim Jugs'));
  const round = DB.inventory.find(v => v.item.includes('Round Jugs'));
  const I = computeInsights();
  const elS = document.getElementById('ledgerSlim'), elR = document.getElementById('ledgerRound'), elP = document.getElementById('ledgerPend');
  if (elS && slim) elS.textContent = slim.on + ' in station';
  if (elR && round) elR.textContent = round.on + ' in station';
  if (elP) elP.textContent = money(I.totalDebt);
  const tb = document.getElementById('topLiaBody');
  if (tb) {
    tb.innerHTML = filterTableRows(I.custs.filter(c => c.debt > 0), 'topLiaBody').map(c => {
      const hasContact = !!dialNumber(c.contact);

      return '<tr>' +
        '<td><span class="ledger-name">' + esc(c.name) + '</span>' +
          '<span class="cell-sub">' + (c.contact ? dialNumber(c.contact) : 'No contact on file') + '</span></td>' +
        '<td class="num ledger-balance"><span class="debt">' + money(c.debt) + '</span>' +
          '</td>' +
        '<td><div class="ledger-actions">' +
          '<button type="button" class="action-message" data-cust="' + c.id + '"' +
            (hasContact ? '' : ' aria-disabled="true" title="No contact number on file"') +
            '>Remind</button>' +
          '<button class="btn btn-tertiary btn-sm" onclick="showSection(\'cust\')">Balances</button>' +
        '</div></td>' +
      '</tr>';
    }).join('');
    showTableEmpty(tb, 3);

    // Delegated so re-rendering the ledger never leaves stale handlers.
    tb.querySelectorAll('[data-cust]').forEach(btn => {
      const id = btn.getAttribute('data-cust');
      btn.addEventListener('click', () => {
        if (btn.classList.contains('action-message')) openRemindDrawer(id);
        else if (btn.classList.contains('action-return')) logContainerReturn(id);
      });
    });
  }
}

/**
 * Records a returned container straight from the ledger. Uses the existing
 * customer-return endpoint so the custody counters stay server-authoritative
 * and the audit trail is identical to logging it from the customer page.
 */
async function logContainerReturn(id) {
  const customer = DB.customers.find(c => String(c.id) === String(id));
  if (!customer) return;

  const slim = Math.max(0, customer.issuedS - customer.returnedS);
  const round = Math.max(0, customer.issuedR - customer.returnedR);

  if (slim <= 0 && round <= 0) {
    toast('Nothing outstanding for ' + customer.name);
    return;
  }

  // The endpoint records one container of a given shape per call.
  const kind = slim > 0 ? 'slim' : 'round';

  try {
    await API.logReturn(customer.id, kind);
    await loadFromServer();
    renderInsights();
    toast('Logged 1 returned ' + kind + ' jug for ' + customer.name);
  } catch (error) {
    toast('Could not log the return: ' + (error.message || 'unknown error'));
  }
}

function toast(message) {
  let el = document.getElementById('afToast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'afToast';
    el.className = 'af-toast';
    document.body.appendChild(el);
  }
  el.textContent = message;
  el.classList.add('is-visible');
  clearTimeout(el._timer);
  el._timer = setTimeout(() => el.classList.remove('is-visible'), 3200);
}


// Purchase Order Export
//
// Only items with a configured reorder point are exported. Advisories for
// items whose threshold is 0 carry a meaningless suggested quantity, and this
// file is handed to a supplier, so those rows are withheld and called out in a
// trailing note instead of being silently ordered.
function draftPO() {
  const advisories = (Array.isArray(DB.advisories) ? DB.advisories : [])
    .filter(a => Number(a.reorder_at) > 0);

  const rows = advisories.length
    ? advisories.map(a => [a.item, a.order_quantity, a.supplier].join(','))
    : DB.inventory.filter(needsReorder).map(v => v.item + ',' + orderQtyFrom(v) + ',' + v.supplier);

  const skipped = DB.inventory.filter(isUnconfigured).map(v => v.item);

  const notes = [];
  if (skipped.length) {
    notes.push('');
    notes.push('# Excluded - no reorder point set: ' + skipped.join('; '));
  }

  const csv = 'Item,SuggestedQty,Supplier\n' + rows.join('\n') + notes.join('\n');
  downloadCSV(csv, 'draft-PO.csv');
}
