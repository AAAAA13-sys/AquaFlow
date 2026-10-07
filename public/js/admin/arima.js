// Series Mapping & Selectors
const ARIMA_SERIES = {
  'Refill gallons': 'Refill Gallons',
  'Heat Shrink Seals': 'Heat Shrink Seals',
  'Non-Spill Caps': 'Non-Spill Caps',
  'SHRINK_SLIM': 'SHRINK_SLIM',
  'SHRINK_ROUND': 'SHRINK_ROUND',
  'CLEAR_COVER': 'CLEAR_COVER',
  'Sediment Filters': 'Sediment Filters'
};

function currentSeriesName() {
  const select = document.getElementById('fSeries');
  const label = select ? select.value : 'Refill gallons';
  return ARIMA_SERIES[label] || 'Refill Gallons';
}

function currentSeriesRecord() {
  const name = currentSeriesName();
  if (DB.forecasts && DB.forecasts[name]) return DB.forecasts[name];
  if (name === 'Refill Gallons') {
    return { series: name, history: DB.history || DB.history30 || [], forecast: DB.forecast7 || [], model: DB.model || {} };
  }
  return null;
}

function currentSeriesUnit() {
  const name = currentSeriesName();
  if (name === 'Refill Gallons') return 'gal';
  if (name === 'Sediment Filters') return 'units';
  return 'pcs';
}


// Forecast Horizon & Aggregators
function horizonDays() {
  const el = document.getElementById('fHorizon');
  const v = el ? el.value : 'Daily';
  return v === 'Weekly' ? 7 : v === 'Monthly' ? 30 : 1;
}

function agg(arr, size) {
  const out = [];
  for (let i = 0; i < arr.length; i += size) out.push(sum(arr.slice(i, i + size)));
  return out;
}


