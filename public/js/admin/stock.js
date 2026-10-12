let stockDetailId = null;
let stockHistoryPage = 1;
let stockDetailRequest = 0;
let stockSaving = false;

function closeStockDetail() {
  if (stockSaving) return;
  stockDetailRequest++;
  document.getElementById('stockDetailWrap')?.classList.add('hidden');
}

function toggleMovementForm(force) {
  const card = document.getElementById('movementFormCard');
  if (!card) return;
  const isHidden = card.classList.contains('hidden');
  const show = force !== undefined ? force : isHidden;
  card.classList.toggle('hidden', !show);
  const btn = document.getElementById('btnToggleMovement');
  if (btn) btn.textContent = show ? 'Hide form' : '+ Record movement';
  if (show) {
    const qty = document.getElementById('movementQty');
    if (qty) qty.focus();
  }
}

async function openStockDetail(id, type = 'history') {
  if (stockSaving) return;
  stockDetailRequest++;
  const item = findInventory(id);
  if (!item) return;
  stockDetailId = id;
  stockHistoryPage = 1;
  const showForm = type !== 'history';
  const initialType = type === 'history' ? 'restock' : type;

  const host = document.getElementById('stockDetailBody');
  host.innerHTML = '<div class="stock-detail-header">' +
    '<div><h4 class="stock-item-title">' + esc(item.item) + '</h4>' +
    '<p id="movementCurrentStock" class="cell-sub">Current stock: <b>' + Number(item.on).toLocaleString() + ' ' + esc(item.unit) + '</b></p></div>' +
    '<button type="button" id="btnToggleMovement" class="btn btn-primary btn-sm" onclick="toggleMovementForm()">' + (showForm ? 'Hide form' : '+ Record movement') + '</button>' +
    '</div>' +

    '<div id="movementFormCard" class="movement-form-card' + (showForm ? '' : ' hidden') + '">' +
    '<h5 class="panel-heading">Record stock movement</h5>' +
    '<form class="auth-form" onsubmit="return saveStockMovement(event)">' +
    '<label class="form-label" for="movementType">Movement type</label><select id="movementType" onchange="updateMovementFields()"><option value="restock">Restock</option><option value="adjustment">Count adjustment</option><option value="damage">Damage</option></select>' +
    '<label class="form-label" for="movementQty">Quantity change (negative to deduct)</label><input id="movementQty" type="number" required min="-1000000" max="1000000" step="1">' +
    '<div id="restockFields" class="auth-form"><label class="form-label" for="movementSupplier">Supplier</label><select id="movementSupplier">' +
    (DB.suppliers || []).map(s => '<option value="' + s.id + '">' + esc(s.name) + '</option>').join('') + '</select>' +
    '<label class="form-label" for="movementLot">Lot number (optional)</label><input id="movementLot" maxlength="100">' +
    '<label class="form-label" for="movementCost">Unit cost (optional)</label><input id="movementCost" type="number" min="0" max="99999999" step="0.0001"></div>' +
    '<div id="adjustmentFields" class="auth-form"><label class="form-label" for="movementReason">Reason</label><select id="movementReason"><option value="count_correction">Count correction</option><option value="damage">Damage</option><option value="spoilage">Spoilage</option><option value="shrinkage">Shrinkage</option></select></div>' +
    '<label class="form-label" for="movementNotes">Notes</label><textarea id="movementNotes" required maxlength="2000"></textarea>' +
    '<p id="movementError" role="alert" class="auth-error hidden"></p><button type="submit" class="btn btn-primary">Save movement</button></form>' +
    '</div>' +

    '<div class="stock-history-section">' +
    '<h4 class="panel-heading">Movement history</h4>' +
    '<div class="stock-history-filters">' +
    '<input id="movementSearch" type="search" aria-label="Search movements" maxlength="100" placeholder="Notes, reason, supplier, staff or lot" oninput="stockHistoryPage=1;renderStockHistory()">' +
    '<select id="movementFilterType" aria-label="Filter movement type" onchange="stockHistoryPage=1;renderStockHistory()"><option value="">All movement types</option><option value="sale">Sale</option><option value="restock">Restock</option><option value="adjustment">Adjustment</option><option value="damage">Damage</option></select>' +
    '<div class="date-filter-group"><label>From <input id="movementFrom" type="date" onchange="stockHistoryPage=1;renderStockHistory()"></label><label>To <input id="movementTo" type="date" onchange="stockHistoryPage=1;renderStockHistory()"></label></div>' +
    '<select id="movementOrder" aria-label="Sort movements" onchange="stockHistoryPage=1;renderStockHistory()"><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select>' +
    '</div>' +
    '<div class="data-table-wrapper"><table class="clean"><thead><tr><th>Date</th><th>Type</th><th class="num">Quantity</th><th>Operator</th><th>Supplier / lot / unit cost</th><th>Reason / notes</th></tr></thead><tbody id="movementBody"></tbody></table></div>' +
    '<div id="movementPages" class="stock-movement-pagination"></div>' +
    '</div>';

  document.getElementById('movementType').value = initialType;
  if (item.supplier_id) document.getElementById('movementSupplier').value = item.supplier_id;
  updateMovementFields();
  document.getElementById('stockDetailWrap').classList.remove('hidden');
  if (showForm) document.getElementById('movementQty')?.focus();
  await renderStockHistory();
}

