// Approval Requests - the maker-checker review screen.
//
// Two things shape this file.
//
// First, the server decides who may decide. `can_decide` arrives on every row
// already computed from the viewer's live role and the no-self-approval rule.
// The buttons below mirror it for clarity; the decision endpoint re-checks the
// same conditions, so a tampered page gains nothing.
//
// Second, nothing on this screen changes a balance, a price or a recipe until a
// decision is approved. A pending collection shows the cash that is already in
// hand, because the customer's ledger has not moved yet.

const APPROVAL_ACTION_LABELS = { create: 'Created', update: 'Changed', delete: 'Removed' };

let approvalCache = [];

// The fields a viewer reads on a proposal, in plain language and in a stable
// order. Anything not named here is still shown, keyed by its own name, so a
// new field is never silently hidden.
const APPROVAL_FIELD_LABELS = {
  name: 'Name',
  price: 'Price',
  category: 'Category',
  container_kind: 'Container',
  walk_in_only: 'Walk-in only',
  sold_at_pos: 'Sold at the till',
  item_name: 'Item',
  unit: 'Unit',
  lead_time_days: 'Delivery time (days)',
  supplier_id: 'Supplier',
  consumables: 'Recipe',
  amount: 'Cash received',
  customer_name: 'Customer',
  balance_before: 'Balance before'
};

function approvalFieldLabel(key) {
  return APPROVAL_FIELD_LABELS[key] || key.replace(/_/g, ' ').replace(/^./, c => c.toUpperCase());
}

function approvalStateLabel(state) {
  return { pending: 'Waiting for a decision', approved: 'Approved', rejected: 'Rejected', cancelled: 'Withdrawn' }[state] || state;
}

function approvalRoleLabel(role) {
  return { super_admin: 'Owner', admin: 'Manager', cashier: 'Cashier', refiller: 'Refiller', rider: 'Rider' }[role] || role || '';
}

async function loadApprovals() {
  const list = document.getElementById('approvalList');
  if (!list) return;

  const params = {};
  const state = document.getElementById('approvalState')?.value;
  const type = document.getElementById('approvalType')?.value;
  if (state) params.state = state;
  if (type) params.type = type;

  try {
    const result = await API.approvals(params);
    approvalCache = result.requests || [];
    renderApprovals();
    SidebarApprovals.pendingCount = Number(result.pending_count || 0);
    renderSidebarBadges();
  } catch (error) {
    list.innerHTML = '<p class="empty-state" role="alert">' + esc(error.message || 'Could not load approval requests.') + '</p>';
  }
}

function renderApprovals() {
  const list = document.getElementById('approvalList');
  if (!list) return;

  const summary = document.getElementById('approvalSummary');
  if (summary) {
    const pending = approvalCache.filter(r => r.is_pending).length;
    const mine = approvalCache.filter(r => r.is_pending && r.can_decide).length;
    summary.innerHTML =
      '<article><span>Waiting for a decision</span><strong>' + pending + '</strong><small>Nothing has changed yet</small></article>' +
      '<article><span>You can decide now</span><strong>' + mine + '</strong><small>Routed to your level</small></article>' +
      '<article><span>On this list</span><strong>' + approvalCache.length + '</strong><small>Across the chosen filter</small></article>';
  }

  if (!approvalCache.length) {
    list.innerHTML = '<p class="empty-state">Nothing here. Changes that need approval will appear on this list.</p>';
    return;
  }

  list.innerHTML = approvalCache.map(row => approvalCard(row)).join('');
}

/**
 * One request, as a card.
 *
 * A settlement is called out specially: the cash is already handed over but the
 * ledger has not moved. Saying so on the card is what stops a second collection
 * of the same pesos at the counter.
 */