// Forecast Table & Metrics Display
function renderForecast() {
  const hz = horizonDays();
  const record = currentSeriesRecord();
  const unit = currentSeriesUnit();

  const meta = document.getElementById('fcMeta');
  const table = document.getElementById('fcTable');
  const metaDash = document.getElementById('fcMetaDash');

  if (!record || !record.forecast || !record.forecast.length) {
    const message = 'No forecast stored yet. Use "Update Forecast" to run the ARIMA service.';
    if (meta) meta.innerHTML = '<span class="pill pill-warn">' + message + '</span>';
    if (table) table.innerHTML = '<p class="insight-note">' + message + '</p>';
    if (metaDash) metaDash.innerHTML = meta ? meta.innerHTML : '';
    return;
  }

  const model = record.model || {};
  const forecast = record.forecast.map(Number);
  const rows = hz === 1
    ? forecast.map((v, i) => ['Day ' + (i + 1) + ' forecast', v])
    : agg(forecast, hz).map((v, i) => [(hz === 7 ? 'Week ' : 'Month ') + (i + 1) + ' forecast', v]);

  const seasonalPill = model.method === 'arima_seasonal'
    ? '<span class="pill pill-ok" title="Detects a repeating weekly pattern">Follows your weekly pattern</span>'
    : '';

  // Pill labels are written for a station owner, not a statistician. The exact
  // figures stay in the tooltip and in the explanation below, so a reviewer can
  // still read ARIMA order, MAPE and the Ljung-Box result.
  const mapeNum = model.mape == null ? NaN : Number(model.mape);
  const mapePill = Number.isFinite(mapeNum)
    ? '<span class="pill ' + (mapeNum <= 10 ? 'pill-ok' : mapeNum <= 20 ? 'pill-warn' : 'pill-bad') +
      '" title="MAPE ' + mapeNum + '% — average error when the model was tested on days it had not seen">Average test error: ' +
      mapeNum.toFixed(1) + '%</span>'
    : '';
  const residOk = model.ljung_box_pvalue > 0.05;
  const residPill = model.ljung_box_pvalue == null ? '' : '<span class="pill ' + (residOk ? 'pill-ok' : 'pill-warn') +
    '" title="Residual diagnostic: Ljung-Box p ' + model.ljung_box_pvalue + '">' + (residOk ? 'No residual pattern detected' : 'Residual pattern detected') + '</span>';

  const warning = model.history_warning || (String(model.method).startsWith('naive') ? 'Fallback estimate; ARIMA requires sufficient reliable history.' : '');
  const metaHtml = warning ? '<span class="pill pill-warn">' + esc(warning) + '</span>' :
    '<span class="pill pill-info" title="' + (model.order || 'ARIMA') + '">Forecast model</span> ' +
    seasonalPill + ' ' + mapePill + ' ' + residPill;

  if (meta) meta.innerHTML = metaHtml;
  if (metaDash) metaDash.innerHTML = metaHtml;

  if (table) {
    const next3 = Math.round(forecast.slice(0, 3).reduce((a, b) => a + b, 0));
    const firstItem = (DB.advisories && DB.advisories[0]) ? DB.advisories[0] : null;
    const action = firstItem
      ? 'order ' + Number(firstItem.order_quantity).toLocaleString() + ' ' + firstItem.unit + ' of ' + firstItem.item + ' from ' + firstItem.supplier
      : 'check the Stock & Supplies tab for what to order';

    table.innerHTML = rows.map(r =>
      '<div class="insight-row"><span>' + r[0] + '</span><b>' + Math.round(r[1]).toLocaleString() + ' ' + unit + '</b></div>'
    ).join('') + '<p class="insight-note"><b>What this means:</b> about ' + next3.toLocaleString() + ' ' + unit + ' are projected for the next 3 days.<br>' +
    '<b>What to do:</b> ' + action + '.<br>' +
    '<b>How reliable is this?</b> When AquaFlow was tested on days it had not seen before, its answer was off by about ' + (model.mape ?? '-') +
    '% on average <span class="term-hint">(MAPE ' + (model.mape ?? '-') + '%)</span>' +
    (model.ljung_box_pvalue !== undefined
      ? ', and its residual test ' + (residOk ? 'found no significant remaining pattern' : 'found a remaining pattern') +
        ' <span class="term-hint">(Ljung-Box p = ' + model.ljung_box_pvalue + ')</span>'
      : '') + '. Treat it as a good guide, not an exact number.</p>';
  }
}



// Demand Chart (14-Day History + 7-Day Forecast)
let chDemand = null, chArima = null;

function renderDemandChart() {
  const el = document.getElementById('chDemand');
  if (!el || typeof Chart === 'undefined') return;
  const record = currentSeriesRecord();
  const hist = (record && record.history) ? record.history : (DB.history || DB.history30);
  const fc = (record && record.forecast) ? record.forecast : DB.forecast7;
  if (!hist || !fc) return;

  const last14 = hist.slice(-30);
  const labels = [...last14.map((_, i) => 'D' + (i + Math.max(1, hist.length - 29))), ...fc.map((_, i) => 'F' + (i + 1))];
  if (chDemand) chDemand.destroy();
  chDemand = new Chart(el, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'History', data: [...last14, ...Array(fc.length).fill(null)], backgroundColor: '#1D4ED8' },
        { label: 'Forecast', type: 'line', data: [...Array(Math.max(0, last14.length - 1)).fill(null), ...(last14.length ? [last14[last14.length - 1]] : []), ...fc], borderColor: '#DC2626', borderDash: [6, 4], tension: 0.3 }
      ]
    },
    options: {
      responsive: true,
      // Chart.js sizes the canvas from its content, so an axis with many wide
      // labels (real dates, autoSkip off) expands the card instead of the
      // card containing it. Skipping labels keeps the axis narrow. Do not set
      // autoSkip: false here.
      plugins: {
        legend: { display: true },
        tooltip: { mode: 'index', intersect: false }
      },
      scales: {
        y: { beginAtZero: true },
        x: { ticks: { autoSkip: true, maxTicksLimit: 12, maxRotation: 0 }, grid: { display: false } }
      }
    }
  });
}