function updateMovementFields() {
  const type = document.getElementById('movementType').value;
  document.getElementById('restockFields').classList.toggle('hidden', type !== 'restock');
  document.getElementById('adjustmentFields').classList.toggle('hidden', type === 'restock');
  document.getElementById('movementSupplier').required = type === 'restock';
  document.getElementById('movementQty').min = type === 'restock' ? 1 : -1000000;
  document.getElementById('movementQty').max = type === 'damage' ? -1 : 1000000;
  if (type === 'damage') document.getElementById('movementReason').value = 'damage';
}

async function saveStockMovement(event) {
  event.preventDefault();
  if (stockSaving) return false;
  stockSaving = true;
  const itemId = stockDetailId;
  const form = event.currentTarget;
  const button = form.querySelector('button[type="submit"]');
  button.disabled = true;
  const error = document.getElementById('movementError');
  error.classList.add('hidden');
  const type = document.getElementById('movementType').value;
  const payload = {type, qty: Number(document.getElementById('movementQty').value), notes: document.getElementById('movementNotes').value};
  if (type === 'restock') {
    payload.supplier_id = Number(document.getElementById('movementSupplier').value);
    payload.lot_number = document.getElementById('movementLot').value || null;
    payload.unit_cost = document.getElementById('movementCost').value || null;
  } else payload.reason = document.getElementById('movementReason').value;
  let result;
  try {
    result = await API.post('inventory/' + itemId + '/movements', payload);
  } catch (failure) {
    error.textContent = (failure.message || 'Unable to confirm movement.') + (!failure.status || failure.status >= 500 ? ' Check movement history before retrying; the update may already be saved.' : '');
    error.classList.remove('hidden');
    stockSaving = false; button.disabled = false;
    return false;
  }
  // Clear the submitted values immediately after the server confirms the write.
  document.getElementById('movementQty').value = '';
  document.getElementById('movementNotes').value = '';
  try {
    API.replaceInventory(result.item);
    document.getElementById('movementCurrentStock').textContent = 'Current stock: ' + result.item.on + ' ' + result.item.unit;
    saveDB(); renderInvTable();
    const advisories = await API.advisories();
    DB.advisories = advisories.advisories || [];
    renderInsights(); saveDB();
    error.textContent = 'Stock movement saved.';
  } catch (refreshFailure) {
    error.textContent = 'Stock movement saved. The screen could not fully refresh; reload the page. Do not enter this movement again.';
  } finally {
    error.classList.remove('hidden');
    stockSaving = false; button.disabled = false;
  }
  stockHistoryPage = 1;
  await renderStockHistory();
  return false;
}

async function renderStockHistory() {
  const request = ++stockDetailRequest;
  try {
    const response = await API.getWithQuery('inventory/' + stockDetailId + '/movements', {
      search: document.getElementById('movementSearch').value,
      order: document.getElementById('movementOrder').value, page: stockHistoryPage,
      type: document.getElementById('movementFilterType').value,
      from: document.getElementById('movementFrom').value, to: document.getElementById('movementTo').value,
    });
    if (request !== stockDetailRequest) return;
    document.getElementById('movementBody').innerHTML = response.data.map(row => {
      const d = row.created_at ? new Date(row.created_at) : null;
      const dateStr = d && !isNaN(d.getTime()) ? d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : esc(row.created_at);
      const timeStr = d && !isNaN(d.getTime()) ? d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true }) : '';
      const dateCell = timeStr ? ('<span>' + dateStr + '</span><span class="cell-sub">' + timeStr + '</span>') : esc(row.created_at);
      return '<tr><td>' + dateCell + '</td><td>' + esc(row.type) + '</td><td class="num">' + row.qty + '</td><td>' + esc(row.user) + '</td><td>' + esc(row.supplier || '-') + ' / ' + esc(row.lot_number || '-') + ' / ' + esc(row.unit_cost || '-') + '</td><td>' + esc((row.reason || '').replaceAll('_', ' ')) + '<span class="cell-sub">' + esc(row.notes || '') + '</span>' + '</td></tr>';
    }).join('') || '<tr><td colspan="6" class="empty-cell">No matching movements.</td></tr>';
    const meta = response.meta || {};
    const total = meta.total != null ? meta.total : (response.data ? response.data.length : 0);
    const perPage = meta.per_page || 20;
    const curPage = meta.current_page || 1;
    const lastPage = Math.max(1, meta.last_page || 1);
    const from = total > 0 ? (meta.from != null ? meta.from : ((curPage - 1) * perPage + 1)) : 0;
    const to = total > 0 ? (meta.to != null ? meta.to : Math.min(from + (response.data ? response.data.length : 0) - 1, total)) : 0;
    const countSummary = total > 0 ? (from + '–' + to + ' of ' + total + ' records') : '0 records';
    document.getElementById('movementPages').innerHTML = '<span>' + countSummary + '</span><div class="stock-pagination-controls"><button type="button" class="btn btn-secondary btn-sm" onclick="stockHistoryPage--;renderStockHistory()"' + (curPage <= 1 ? ' disabled' : '') + '>Previous</button><span>Page ' + curPage + ' of ' + lastPage + '</span><button type="button" class="btn btn-secondary btn-sm" onclick="stockHistoryPage++;renderStockHistory()"' + (curPage >= lastPage ? ' disabled' : '') + '>Next</button></div>';
  } catch (failure) {
    if (request === stockDetailRequest) {
      document.getElementById('movementBody').innerHTML = '<tr><td colspan="6" class="empty-cell">' + esc(failure.message) + ' <button type="button" class="btn btn-ghost" onclick="renderStockHistory()">Retry</button></td></tr>';
      document.getElementById('movementPages').innerHTML = '';
    }
  }
}
