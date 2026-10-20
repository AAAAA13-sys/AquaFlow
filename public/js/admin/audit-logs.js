// Audit Log - who changed what, filtered to the viewer's level.
//
// The rank filter is applied by the server, not here. This screen sends the
// filters the operator chose and renders what comes back; it never assumes it
// can see everything, and it never widens the result on its own.

const AUDIT_ENTITY_LABELS = {
  product: 'Till item',
  inventoryitem: 'Stock',
  supplier: 'Supplier',
  customer: 'Customer'
};

const AUDIT_CONTEXT_LABELS = {
  direct: 'Direct change',
  approval: 'Approved request',
  submission: 'Counter collection'
};

const AUDIT_FIELD_LABELS = {
  price: 'Price',
  name: 'Name',
  item_name: 'Item',
  category: 'Category',
  unit: 'Unit',
  lead_time_days: 'Delivery time (days)',
  supplier_id: 'Supplier',
  debt_balance: 'Balance',
  contact: 'Contact',
  recipe: 'Recipe',
  walk_in_only: 'Walk-in only',
  sold_at_pos: 'Sold at the till'
};

const MONEY_FIELDS = ['price', 'debt_balance', 'amount', 'total_amount'];

function auditFieldLabel(key) {
  return AUDIT_FIELD_LABELS[key] || key.replace(/_/g, ' ').replace(/^./, c => c.toUpperCase());
}

function auditEntityLabel(type) {
  return AUDIT_ENTITY_LABELS[type] || type;
}

async function loadAuditLogs() {
  const list = document.getElementById('auditList');
  if (!list) return;

  const params = {};
  const entity = document.getElementById('auditEntity')?.value;
  const action = document.getElementById('auditAction')?.value;
  const from = document.getElementById('auditFrom')?.value;
  const to = document.getElementById('auditTo')?.value;
  if (entity) params.entity_type = entity;
  if (action) params.action = action;
  if (from) params.from = from + ' 00:00:00';
  if (to) params.to = to + ' 23:59:59';

  try {
    const result = await API.auditLogs(params);
    renderAuditLogs(result.logs || []);
  } catch (error) {
    list.innerHTML = '<p class="empty-state" role="alert">' + esc(error.message || 'Could not load the audit log.') + '</p>';
  }
}

function renderAuditLogs(rows) {
  const list = document.getElementById('auditList');
  if (!list) return;

  if (!rows.length) {
    list.innerHTML = '<p class="empty-state">No changes recorded for this filter. Changes you are allowed to see will appear here.</p>';
    return;
  }

  list.innerHTML = rows.map(row => auditRow(row)).join('');
}

function auditRow(row) {
  const changes = row.changes || {};
  const fields = Object.keys(changes).map(key => {
    const pair = changes[key] || {};
    return '<div class="audit-change"><span>' + esc(auditFieldLabel(key)) + '</span>' +
      '<b>' + auditValue(key, pair.from) + ' &rarr; ' + auditValue(key, pair.to) + '</b></div>';
  }).join('');

  const when = row.at ? new Date(row.at).toLocaleString() : '';

  return '<article class="audit-card">' +
    '<header class="audit-head">' +
      '<div><span class="audit-kind">' + esc(AUDIT_CONTEXT_LABELS[row.context] || row.context) + '</span>' +
      '<b class="audit-title">' + esc(actionWord(row.action)) + ' ' + esc(auditEntityLabel(row.entity_type)) +
      (row.entity_label ? ': ' + esc(row.entity_label) : '') + '</b></div>' +
      '<span class="audit-when">' + esc(when) + '</span>' +
    '</header>' +
    '<p class="audit-meta">' + esc(row.actor || 'Unknown') +
      (row.actor_role ? ' (' + esc(auditRoleLabel(row.actor_role)) + ')' : '') + '</p>' +
    (fields ? '<div class="audit-fields">' + fields + '</div>' : '<p class="audit-meta">No field values recorded.</p>') +
  '</article>';
}

function actionWord(action) {
  return { create: 'Created', update: 'Changed', delete: 'Removed' }[action] || action;
}

function auditRoleLabel(role) {
  return { super_admin: 'Owner', admin: 'Manager', cashier: 'Cashier', refiller: 'Refiller', rider: 'Rider' }[role] || role;
}

function auditValue(key, value) {
  if (value === null || value === undefined || value === '') return '<i>empty</i>';
  if (MONEY_FIELDS.includes(key) && Number.isFinite(Number(value))) return money(value);
  if (Array.isArray(value)) return esc(String(value.length) + ' entries');
  if (typeof value === 'object') return esc(JSON.stringify(value));
  return esc(value);
}