// ARIMA Model Chart (Multi-Horizon)
function renderArimaChart() {
  const el = document.getElementById('chArima');
  if (!el || typeof Chart === 'undefined') return;
  const record = currentSeriesRecord();
  if (!record || !record.history) return;

  const hz = horizonDays();
  const hist = record.history.map(Number);
  const fc = record.forecast.map(Number);
  let labels, hData, fData;

  if (hz === 1) {
    const recent = hist.slice(-30);
    labels = [...recent.map((_, i) => 'D' + (Math.max(0, hist.length - 30) + i + 1)), ...fc.map((_, i) => 'F' + (i + 1))];
    hData = [...recent, ...Array(fc.length).fill(null)];
    fData = [...Array(Math.max(0, recent.length - 1)).fill(null), ...(recent.length ? [recent[recent.length - 1]] : []), ...fc];
  } else {
    const hAgg = agg(hist, hz), fAgg = agg(fc, hz);
    const p = hz === 7 ? 'W' : 'M';
    labels = [...hAgg.map((_, i) => p + (i + 1)), ...fAgg.map((_, i) => 'F' + p + (i + 1))];
    hData = [...hAgg, ...Array(fAgg.length).fill(null)];
    fData = [...Array(hAgg.length).fill(null), ...fAgg];
  }

  if (chArima) chArima.destroy();
  chArima = new Chart(el, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: hz === 1 ? 'History' : 'History (aggregated)', data: hData, backgroundColor: '#1D4ED8' },
        { label: 'Forecast', type: 'line', data: fData, borderColor: '#DC2626', borderDash: [6, 4], tension: 0.3 }
      ]
    },
    options: { plugins: { legend: { display: true } }, scales: { y: { beginAtZero: true } } }
  });
}


// Recompute & CSV Export Actions
async function runForecast() {
  const button = document.getElementById('btnRunForecast');
  if (button) {
    button.disabled = true;
    button.textContent = 'Running...';
  }
  try {
    const result = await API.runForecast();
    if (result.forecasts) DB.forecasts = result.forecasts;
    if (result.inventory) DB.inventory = result.inventory;
    if (result.advisories) DB.advisories = result.advisories;

    saveDB();
    renderForecast();
    renderArimaChart();
    renderDemandChart();
    if (typeof renderInvTable === 'function') renderInvTable();
    if (typeof renderInsights === 'function') renderInsights();
    alert('Forecast updated from the ARIMA service.');
  } catch (error) {
    alert(error.message || 'Could not update the forecast. Is the analytics service running?');
  } finally {
    if (button) {
      button.disabled = false;
      button.textContent = 'Update Forecast';
    }
  }
}

async function reportSales() {
  const sales = [];
  let page = 1;
  let response;
  do {
    response = await API.transactions({ paginated: 1, page: page++ });
    sales.push(...response.transactions);
  } while (response.has_more);
  return sales;
}

