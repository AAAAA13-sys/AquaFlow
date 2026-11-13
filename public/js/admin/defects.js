// Defect & damaged-stock report queue (admin/owner).
//
// Fetched separately from /api/bootstrap because only management may read the
// list. Staff never receive defect reports in their snapshot at all.

let defectReports = [];
let defectLoadError = '';

async function loadDefectReports() {
  try {
    const data = await API.defectReports();
    defectReports = Array.isArray(data.reports) ? data.reports : [];
    defectLoadError = '';
  } catch (error) {
    appNotice((error.message || 'Could not load defect reports.') + ' Retry in a moment.');
    defectReports = [];
    defectLoadError = error.message || 'Could not load defect reports.';
  }
  renderDefectTable();
  renderDefectBadge();
}

function renderDefectBadge() {
  const el = document.getElementById('badgeDefects');
  if (!el) return;
  const pending = defectReports.filter(r => r.status === 'pending').length;
  el.textContent = pending > 0 ? String(pending) : '';
  el.hidden = !(pending > 0);
}

function renderDefectTable() {
  const body = document.getElementById('defectBody');
  if (!body) return;
  const summary = document.getElementById('defectSummary');
  if (summary) {
    const pending = defectReports.filter(r => r.status === 'pending').length;
    summary.textContent = defectLoadError ? 'Defect reports are unavailable.' : pending ? pending + (pending === 1 ? ' defect report awaits' : ' defect reports await') + ' a decision.' : 'No defect reports await a decision.';
  }

  const status = (document.getElementById('defectStatus')?.value || '').trim().toLowerCase();
  const rows = filterTableRows(defectReports, 'defectBody')
    .filter(r => !status || r.status === status);

  if (defectLoadError) {
    body.innerHTML = '<tr><td colspan="7"><div class="error-state" role="alert"><p class="error-state__message">Defect reports could not be loaded. ' + esc(defectLoadError) + '</p>' +
      '<button type="button" class="btn btn-secondary btn-sm error-state__retry" onclick="loadDefectReports()">Retry</button></div></td></tr>';
    return;
  }

  if (!rows.length) {
    body.innerHTML = defectReports.length
      ? '<tr><td colspan="7"><div class="empty-state"><p class="empty-state__title">No reports match these filters</p><p class="empty-state__body">Change the status or clear the search.</p></div></td></tr>'
      : '<tr><td colspan="7"><div class="empty-state"><p class="empty-state__title">No defect reports filed yet.</p><p class="empty-state__body">Damaged stock your refillers and riders report will wait here for a decision.</p></div></td></tr>';
    return;
  }

  body.innerHTML = rows.map(r => {
    const item = r.inventory_item || {};
    const reportedBy = r.reported_by ? r.reported_by.name : 'Former staff';
    const reason = esc(r.reason || '-');
    const created = r.created_at ? new Date(r.created_at).toLocaleString() : '-';
    const statusPill = r.status === 'pending'
      ? '<span class="pill pill-bad">Pending</span>'
      : r.status === 'approved'
        ? '<span class="cell-quiet">Approved</span>'
        : '<span class="cell-quiet">Rejected</span>';

    const actions = r.status === 'pending'
      ? '<button type="button" class="btn btn-primary btn-sm" onclick="resolveDefect(' + r.id + ', \'approve\')">Approve</button>' +
        '<button type="button" class="btn btn-ghost btn-sm" onclick="resolveDefect(' + r.id + ', \'reject\')">Reject</button>'
      : '<span class="cell-sub">' + (r.resolved_at ? new Date(r.resolved_at).toLocaleString() : '-') + '</span>';

    return '<tr>' +
      '<td><b>' + esc(reportedBy) + '</b></td>' +
      '<td>' + esc(item.item || '-') + '<span class="cell-sub">' + esc(item.unit || 'pcs') + '</span></td>' +
      '<td class="num">' + Number(r.quantity).toLocaleString() + '</td>' +
      '<td>' + reason + '</td>' +
      '<td class="text-center">' + statusPill + '</td>' +
      '<td>' + created + '</td>' +
      '<td class="text-right"><div class="row-actions">' + actions + '</div></td>' +
    '</tr>';
  }).join('');
  showTableEmpty(body, 7);
}

async function resolveDefect(id, action) {
  const report = defectReports.find(r => r.id === id);
  if (!report) return;
  const item = report.inventory_item || {};
  const actionLabel = action === 'approve' ? 'Approve' : 'Reject';

  let notes = null;
  if (action === 'reject') {
    notes = await appPrompt('Why is this report rejected? (optional notes for the staff member)', 'Not damage, still usable', actionLabel);
    if (notes === null) return; // cancelled
  } else {
    const ok = await appConfirm(
      actionLabel + ' this report?\n\n' +
      (report.reported_by ? report.reported_by.name : 'Former staff') + ' reported ' +
      Number(report.quantity).toLocaleString() + ' × ' + (item.item || 'item') + ' — ' + esc(report.reason) +
      '\n\nApproving deducts these units from stock.',
      actionLabel + ' report'
    );
    if (!ok) return;
  }

  try {
    await API.resolveDefectReport(id, action, notes);
    await loadDefectReports();
    appNotice(action === 'approve'
      ? 'Report #' + id + ' approved. Stock updated.'
      : 'Report #' + id + ' rejected.');
  } catch (error) {
    appNotice(error.message || 'Could not ' + actionLabel.toLowerCase() + ' this report.');
    await loadDefectReports();
  }
}
