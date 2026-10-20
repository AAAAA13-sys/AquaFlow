// Defect & damaged-stock report queue (admin/owner).
//
// Fetched separately from /api/bootstrap because only management may read the
// list. Staff never receive defect reports in their snapshot at all.

let defectReports = [];

async function loadDefectReports() {
  try {
    const data = await API.defectReports();
    defectReports = Array.isArray(data.reports) ? data.reports : [];
  } catch (error) {
    appNotice((error.message || 'Could not load defect reports.') + ' Retry in a moment.');
    defectReports = [];
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
    const approved = defectReports.filter(r => r.status === 'approved').length;
    const rejected = defectReports.filter(r => r.status === 'rejected').length;
    summary.innerHTML =
      '<article><span>Pending</span><strong>' + pending + '</strong><small>Awaiting a decision</small></article>' +
      '<article><span>Approved</span><strong>' + approved + '</strong><small>Written off stock</small></article>' +
      '<article><span>Rejected</span><strong>' + rejected + '</strong><small>Not a loss</small></article>';
  }

  const status = (document.getElementById('defectStatus')?.value || '').trim().toLowerCase();
  const rows = filterTableRows(defectReports, 'defectBody')
    .filter(r => !status || r.status === status);

  if (!rows.length) {
    body.innerHTML = '<tr><td colspan="7" class="text-center empty-cell" style="padding:2rem;color:#64748b;">No defect reports filed yet.</td></tr>';
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
        ? '<span class="pill pill-ok">Approved</span>'
        : '<span class="pill pill-neutral">Rejected</span>';

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
      '<td class="text-right"><div style="display:flex;gap:6px;justify-content:flex-end;">' + actions + '</div></td>' +
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
