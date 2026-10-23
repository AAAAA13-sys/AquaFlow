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

/* ---------------------------------------------------------------------------
   Bar + sparkline helpers.

   These exist so a column of figures can be *seen* instead of read and
   compared by eye. "3.4 / 3.4 / 3.4 / 3.4 days" in a list forces the reader
   to find the shortest; as bars on a shared scale the shortest one is simply
   the shortest one.

   Bars are plain styled spans rather than SVG: they inherit the theme tokens,
   need no extra assets, and cost nothing to render in a long table.
   ------------------------------------------------------------------------ */

/**
 * A horizontal magnitude bar, scaled against the largest value in the set.
 * @param {number} value  the figure being shown
 * @param {number} max    the largest figure in its group, for scaling
 * @param {string} tone   modifier class: 'low' | 'watch' | 'ok'
 */
function magBar(value, max, tone) {
  const pct = max > 0 ? Math.max(2, Math.min(100, (value / max) * 100)) : 0;
  return '<span class="magbar magbar--' + tone + '"><span class="magbar-fill" style="width:' +
    pct.toFixed(1) + '%"></span></span>';
}

/**
 * Direction of a series: compare the recent half against the earlier half, so
 * a single odd day at the end cannot flip the colour.
 */
function trendTone(series) {
  const v = (series || []).filter(n => typeof n === 'number' && isFinite(n));
  if (v.length < 4) return 'flat';
  const half = Math.floor(v.length / 2);
  const earlier = v.slice(0, half).reduce((a, b) => a + b, 0) / half;
  const recent = v.slice(half).reduce((a, b) => a + b, 0) / (v.length - half);
  if (!earlier) return recent > 0 ? 'up' : 'flat';
  const change = (recent - earlier) / earlier;
  if (change > 0.05) return 'up';
  if (change < -0.05) return 'down';
  return 'flat';
}

/**
 * A tiny inline trend line for a KPI value.
 *
 * Answers "is this up or down?" without adding another figure to read. Built
 * from the last N daily values; returns nothing when there is no history, so
 * a KPI without history simply shows no trend rather than a misleading flat.
 *
 * @param {number[]} series oldest-to-newest
 */
function sparkline(series, tone) {
  const values = (series || []).filter(v => typeof v === 'number' && isFinite(v));
  if (values.length < 3) return '';

  const w = 72, h = 22, pad = 2;
  const max = Math.max(...values), min = Math.min(...values);
  const span = max - min || 1;
  const step = (w - pad * 2) / (values.length - 1);

  const points = values.map((v, i) => {
    const x = (pad + i * step).toFixed(1);
    const y = (h - pad - ((v - min) / span) * (h - pad * 2)).toFixed(1);
    return x + ',' + y;
  });

  const first = values[0], last = values[values.length - 1];
  const rising = last >= first;
  const colour = tone || (rising ? 'up' : 'down');

  return '<svg class="spark spark--' + colour + '" width="' + w + '" height="' + h +
    '" viewBox="0 0 ' + w + ' ' + h + '" aria-hidden="true" focusable="false">' +
    '<polyline points="' + points.join(' ') + '" fill="none" stroke="currentColor" ' +
    'stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>' +
    '<circle cx="' + (w - pad) + '" cy="' + points[points.length - 1].split(',')[1] +
    '" r="2" fill="currentColor"/>' +
    '</svg>';
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
  // The approval badge reflects what THIS viewer may decide. The server scopes
  // the count, so a manager's badge never counts an owner-tier request.
  set('badgeApprovals', SidebarApprovals.pendingCount);
}

// The pending-approval count for the sidebar badge.
//
// Kept out of `DB` because it is viewer-scoped server state, not a station
// snapshot. It is refreshed on demand and never trusted for authorization - the
// approvals screen re-reads the list from the API.
const SidebarApprovals = {
  pendingCount: 0,
  async refresh() {
    try {
      const result = await API.approvals({ state: 'pending' });
      this.pendingCount = Number(result.pending_count || 0);
    } catch (error) {
      // A badge is not worth breaking a page over; leave the last known value.
    }
    renderSidebarBadges();
  }
};

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
  const sales = (DB.transactions || []).filter(t => t.date === today && !t.voided && t.type !== 'Debt Payment');

  // Direction for the revenue KPI: today's figure against the recorded run.
  // Appended after the KPI text is set, because setting textContent on the
  // value element would otherwise discard anything placed inside it.
  const revenueTrend = (DB.meta?.daily_revenue || []).length >= 3
    ? sparkline(DB.meta.daily_revenue.slice(-14), trendTone(DB.meta.daily_revenue))
    : '';
  if (revenueTrend) {
    const slot = document.getElementById('kRevTrend');
    if (slot) slot.innerHTML = revenueTrend;
  }
  setText('kRev', money(DB.meta?.sales_today_total ?? sum(sales.map(t => Number(t.total)))));
  setText('kRevSub', (DB.meta?.sales_today_count ?? sales.length) + ' sales today');
  // An absent forecast is not zero demand. Saying "0 jugs" next to a live
  // revenue figure reads as "you sold nothing", so name the missing forecast
  // instead of reporting a number that was never calculated.
  const hasForecast = (DB.forecast7 || []).length > 0;
  setText('kGal', hasForecast ? Math.round(I.fTotal).toLocaleString() + ' jugs' : 'Not forecast yet');
  setText('kGalSub', hasForecast
    ? '7-day forecast ' + I.band(I.fAvg) + ' jugs/day'
    : 'Run the demand forecast to see next week');
  // Counts only genuinely actionable items. Unconfigured items are surfaced as
