// Presentation only: unavailable thresholds produce no meter.
// markName names the tick for screen readers. Only a reorder point counts
// "at the mark" as low; for other marks (expected cash, ordered quantity)
// reaching the mark is the goal, so only falling short is low.
function levelMeterState(value, threshold, target, markName = 'reorder point') {
  if (value == null || threshold == null || !Number.isFinite(Number(value)) || !Number.isFinite(Number(threshold))) return null;
  const amount = Math.max(0, Number(value));
  const point = Math.max(0, Number(threshold));
  const scale = Math.max(amount, point, Number.isFinite(Number(target)) ? Number(target) : 0, 1);
  return { level: amount / scale, mark: point / scale, empty: amount === 0,
    low: point > 0 && (markName === 'reorder point' ? amount <= point : amount < point),
    label: amount.toLocaleString() + ' ' + ({ expected: 'counted', ordered: 'received' }[markName] || 'on hand') + '; ' + markName + ' ' + point.toLocaleString() };
}
function levelMeterHTML(value, threshold, target, markName) {
  const m = levelMeterState(value, threshold, target, markName);
  return m ? '<span class="level-meter' + (m.empty ? ' is-empty' : m.low ? ' is-low' : '') + '" role="img" aria-label="' + m.label + '"><span class="level-meter__fill" style="--level:' + m.level + '"></span><span class="level-meter__mark" style="--mark:' + m.mark + '"></span></span>' : '';
}
