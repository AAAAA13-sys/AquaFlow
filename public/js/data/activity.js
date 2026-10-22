// AquaFlow — "My activity" screen.
//
// One list, one renderer, every role. The server returns the signed-in
// account's own records for today (sales, order work, drawer, damaged-stock
// reports) already sorted oldest first, so this module only draws them.
//
// The page markup is expected to provide:
//   #activityList     the container the cards are written into
//   #activitySummary  one line describing the day
//   #activityCount    the number of entries
//   #activityUpdated  "Updated hh:mm" line
//   #activityError    the error banner
//   #pulseDot         the connection dot

let activityBusy = false;
let activityFlight = null;

function activityTime(iso) {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

function activityTone(tone) {
  const known = ['preparing', 'ready', 'out_for_delivery', 'delivered', 'damage', 'sale', 'payment', 'drawer', 'neutral'];
  return known.includes(tone) ? tone : 'neutral';
}

function activityBadge(entry) {
  const badge = document.createElement('span');
  badge.className = 'activity-badge activity-badge--' + activityTone(entry.tone);
  badge.textContent = entry.badge || 'Activity';
  return badge;
}

function activityLine(className, text) {
  const line = document.createElement('div');
  line.className = className;
  line.textContent = text;
  return line;
}

/** Builds one activity card. */
function activityCard(entry) {
  const card = document.createElement('article');
  card.className = 'activity-card';

  const head = document.createElement('div');
  head.className = 'activity-head';

  const left = document.createElement('div');
  left.append(activityLine('activity-title', entry.title || ''), activityLine('activity-subtitle', entry.subtitle || ''));

  const right = document.createElement('div');
  right.className = 'activity-right';
  const time = document.createElement('time');
  time.className = 'activity-time';
  time.textContent = activityTime(entry.at);
  right.append(activityBadge(entry), time);

  head.append(left, right);

  const meta = document.createElement('div');
  meta.className = 'activity-meta';
  if (entry.lines) meta.append(activityLine('activity-lines', entry.lines));
  if (entry.address) meta.append(activityLine('activity-address', entry.address));
  if (entry.note) meta.append(activityLine('activity-note', entry.note));
  if (entry.amount !== null && entry.amount !== undefined) {
    meta.append(activityLine('activity-amount', (entry.amount_label ? entry.amount_label + ': ' : '') + '₱' + Number(entry.amount).toFixed(2)));
  }

  card.append(head, meta);
  return card;
}

/** One line describing the day, built from the server's counts. */
function activitySummary(counts) {
  const total = Number(counts?.actions || 0);
  const parts = [total + (total === 1 ? ' action' : ' actions')];
  if (counts?.sales) parts.push(counts.sales + (counts.sales === 1 ? ' sale' : ' sales'));
  if (counts?.deliveries) parts.push(counts.deliveries + (counts.deliveries === 1 ? ' delivery' : ' deliveries'));
  if (counts?.damage) parts.push(counts.damage + (counts.damage === 1 ? ' damage report' : ' damage reports'));
  if (Number(counts?.cash || 0) > 0) {
    parts.push('₱' + Number(counts.cash).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' handled');
  }
  return parts.join(' · ');
}

function showActivityError(message) {
  const error = document.getElementById('activityError');
  if (!error) return;
  error.textContent = message || '';
  error.classList.toggle('hidden', !message);
}

async function refreshActivity() {
  if (activityBusy) return activityFlight;
  if (activityFlight) return activityFlight;

  activityFlight = (async () => {
    try {
      const data = await API.request('activity');
      if (!Array.isArray(data?.entries)) throw new Error('Activity list was incomplete. Try Refresh again.');
      window.activityEntries = data.entries;
      window.activityCounts = data.counts || {};
      document.getElementById('pulseDot')?.classList.remove('offline');
      const updated = document.getElementById('activityUpdated');
      if (updated) updated.textContent = 'Updated ' + new Date(data.updated_at || Date.now()).toLocaleTimeString();
      showActivityError(null);
    } catch (error) {
      document.getElementById('pulseDot')?.classList.add('offline');
      showActivityError((error.message || 'Cannot load your activity.') + ' Tap Refresh to retry.');
    } finally {
      renderActivity();
    }
  })();

  try { return await activityFlight; } finally { activityFlight = null; }
}

function renderActivity() {
  const container = document.getElementById('activityList');
  if (!container) return;

  const entries = Array.isArray(window.activityEntries) ? window.activityEntries : [];
  container.replaceChildren();

  const summary = document.getElementById('activitySummary');
  if (summary) summary.textContent = activitySummary(window.activityCounts);

  const count = document.getElementById('activityCount');
  if (count) count.textContent = String(entries.length);

  if (!entries.length) {
    const empty = document.createElement('div');
    empty.className = 'activity-empty';
    empty.textContent = 'Nothing recorded today. What you confirm will show up here.';
    container.append(empty);
    return;
  }

  for (const entry of entries) container.append(activityCard(entry));
}