// a separate "set a threshold" prompt so they never read as emergencies.
const crit = I.runway.filter(r => !r.unconfigured && r.status !== 'OK').length;
  const unset = I.runway.filter(r => r.unconfigured).length;
  setText('invHealth', String(crit));

  // The attention card was hardcoded red in the blade, so a station with
  // nothing wrong was shown a red "0". Let the number pick the tone.
  const attentionCard = document.getElementById('invHealth')?.closest('.kpi-card-body');
  if (attentionCard) {
    attentionCard.classList.remove('kpi-red', 'kpi-green', 'kpi-yellow');
    attentionCard.classList.add(crit > 0 ? 'kpi-red' : unset > 0 ? 'kpi-yellow' : 'kpi-green');
  }

  setText('kStockSub', crit
    ? crit + (crit === 1 ? ' item needs' : ' items need') + ' buying'
    : unset
      ? unset + (unset === 1 ? ' item needs' : ' items need') + ' a reorder point set'
      : 'Nothing to buy right now');
  setText('kLia', money(I.totalDebt));
  setText('kLiaSub', I.custs.filter(c => c.debt > 0).length + ' customers with outstanding balances');

  // Name the single biggest debtor only if there is a real outstanding balance.
  const topDebtor = I.custs.find(c => Number(c.debt) > 0);
  setText('kLiaTop', topDebtor
    ? 'Largest balance: ' + topDebtor.name + ' — ' + money(topDebtor.debt)
    : '');

  const insDemand = document.getElementById('insDemand');
  if (insDemand) {
    const hasHistory = (DB.history || DB.history30 || []).length > 0;
    insDemand.innerHTML = hasHistory ?
      '<div class="insight-row"><span>Sales trend · last 7 vs previous 7 days</span><b class="' + (I.trend >= 0 ? 'text-green-700' : 'text-red-600') + '">' + (I.trend >= 0 ? '+' : '−') + Math.abs(I.trend).toFixed(1) + '%</b></div>' +
      '<div class="insight-row"><span>Daily average · last 30 days</span><b>' + Math.round(I.avg30).toLocaleString() + ' jugs</b></div>' +
      '<div class="insight-row"><span>Busiest / quietest day</span><b>' + I.peak.toLocaleString() + ' / ' + I.low.toLocaleString() + ' jugs</b></div>' +
      '<p class="insight-note">Forecasts are estimates. Review the demand forecast before planning filling and delivery work.</p>' :
      '<p class="overview-empty">No sales history yet. Sales patterns will appear as transactions are recorded.</p>';
  }

  const insRunway = document.getElementById('insRunway');
  if (insRunway && !I.runway.length) insRunway.innerHTML = '<p class="overview-empty">No inventory items recorded yet.</p>';
  if (insRunway && I.runway.length) {
    insRunway.innerHTML = I.runway.filter(r => !r.unconfigured).slice(0, 4).map(r =>
      '<div class="insight-row"><span>' + esc(r.item) + '<span class="insight-sub">' + r.on.toLocaleString() + ' ' + esc(r.unit) + ' remaining</span></span>' +
      '<b class="' + (r.days <= r.lead ? 'text-red-600' : r.status !== 'OK' ? 'text-yellow-700' : 'text-green-700') + '">' + (r.days === 999 ? 'No estimate' : r.days.toFixed(1) + ' days') + '</b></div>'
    ).join('') || '<p class="overview-empty">Set a reorder point on each item to see stock runway.</p>';
  }

  // "Today's priorities" used to be rendered here as three cards restating the
  // KPI row. The KPI cards carry the action links now, so this renders nothing;
  // only the stock action link still needs to switch its label.
  const stockAction = document.getElementById('kStockAction');
  if (stockAction) {
    const unset = I.runway.find(item => item.unconfigured);
    stockAction.textContent = unset ? 'Set reorder points →' : 'View stock →';
    stockAction.href = unset ? '/admin/inventory?view=supplies' : '/admin/inventory';
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

    const cards = real.map(advisory => {
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
    showAdvisoryRail(true);
    return;
  }

  // Nothing to buy. The "Needs Your Attention" KPI already reads zero and says
  // so, so the rail is removed and the forecast chart takes the full width
  // rather than sitting beside a panel explaining that there is nothing to do.
  showAdvisoryRail(false);
}

/** Show or hide the restock rail, and let the chart claim the freed space. */
function showAdvisoryRail(show) {
  const rail = document.getElementById('advisoryRail');
  const row = document.getElementById('dashChartRow');
  if (rail) rail.classList.toggle('hidden', !show);
  if (row) row.classList.toggle('is-wide', !show);
  if (!show) {
    const el = document.getElementById('advisory');
    if (el) el.innerHTML = '';
  }
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
  // The control is a <button>, so the destination is held in a data attribute
  // and opened here. (As an anchor with no href it used to be skipped by the
  // tab order entirely.)
  if (link) {
    send.dataset.href = link;
    send.disabled = false;
    send.classList.remove('is-disabled');
    send.removeAttribute('aria-disabled');
  } else {
    send.removeAttribute('data-href');
    send.disabled = true;
    send.classList.add('is-disabled');
    send.setAttribute('aria-disabled', 'true');
  }

  drawer.classList.add('is-open');
  drawer.setAttribute('aria-hidden', 'false');
  const first = drawer.querySelector('[data-action="copy"]');
  if (first) first.focus();
}

/**
 * Open the reminder in the customer's messaging app.
 *
 * Open with an explicit click so the popup is not blocked, and never navigate
 * the admin portal itself away to wa.me.
 */
function sendWhatsApp() {
  const send = document.querySelector('[data-action="send"]');
  const link = send?.dataset.href;
  if (!link) return;
  window.open(link, '_blank', 'noopener');
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
// Match on the singular "Jug": the seeded rows are "5 Gallon Slim Jug" /
// "5 Gallon Round Jug". Looking for a plural "Jugs" never matched, so these
// two figures sat on "Loading" forever.
function renderLedger() {
  const slim = DB.inventory.find(v => v.item.includes('Slim Jug'));
  const round = DB.inventory.find(v => v.item.includes('Round Jug'));
  const I = computeInsights();
  const elS = document.getElementById('ledgerSlim'), elR = document.getElementById('ledgerRound'), elP = document.getElementById('ledgerPend');
  if (elS && slim) elS.textContent = slim.on + ' in station';
  if (elR && round) elR.textContent = round.on + ' in station';
  if (elP) elP.textContent = money(I.totalDebt);
  const tb = document.getElementById('topLiaBody');
  if (tb) {
    const ledgerRows = filterTableRows(I.custs, 'topLiaBody');
    // Bars share one scale across the whole table, so the largest debtor is
    // the longest bar regardless of sort order or search.
    const ledgerMax = ledgerRows.reduce((n, c) => Math.max(n, Number(c.debt) || 0), 0);
    // A table of settled customers is a wall of zero balances. When nothing is
    // owed, say so once and stop; the KPI card above carries the same total.
    if (ledgerMax <= 0) {
      tb.innerHTML = '<tr><td colspan="3" class="table-empty-note">' +
        'No customer owes money. Every delivery account is settled.</td></tr>';
      return;
    }
    tb.innerHTML = ledgerRows.map(c => {
      const isZero = !c.debt || Number(c.debt) <= 0;

      return '<tr>' +
        '<td><span class="ledger-name">' + esc(c.name) + '</span>' +
          '<span class="cell-sub">' + (c.contact ? dialNumber(c.contact) : 'No contact on file') + '</span></td>' +
        '<td class="num ledger-balance"><span class="' + (isZero ? 'balance-zero' : 'debt') + '">' + money(c.debt) +
          (isZero ? '' : magBar(Number(c.debt), ledgerMax, 'debt')) + '</span>' +
          '</td>' +
        '<td class="text-right"><div class="ledger-actions">' +
          (isZero
            ? '<button type="button" class="btn btn-secondary btn-sm" onclick="showSection(\'cust\')">View</button>'
            : '<button type="button" class="action-message" disabled aria-disabled="true" title="SMS reminders are unavailable for now.">Remind</button>' +
                '<button class="btn btn-tertiary btn-sm" onclick="showSection(\'cust\')">Balances</button>') +
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
    ? advisories.map(a => [a.item, a.order_quantity, a.supplier].map(csvCell).join(','))
    : DB.inventory.filter(needsReorder).map(v => [v.item, orderQtyFrom(v), v.supplier].map(csvCell).join(','));

  const skipped = DB.inventory.filter(isUnconfigured).map(v => v.item);

  const notes = [];
  if (skipped.length) {
    notes.push('');
    notes.push(csvCell('# Excluded - no reorder point set: ' + skipped.join('; ')));
  }

  const csv = 'Item,SuggestedQty,Supplier\n' + rows.join('\n') + notes.join('\n');
  downloadCSV(csv, 'draft-PO.csv');
}