async function exportReport() {
  const record = currentSeriesRecord();
  if (!record) {
    alert('No forecast available to export.');
    return;
  }

  let sales;
  try { sales = await reportSales(); } catch (error) { alert(error.message); return; }
  const history = record.history || [];
  const forecast = record.forecast || [];
  const rows = ['Day,History,Forecast'];
  const length = Math.max(history.length, forecast.length);
  for (let i = 0; i < length; i++) {
    rows.push((i + 1) + ',' + (history[i] !== undefined ? history[i] : '') + ',' + (forecast[i] !== undefined ? forecast[i] : ''));
  }

  const model = record.model || {};
  const header = '# AquaFlow ARIMA report\n' +
    '# Series,' + record.series + '\n' +
    '# Model,' + (model.order || '') + '\n' +
    '# Method,' + (model.method || '') + '\n' +
    '# MAPE,' + (model.mape ?? '') + '\n' +
    '# MAE,' + (model.mae ?? '') + '\n' +
    '# RMSE,' + (model.rmse ?? '') + '\n' +
    '# AIC,' + (model.aic ?? '') + '\n' +
    '# ADF p-value,' + (model.adf_pvalue ?? '') + '\n' +
    '# Ljung-Box p-value,' + (model.ljung_box_pvalue ?? '') + '\n';

  const cell = value => '"' + String(value ?? '').replace(/"/g, '""') + '"';
  const section = (title, columns, values) => '\n\n' + title + '\n' + columns.map(cell).join(',') + '\n' + values.map(row=>row.map(cell).join(',')).join('\n');
  const csv = header + rows.join('\n') +
    section('Inventory',['Item','Stock','Unit','Safety stock','Reorder point','Supplier'],DB.inventory.map(v=>[v.item,v.on,v.unit,v.ss,v.rop,v.supplier])) +
    section('Sales',['Receipt','Date','Customer','Total','Payment'],sales.map(t=>[t.no,t.date,t.cust,t.total,t.pay])) +
    section('Advisories',['Item','Supplier','Order quantity'],(DB.advisories||[]).map(a=>[a.item,a.supplier,a.order_qty]));
  downloadCSV(csv, 'arima-report-' + String(record.series).replace(/[^a-z0-9]+/gi, '-').toLowerCase() + '-' + new Date().toISOString().replace(/[:.]/g, '-') + '.csv');
}

function saveForecastView() {
  try { localStorage.setItem('aquaflow.forecastView', document.getElementById('fHorizon').value); } catch (_) {}
  renderArimaChart(); renderForecast();
}
function restoreForecastView() {
  const select = document.getElementById('fHorizon');
  if (!select) return;
  try { const value = localStorage.getItem('aquaflow.forecastView'); if (['Daily','Weekly','Monthly'].includes(value)) select.value = value; } catch (_) {}
}
async function exportReportPDF() {
  const popup = window.open('', '_blank');
  if (!popup) { alert('Allow popups to open the printable report.'); return; }
  let sales;
  try { sales = await reportSales(); } catch (error) { popup.close(); alert(error.message); return; }
  const table = (title, headers, rows) => '<h2>' + esc(title) + '</h2><table><thead><tr>' + headers.map(h => '<th>' + esc(h) + '</th>').join('') + '</tr></thead><tbody>' + rows.map(row => '<tr>' + row.map(cell => '<td>' + esc(String(cell ?? '')) + '</td>').join('') + '</tr>').join('') + '</tbody></table>';
  const forecasts = Object.entries(DB.forecasts || {}).flatMap(([name, record]) => (record.forecast || []).map((value, index) => [name, index + 1, value, record.model?.mape]));
  popup.document.write('<!doctype html><html><head><meta charset="utf-8"><title>AquaFlow report ' + new Date().toISOString().slice(0,10) + '</title><style>body{font:12px sans-serif}table{border-collapse:collapse;width:100%;margin-bottom:20px}th,td{border:1px solid #ccc;padding:6px;text-align:left}thead{display:table-header-group}tr{break-inside:avoid}</style></head><body><h1>AquaFlow Report</h1><p>Generated ' + esc(new Date().toLocaleString()) + '</p>' + table('Sales', ['Receipt','Date','Customer','Total','Payment'], sales.map(t=>[t.no,t.date,t.cust,t.total,t.pay])) + table('Inventory', ['Item','On hand','Unit','Safety stock','Reorder point','Supplier'], DB.inventory.map(v=>[v.item,v.on,v.unit,v.ss,v.rop,v.supplier])) + table('Forecasts',['Series','Day','Demand','MAPE'],forecasts) + table('Advisories',['Item','Supplier','Order quantity'],(DB.advisories||[]).map(a=>[a.item,a.supplier,a.order_qty])) + '</body></html>');
  popup.document.close(); popup.focus(); popup.print();
}
