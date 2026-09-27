// AquaFlow ADMIN portal - insight engine (Dashboard plain-words cards, advisories, custody ledger)
// Logic copied from the original AquaFlow prototype, re-rendered
// with the current semantic design-system classes.

function avg(a) { return a.reduce((x, y) => x + y, 0) / Math.max(1, a.length); }
function sum(a) { return a.reduce((x, y) => x + y, 0); }

// Daily consumption rate per inventory item, derived from forecast demand
function dailyUse(item) {
  const fAvg = avg(DB.forecast7); // ~285 gal/day
  if (item.includes('Caps')) return fAvg * 0.8;
  if (item.includes('Seals')) return fAvg * 0.8;
  if (item.includes('Filter')) return 0.15;
  if (item.includes('Soap')) return 1.2;
  if (item.includes('Sponges')) return 1.5;
  if (item.includes('Slim Jugs')) return 1.8;
  if (item.includes('Round Jugs')) return 1.4;
  return 1;
}

function daysCover(inv) { return inv.on / Math.max(0.01, dailyUse(inv.item)); }

function orderByDate(inv) {
  const d = new Date();
  d.setDate(d.getDate() + Math.floor(daysCover(inv) - inv.lead));
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function computeInsights() {
  const h = DB.history30, f = DB.forecast7;
  const avg30 = avg(h), last7 = avg(h.slice(-7)), prev7 = avg(h.slice(-14, -7));
  const trend = (last7 - prev7) / Math.max(1, prev7) * 100;
  const peak = Math.max(...h), peakDay = h.indexOf(peak) + 1;
  const low = Math.min(...h);
  const fTotal = sum(f), fAvg = avg(f);
  // weekend effect: every 6th/7th day in the 30d series spikes
  const weekend = avg(h.filter((_, i) => (i + 1) % 7 === 0 || (i + 1) % 7 === 6));
  const weekday = avg(h.filter((_, i) => (i + 1) % 7 !== 0 && (i + 1) % 7 !== 6));
  const uplift = (weekend - weekday) / Math.max(1, weekday) * 100;
  // inventory runway sorted by urgency
  const runway = DB.inventory.map(v => ({
    item: v.item, on: v.on, unit: v.unit, days: daysCover(v),
    status: statusOf(v)[0], rop: v.rop, lead: v.lead, supplier: v.supplier,
    orderQty: Math.max(0, Math.ceil(v.rop - v.on + v.ss)), orderBy: orderByDate(v)
  })).sort((a, b) => a.days - b.days);
  // customers: leakage value at P200 per unreturned jug (paper Ch I)
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
  // today's POS mix
  const cash = DB.transactions.filter(t => t.pay === 'Cash').length;
  const acct = DB.transactions.filter(t => t.pay === 'Account').length;
  return { avg30, last7, prev7, trend, peak, peakDay, low, fTotal, fAvg, weekend, weekday, uplift, runway, custs, totalDebt, totalBottles, totalExposure, topShare, worstReturn, cash, acct };
}

function renderInsights() {
  const I = computeInsights();
  const setText = (id, txt) => { const el = document.getElementById(id); if (el) el.textContent = txt; };

  // KPI row is computed, not hardcoded
  setText('kRev', 'P' + Math.round(I.last7 * 35).toLocaleString());
  setText('kRevSub', (I.trend >= 0 ? '+' : '') + I.trend.toFixed(1) + '% vs prior 7d avg');
  setText('kGal', Math.round(I.last7) + ' gal/day');
  setText('kGalSub', '30d avg ' + Math.round(I.avg30) + ' | forecast ' + Math.round(I.fAvg));
  const crit = I.runway.filter(r => r.status !== 'OK').length;
  setText('invHealth', crit + ' item(s) need reorder');
  setText('kLia', I.totalBottles + ' btls | P' + I.totalDebt.toLocaleString());
  setText('kLiaSub', 'Jug replacement value P' + (I.totalBottles * 200).toLocaleString() + ' at P200/jug');

  // Demand insight: translated, not summarized
  const insDemand = document.getElementById('insDemand');
  if (insDemand) {
    insDemand.innerHTML =
      '<div class="insight-row"><span>7-day sales trend</span><b class="' + (I.trend >= 0 ? 'text-green-700' : 'text-red-600') + '">' + (I.trend >= 0 ? 'UP ' : 'DOWN ') + Math.abs(I.trend).toFixed(1) + '%</b></div>' +
      '<div class="insight-row"><span>Weekend sales</span><b>+' + I.uplift.toFixed(0) + '% (' + Math.round(I.weekend) + ' vs ' + Math.round(I.weekday) + ' gal/day)</b></div>' +
      '<div class="insight-row"><span>Busiest / quietest (30 days)</span><b>' + I.peak + ' gal (day ' + I.peakDay + ') / ' + I.low + ' gal</b></div>' +
      '<div class="insight-row"><span>Expected sales next 7 days</span><b>' + I.fTotal.toLocaleString() + ' gal</b></div>' +
      '<p class="insight-note"><b>What this means:</b> customers buy ' + (I.uplift > 20 ? 'much more on weekends' : 'about the same every day') + ', and next week is worth about <b>P' + Math.round(I.fTotal * 35).toLocaleString() + '</b> at P35 per refill.<br>' +
      '<b>What to do:</b> ' + (I.uplift > 20 ? 'prepare ' + Math.round((I.weekend - I.weekday) * 2) + ' extra gallons before Friday and add one delivery run.' : 'keep the normal filling schedule.') + '<br>' +
      '<b>If ignored:</b> weekend stockout risks about P' + Math.round((I.weekend - I.weekday) * 2 * 35).toLocaleString() + ' in lost sales plus walkouts to competitors.</p>';
  }

  // Runway insight: translated
  const insRunway = document.getElementById('insRunway');
  if (insRunway) {
    insRunway.innerHTML = I.runway.slice(0, 4).map(r =>
      '<div class="insight-row"><span>' + r.item + ' <span class="insight-sub">' + r.on.toLocaleString() + ' ' + r.unit + ' left</span></span>' +
      '<b class="' + (r.days <= r.lead ? 'text-red-600' : r.status !== 'OK' ? 'text-yellow-700' : 'text-green-700') + '">' + r.days.toFixed(1) + ' days left</b></div>'
    ).join('') + '<p class="insight-note"><b>What this means:</b> ' + I.runway[0].item + ' runs out first. <b>What to do:</b> order ' + I.runway[0].orderQty.toLocaleString() + ' units from ' + I.runway[0].supplier + ' by ' + I.runway[0].orderBy + '. <b>If ignored:</b> refills stop even when demand is high, because every gallon needs a cap and seal. Reorder point = the stock level you should never fall below.</p>';
  }

  // Collection insight: translated
  const insCollect = document.getElementById('insCollect');
  if (insCollect) {
    const top3 = I.custs.slice(0, 3);
    insCollect.innerHTML = top3.map((c, i) =>
      '<div class="insight-row"><span>' + (i + 1) + '. ' + c.name + ' <span class="insight-sub">' + c.pS + 'S/' + c.pR + 'R bottles + P' + c.debt.toLocaleString() + '</span></span><b>P' + c.exposure.toLocaleString() + '</b></div>'
    ).join('') + '<p class="insight-note"><b>What this means:</b> ' + I.custs[0].name + ' holds ' + I.topShare + '% of everything owed (P' + I.totalExposure.toLocaleString() + ' total). ' + I.worstReturn.name + ' returns only ' + I.worstReturn.returnRate + '% of bottles.<br><b>What to do:</b> collect bottles before cash, each missing jug costs P200 to replace. <b>If ignored:</b> you keep buying new jugs at P250 deposit while old ones sit in customer homes.</p>';
  }

  // Action queue
  const insActions = document.getElementById('insActions');
  if (insActions) {
    const urgent = I.runway[0];
    insActions.innerHTML =
      '<div class="advisory-card advisory-critical"><b>1. Order today:</b> ' + urgent.item + ' will last only ' + urgent.days.toFixed(1) + ' more days (restock takes ' + urgent.lead + ' days). Order ' + urgent.orderQty.toLocaleString() + ' from ' + urgent.supplier + '. <button onclick="draftPO()" class="btn btn-primary btn-sm">Make a Purchase Request</button></div>' +
      '<div class="advisory-card advisory-info"><b>2. Collect:</b> visit ' + I.custs[0].name + ' (' + I.custs[0].contact + ') - P' + I.custs[0].exposure.toLocaleString() + ' owed. <button onclick="showSection(\'cust\')" class="btn btn-ghost btn-sm">Open Balances</button></div>' +
      '<div class="advisory-card advisory-watch"><b>3. Prepare for the weekend:</b> sales run +' + I.uplift.toFixed(0) + '% higher on weekends. Pre-fill bottles and check the filters before Friday. <button onclick="showSection(\'arima\')" class="btn btn-ghost btn-sm">View Forecast</button></div>';
  }

  // keep the dashboard advisory box in sync
  renderAdvisories();
  renderLedger();
}

// Dashboard right column: Spoon-Fed Restock Advisories
function renderAdvisories() {
  const el = document.getElementById('advisory');
  if (!el) return;
  const I = computeInsights();
  const worst = I.runway[0], next = I.runway[1];
  el.innerHTML =
    '<div class="advisory-card advisory-critical">CRITICAL: ' + worst.item + ' is almost out (' + worst.on.toLocaleString() + ' left, reorder at ' + worst.rop + '). About ' + worst.days.toFixed(1) + ' days left. Order ' + worst.orderQty.toLocaleString() + ' units from ' + worst.supplier + ' by ' + worst.orderBy + '. <button onclick="draftPO()" class="btn btn-primary btn-sm">Make a Purchase Request</button></div>' +
    '<div class="advisory-card advisory-watch">WATCH: ' + next.item + ' has ' + next.days.toFixed(1) + ' days left. Filters can clog - check the water flow now.</div>' +
    '<div class="advisory-card advisory-info">' + I.custs[0].name + ': ' + I.custs[0].pS + 'S/' + I.custs[0].pR + 'R bottles owed + P' + I.custs[0].debt.toLocaleString() + ' cash owed.</div>';
}

// Dashboard custody ledger: station jug totals + top debtors with Remind buttons
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
    tb.innerHTML = I.custs.slice(0, 4).map(c =>
      '<tr><td><b>' + c.name + '</b><span class="cell-sub">' + c.contact + '</span></td>' +
      '<td class="num">' + c.pS + 'S / ' + c.pR + 'R + P' + c.debt.toLocaleString() + '</td>' +
      '<td><button class="remind-btn" onclick="showSection(\'cust\')">Remind</button></td></tr>'
    ).join('');
  }
}

// Export a draft purchase order (CSV) for every item below its reorder point.
function draftPO() {
  const rows = DB.inventory.filter(v => statusOf(v)[0] !== 'OK').map(v => v.item + ',' + Math.max(0, v.rop - v.on + v.ss) + ',' + v.supplier);
  const csv = 'Item,SuggestedQty,Supplier\n' + rows.join('\n');
  const blob = new Blob([csv], { type: 'text/csv' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = 'draft-PO.csv'; a.click();
}