function approvalCard(row) {
  const requester = row.requested_by || {};
  const payload = row.payload || {};
  const isSettlement = row.type === 'settlement.create';

  const fields = Object.keys(payload)
    .filter(key => key !== 'customer_id' && key !== 'submission_key')
    .map(key => {
      const value = key === 'amount' || key === 'balance_before' || key === 'price'
        ? money(payload[key])
        : key === 'consumables'
          ? consumablesSummary(payload[key])
          : esc(payload[key]);
      return '<div class="approval-field"><span>' + esc(approvalFieldLabel(key)) + '</span><b>' + value + '</b></div>';
    }).join('');

  const actions = row.is_pending
    ? '<div class="approval-actions">' +
        (row.can_decide
          ? '<button type="button" class="btn btn-primary btn-sm" onclick="decideApproval(' + row.id + ', \'approve\')">Approve</button>' +
            '<button type="button" class="btn btn-ghost btn-sm" onclick="decideApproval(' + row.id + ', \'reject\')">Reject</button>'
          : '<span class="approval-wait">' + esc(approvalRoutingNote(row)) + '</span>') +
        (row.can_cancel
          ? '<button type="button" class="btn btn-ghost btn-sm" onclick="cancelApproval(' + row.id + ')">Withdraw</button>'
          : '') +
      '</div>'
    : '';

  const decision = row.decided_by
    ? '<p class="approval-decision">' + esc(approvalStateLabel(row.state)) + ' by ' + esc(row.decided_by.name || '') +
      (row.decision_note ? ' - ' + esc(row.decision_note) : '') + '</p>'
    : '';

  return '<article class="approval-card" data-state="' + esc(row.state) + '">' +
    '<header class="approval-head">' +
      '<div><span class="approval-kind">' + esc(row.label || row.type) + '</span>' +
      '<b class="approval-title">' + esc(payload.name || payload.item_name || payload.customer_name || row.target_id || '') + '</b></div>' +
      '<span class="approval-status approval-status--' + esc(row.state) + '">' + esc(approvalStateLabel(row.state)) + '</span>' +
    '</header>' +
    (isSettlement
      ? '<p class="approval-warning" role="note">Cash already received. The customer still owes the full amount until this is approved.</p>'
      : '') +
    '<div class="approval-fields">' + fields + '</div>' +
    '<p class="approval-meta">Requested by ' + esc(requester.name || '') +
      (requester.role ? ' (' + esc(approvalRoleLabel(requester.role)) + ')' : '') +
      (row.reason ? ' - ' + esc(row.reason) : '') + '</p>' +
    decision + actions +
  '</article>';
}

/** Why a pending request is not this viewer's to decide. */
function approvalRoutingNote(row) {
  const requester = row.requested_by || {};
  if (requester.id === (getSession()?.u)) return 'Your own request - another account must decide it.';
  return 'Waiting for a more senior role to decide.';
}

function consumablesSummary(list) {
  if (!Array.isArray(list) || !list.length) return esc('None');
  return esc(list.map(item => 'Item ' + item.inventory_item_id + ' x' + (item.qty_per_sale || 1)).join(', '));
}

async function decideApproval(id, decision) {
  const row = approvalCache.find(r => r.id === id);
  if (!row) return;

  let note = null;
  if (decision === 'reject') {
    note = await appPrompt('Why is this rejected? The maker will see this note.', '', 'Reject request');
    if (note === null) return;
    if (!String(note).trim()) { appNotice('A rejection needs a reason.'); return; }
  } else {
    const confirmed = await appConfirm(
      row.type === 'settlement.create'
        ? 'Approve this collection? The customer\u2019s balance drops by ' + money(row.payload?.amount || 0) + ' and a receipt is issued.'
        : 'Approve this change? It takes effect immediately.',
      'Approve'
    );
    if (!confirmed) return;
  }

  try {
    const result = await API.decideApproval(id, decision, note);
    appNotice(decision === 'approve' ? 'Approved. The change has been applied.' : 'Rejected. Nothing was changed.');
    await loadApprovals();
    // An approved collection changes a customer balance; refresh the snapshot
    // so other screens do not keep showing stale debt.
    if (result.request?.type === 'settlement.create' && decision === 'approve' && typeof loadFromServer === 'function') {
      await loadFromServer();
    }
    renderSidebarBadges();
  } catch (error) {
    appNotice(error.message || 'Could not record the decision.');
  }
}

async function cancelApproval(id) {
  const confirmed = await appConfirm('Withdraw this request? It will not be applied.', 'Withdraw');
  if (!confirmed) return;

  try {
    await API.cancelApproval(id);
    appNotice('Request withdrawn. Nothing was changed.');
    await loadApprovals();
    renderSidebarBadges();
  } catch (error) {
    appNotice(error.message || 'Could not withdraw the request.');
  }
}
