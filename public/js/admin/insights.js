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
  const h = DB.history || DB.history30, f = DB.forecast7;
  const avg30 = avg(h), last7 = avg(h.slice(-7)), prev7 = avg(h.slice(-14, -7));
  const trend = (last7 - prev7) / Math.max(1, prev7) * 100;
  const peak = Math.max(...h), peakDay = h.indexOf(peak) + 1;
  const low = Math.min(...h);
  const fTotal = sum(f), fAvg = avg(f);
  const weekend = avg(h.filter((_, i) => (i + 1) % 7 === 0 || (i + 1) % 7 === 6));
  const weekday = avg(h.filter((_, i) => (i + 1) % 7 !== 0 && (i + 1) % 7 !== 6));
  const uplift = (weekend - weekday) / Math.max(1, weekday) * 100;

  const runway = DB.inventory.map(v => ({
    item: v.item, on: v.on, unit: v.unit, days: daysCover(v),
    status: statusOf(v)[0], rop: v.rop, lead: v.lead, supplier: v.supplier,
    // Order quantity and date come from the engine too (see orderQtyFrom).
    orderQty: orderQtyFrom(v), orderBy: orderByDate(v)
  })).sort((a, b) => a.days - b.days);

  const custs = DB.customers.filter(c => c.name !== 'Walk-in Guest').map(c => {
    const p = pending(c);
    const bottles = p.s + p.r;
    return {
      name: c.name, contact: c.contact, debt: c.debt, tx: c.tx, pS: p.s, pR: p.r, bottles, leakage: bottles * 200, exposure: c.debt + bottles * 200,
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

  setText('kRev', 'P' + Math.round(I.last7 * 35).toLocaleString());
  setText('kRevSub', (I.trend >= 0 ? '+' : '') + I.trend.toFixed(1) + '% vs prior 7d avg');
  setText('kGal', Math.round(I.last7) + ' gal/day');
  setText('kGalSub', '30d avg ' + Math.round(I.avg30) + ' | 7d forecast ' + I.band(I.fAvg) + ' gal/day');
  const crit = I.runway.filter(r => r.status !== 'OK').length;
  setText('invHealth', crit + ' item(s) need reorder');
  setText('kLia', I.totalBottles + ' btls | P' + I.totalDebt.toLocaleString());
  setText('kLiaSub', 'Jug replacement value P' + (I.totalBottles * 200).toLocaleString() + ' at P200/jug');

  // Name the single biggest debtor. "P40,495 owed" is a number; naming the
  // customer turns it into somewhere to go.
  const topDebtor = I.custs.length ? I.custs[0] : null;
  setText('kLiaTop', topDebtor
    ? 'Largest Balance: ' + topDebtor.name + ' — P' + topDebtor.debt.toLocaleString()
    : '');

  const weekendWord = Math.abs(I.uplift) < 10 ? 'about the same on weekends as on weekdays'
    : (I.uplift > 0 ? 'much more on weekends' : 'noticeably more on weekdays than weekends');
  const weekendPhrase = (I.uplift >= 0 ? '+' : '\u2212') + Math.abs(I.uplift).toFixed(0) + '%';
  const peakWord = I.weekend >= I.weekday ? 'before Friday' : 'early in the week';

  const insDemand = document.getElementById('insDemand');
  if (insDemand) {
    insDemand.innerHTML =
      '<div class="insight-row"><span>7-day sales trend</span><b class="' + (I.trend >= 0 ? 'text-green-700' : 'text-red-600') + '">' + (I.trend >= 0 ? 'UP ' : 'DOWN ') + Math.abs(I.trend).toFixed(1) + '%</b></div>' +
      '<div class="insight-row"><span>Weekend sales</span><b>' + weekendPhrase + ' (' + Math.round(I.weekend) + ' vs ' + Math.round(I.weekday) + ' gal/day)</b></div>' +
      '<div class="insight-row"><span>Busiest / quietest (30 days)</span><b>' + I.peak + ' gal (day ' + I.peakDay + ') / ' + I.low + ' gal</b></div>' +
      '<div class="insight-row"><span>Expected sales next 7 days</span><b>' + I.fTotal.toLocaleString() + ' gal <span class="insight-sub">(' + I.band(I.fTotal) + ' at MAPE ' + I.mape.toFixed(1) + '%)</span></b></div>' +
      callout(
        I.uplift > 20
          ? 'Prepare ' + Math.round((I.weekend - I.weekday) * 2) + ' extra gallons ' + peakWord + ' and add one delivery run.'
          : 'Keep the normal filling schedule and spread stock evenly across the week.',
        [
          { text: 'Customers buy ' + weekendWord.toLowerCase() + ', and next week is worth roughly <b>P' + I.band(Math.round(I.fTotal * 35)) + '</b> at P35 per refill. The range is the model\'s own backtest error, so plan to the middle of it.' },
          { risk: true, text: 'About <b>P' + Math.round((Math.abs(I.weekend - I.weekday) * 2) * 35).toLocaleString() + '</b> in lost sales plus walkouts to competitors.' }
        ]
      );
  }

  const insRunway = document.getElementById('insRunway');
  if (insRunway) {
    insRunway.innerHTML = I.runway.slice(0, 4).map(r =>
      '<div class="insight-row"><span>' + r.item + ' <span class="insight-sub">' + r.on.toLocaleString() + ' ' + r.unit + ' left</span></span>' +
      '<b class="' + (r.days <= r.lead ? 'text-red-600' : r.status !== 'OK' ? 'text-yellow-700' : 'text-green-700') + '">' + r.days.toFixed(1) + ' days left</b></div>'
    ).join('') + callout(
      'See <b>Restock Advisories</b> above for the exact quantity and supplier — it is calculated by the inventory engine, not here.',
      [
        { text: I.runway[0].item + ' runs out first, in about ' + I.runway[0].days.toFixed(1) + ' days.' },
        { risk: true, text: 'Refills stop even when demand is high, because every gallon needs a cap and a seal.' }
      ]
    );
  }

  const insCollect = document.getElementById('insCollect');
  if (insCollect) {
    const top3 = I.custs.slice(0, 3);
    insCollect.innerHTML = top3.map((c, i) =>
      '<div class="insight-row"><span>' + (i + 1) + '. ' + c.name + ' <span class="insight-sub">' + c.pS + 'S/' + c.pR + 'R bottles + P' + c.debt.toLocaleString() + '</span></span><b>P' + c.exposure.toLocaleString() + '</b></div>'
    ).join('') + callout(
      'Visit <b>' + I.custs[0].name + '</b> (' + I.custs[0].contact + ') and collect P' + I.custs[0].debt.toLocaleString() + '.',
      [
        { text: I.custs[0].name + ' holds ' + I.topShare + '% of everything owed (P' + I.totalExposure.toLocaleString() + ' total). ' + I.worstReturn.name + ' returns only ' + I.worstReturn.returnRate + '% of bottles.' },
        { risk: true, text: 'Collect bottles before taking cash — each missing jug costs P250 to replace.' }
      ]
    );
  }

  const insActions = document.getElementById('insActions');
  if (insActions) {
    const urgent = I.runway[0];
    insActions.innerHTML =
      '<div class="advisory-card advisory-critical"><b>1. Order today:</b> ' + urgent.item + ' has only ' + urgent.days.toFixed(1) + ' days left and restocking takes ' + urgent.lead + ' days. <b>Restock Advisories</b> has the quantity. <button onclick="draftPO()" class="btn btn-primary btn-sm">Make a Purchase Request</button></div>' +
      '<div class="advisory-card advisory-info"><b>2. Collect:</b> visit ' + I.custs[0].name + ' (' + I.custs[0].contact + ') - P' + I.custs[0].exposure.toLocaleString() + ' owed. <button onclick="showSection(\'cust\')" class="btn btn-ghost btn-sm">Open Balances</button></div>' +
      '<div class="advisory-card advisory-watch"><b>3. Plan the week:</b> sales run ' + weekendPhrase + ' on weekends (' + Math.round(I.weekend) + ' vs ' + Math.round(I.weekday) + ' gal/day). Pre-fill ' + peakWord + ' and check the filters. <button onclick="showSection(\'arima\')" class="btn btn-ghost btn-sm">View Forecast</button></div>';
  }

  renderAdvisories();
  renderLedger();
}


// Restock Advisories
function renderAdvisories() {
  const el = document.getElementById('advisory');
  if (!el) return;

  if (Array.isArray(DB.advisories) && DB.advisories.length) {
    el.innerHTML = DB.advisories.slice(0, 3).map(advisory => {
      const critical = advisory.severity === 'critical';
      return '<div class="advisory-card ' + (critical ? 'advisory-critical' : 'advisory-watch') + '">' +
        '<b>' + (critical ? 'CRITICAL' : 'WATCH') + ':</b> ' + advisory.item + ' has ' +
        Number(advisory.on_hand).toLocaleString() + ' ' + advisory.unit + ' left (' + advisory.days_left +
        ' days), reorder at ' + Number(advisory.reorder_at).toLocaleString() +
        '. Order ' + Number(advisory.order_quantity).toLocaleString() + ' ' + advisory.unit +
        ' from ' + advisory.supplier + '.' +
        '<button onclick="draftPO()" class="btn btn-primary btn-sm">Make a Purchase Request</button></div>';
    }).join('');
    return;
  }

  const I = computeInsights();
  const worst = I.runway[0], next = I.runway[1];
  el.innerHTML =
    '<div class="advisory-card advisory-critical">CRITICAL: ' + worst.item + ' is almost out (' + worst.on.toLocaleString() + ' left, reorder at ' + worst.rop + '). About ' + worst.days.toFixed(1) + ' days left. Order ' + worst.orderQty.toLocaleString() + ' units from ' + worst.supplier + '. <button onclick="draftPO()" class="btn btn-primary btn-sm">Make a Purchase Request</button></div>' +
    '<div class="advisory-card advisory-watch">WATCH: ' + next.item + ' has ' + next.days.toFixed(1) + ' days left. Filters can clog - check the water flow now.</div>' +
    '<div class="advisory-card advisory-info">' + I.custs[0].name + ': ' + I.custs[0].pS + 'S/' + I.custs[0].pR + 'R bottles owed + P' + I.custs[0].debt.toLocaleString() + ' cash owed.</div>';
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
    'Hi ' + c.name + ', this is AquaFlow Station. A balance of P' +
    Number(c.debt).toLocaleString() + ' is still outstanding' +
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
  const name = (station || 'AquaFlow Station').trim();
  return 'Hi ' + c.name + ', this is ' + name + '. You have ' + c.bottles +
    ' pending bottles and an outstanding balance of P' + Number(c.debt).toLocaleString() +
    '. Please let us know when we can schedule a pickup/refill!';
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
    c.bottles + ' container(s) · P' + Number(c.debt).toLocaleString() + ' outstanding';
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
  if (elP) elP.textContent = I.totalBottles + ' with customers';
  const tb = document.getElementById('topLiaBody');
  if (tb) {
    tb.innerHTML = I.custs.slice(0, 4).map(c => {
      const hasContact = !!dialNumber(c.contact);

      return '<tr>' +
        '<td><span class="ledger-name">' + c.name + '</span>' +
          '<span class="cell-sub">' + (c.contact ? dialNumber(c.contact) : 'No contact on file') + '</span></td>' +
        '<td class="num ledger-balance"><span class="debt">P' + c.debt.toLocaleString() + '</span>' +
          '<span class="num-unit">' + c.pS + ' slim / ' + c.pR + ' round containers</span></td>' +
        '<td><div class="ledger-actions">' +
          '<button type="button" class="action-message" data-cust="' + c.id + '"' +
            (hasContact ? '' : ' aria-disabled="true" title="No contact number on file"') +
            '>' + icon('i-message') + 'Remind</button>' +
          '<button type="button" class="action-return" data-cust="' + c.id + '"' +
            ' title="Record a returned 5-Gal jug">' + icon('i-return') + 'Log Return</button>' +
          '<button class="btn btn-tertiary btn-sm" onclick="showSection(\'cust\')">Balances</button>' +
        '</div></td>' +
      '</tr>';
    }).join('');

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
function draftPO() {
  const rows = (Array.isArray(DB.advisories) && DB.advisories.length)
    ? DB.advisories.map(a => [a.item, a.order_quantity, a.supplier].join(','))
    : DB.inventory.filter(v => statusOf(v)[0] !== 'OK').map(v => v.item + ',' + orderQtyFrom(v) + ',' + v.supplier);

  const csv = 'Item,SuggestedQty,Supplier\n' + rows.join('\n');
  const blob = new Blob([csv], { type: 'text/csv' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = 'draft-PO.csv'; a.click();
}
