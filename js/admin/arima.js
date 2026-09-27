// AquaFlow ADMIN portal - ARIMA analytics + forecast charts
// Logic copied from the original AquaFlow prototype, re-rendered
// with the current semantic design-system classes.

function horizonDays() {
  const el = document.getElementById('fHorizon');
  const v = el ? el.value : 'Daily';
  return v === 'Weekly' ? 7 : v === 'Monthly' ? 30 : 1;
}

function seriesFactor() {
  const el = document.getElementById('fSeries');
  return (el && el.value === 'Heat Shrink Seals') ? 0.8 : 1;
}

function agg(arr, size) {
  const out = [];
  for (let i = 0; i < arr.length; i += size) out.push(sum(arr.slice(i, i + size)));
  return out;
}

function renderForecast() {
  const hz = horizonDays(), f = seriesFactor();
  const seriesEl = document.getElementById('fSeries');
  const unit = (seriesEl && seriesEl.value === 'Heat Shrink Seals') ? 'seals' : 'gal';
  const fc = DB.forecast7.map(v => Math.round(v * f));
  const rows = hz === 1
    ? fc.map((v, i) => ['Day ' + (i + 1) + ' forecast', v])
    : agg(fc, hz).map((v, i) => [(hz === 7 ? 'Week ' : 'Month ') + (i + 1) + ' forecast', v]);

  const metaHtml =
    '<span class="pill pill-info">Forecast: ' + DB.model.order + '</span> <span class="pill pill-ok">Good fit (error ' + DB.model.mape + '%)</span>';

  const fcMeta = document.getElementById('fcMeta');
  if (fcMeta) fcMeta.innerHTML = metaHtml;

  const fcTable = document.getElementById('fcTable');
  if (fcTable) {
    const lead3 = Math.round(sum(DB.forecast7.slice(0, 3)) * f);
    fcTable.innerHTML = rows.map(r =>
      '<div class="insight-row"><span>' + r[0] + '</span><b>' + r[1].toLocaleString() + ' ' + unit + '</b></div>'
    ).join('') + '<p class="insight-note"><b>What this means:</b> you will need about ' + lead3.toLocaleString() + ' ' + unit + ' over the next 3 restock days.<br><b>What to do:</b> order 600 caps from SealPack now, because caps cover only 2 days and restock takes 2 days.<br><b>Model confidence:</b> ARIMA error is ' + DB.model.mape + '% (MAPE), good enough for ordering. Full accuracy table lives in the thesis evaluation.</p>';
  }

  const fcMetaDash = document.getElementById('fcMetaDash');
  if (fcMetaDash) fcMetaDash.innerHTML = metaHtml;
}

let chDemand = null, chArima = null;

function renderDemandChart() {
  const el = document.getElementById('chDemand');
  if (!el || typeof Chart === 'undefined') return;
  const hist = DB.history30, fc = DB.forecast7;
  const last14 = hist.slice(-14);
  const labels = [...last14.map((_, i) => 'D' + (i + 17)), ...fc.map((_, i) => 'F' + (i + 1))];
  if (chDemand) chDemand.destroy();
  chDemand = new Chart(el, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'History', data: [...last14, ...Array(fc.length).fill(null)], backgroundColor: '#0EA5E9' },
        { label: 'Forecast', type: 'line', data: [...Array(last14.length - 1).fill(null), last14[last14.length - 1], ...fc], borderColor: '#F59E0B', borderDash: [6, 4], tension: 0.3 }
      ]
    },
    options: { plugins: { legend: { display: true } }, scales: { y: { beginAtZero: true } } }
  });
}

function renderArimaChart() {
  const el = document.getElementById('chArima');
  if (!el || typeof Chart === 'undefined') return;
  const hz = horizonDays(), f = seriesFactor();
  const hist = DB.history30.map(v => Math.round(v * f));
  const fc = DB.forecast7.map(v => Math.round(v * f));
  let labels, hData, fData;
  if (hz === 1) {
    labels = [...hist.map((_, i) => 'D' + (i + 1)), ...fc.map((_, i) => 'F' + (i + 1))];
    hData = [...hist, ...Array(fc.length).fill(null)];
    fData = [...Array(hist.length - 1).fill(null), hist[hist.length - 1], ...fc];
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
        { label: hz === 1 ? 'History (30d)' : 'History (aggregated)', data: hData, backgroundColor: '#0EA5E9' },
        { label: 'Forecast (7d)', type: 'line', data: fData, borderColor: '#7C3AED', borderDash: [6, 4], tension: 0.3 }
      ]
    },
    options: { plugins: { legend: { display: true } }, scales: { y: { beginAtZero: true } } }
  });
}

// Planned: export the current ARIMA forecast table/chart as CSV or PDF.
function exportReport() {
  alert('Report export is not wired up yet - planned for the thesis demo.');
}
