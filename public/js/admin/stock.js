let stockDetailId = null;
let stockHistoryPage = 1;
let stockDetailRequest = 0;

function closeStockDetail() {
  stockDetailRequest++;
  document.getElementById('stockDetailWrap')?.classList.add('hidden');
}

async function openStockDetail(id, type = 'restock') {
  const item = findInventory(id);
  if (!item) return;
  stockDetailId = id;
  stockHistoryPage = 1;
  const host = document.getElementById('stockDetailBody');
  host.innerHTML = '<h4>' + esc(item.item) + '</h4><p class="cell-sub">Current stock: ' + item.on + ' ' + esc(item.unit) + '</p>' +
    '<form class="auth-form" onsubmit="return saveStockMovement(event)">' +
    '<label class="form-label" for="movementType">Movement</label><select id="movementType" onchange="updateMovementFields()"><option value="restock">Restock</option><option value="adjustment">Count adjustment</option><option value="damage">Damage</option></select>' +
    '<label class="form-label" for="movementQty">Quantity change (negative to deduct)</label><input id="movementQty" type="number" required min="-1000000" max="1000000" step="1">' +
    '<div id="restockFields" class="auth-form"><label class="form-label" for="movementSupplier">Supplier</label><select id="movementSupplier">' +
    (DB.suppliers || []).map(s => '<option value="' + s.id + '">' + esc(s.name) + '</option>').join('') + '</select>' +
    '<label class="form-label" for="movementLot">Lot number (optional)</label><input id="movementLot" maxlength="100">' +
    '<label class="form-label" for="movementCost">Unit cost (optional)</label><input id="movementCost" type="number" min="0" max="99999999" step="0.0001"></div>' +
    '<div id="adjustmentFields" class="auth-form"><label class="form-label" for="movementReason">Reason</label><select id="movementReason"><option value="count_correction">Count correction</option><option value="damage">Damage</option><option value="spoilage">Spoilage</option><option value="shrinkage">Shrinkage</option></select></div>' +
    '<label class="form-label" for="movementNotes">Notes</label><textarea id="movementNotes" required maxlength="2000"></textarea>' +
    '<p id="movementError" role="alert" class="auth-error hidden"></p><button type="submit" class="btn btn-primary">Save movement</button></form>' +
    '<h4 class="panel-heading order-type-selector">Movement history</h4><div class="filter-toolbar"><input id="movementSearch" type="search" aria-label="Search movements" placeholder="Search movements" oninput="stockHistoryPage=1;renderStockHistory()">' +
    '<select id="movementOrder" aria-label="Sort movements" onchange="stockHistoryPage=1;renderStockHistory()"><option value="newest">New to Old</option><option value="oldest">Old to New</option></select></div>' +
    '<div class="data-table-wrapper"><table class="clean"><thead><tr><th>Date</th><th>Type</th><th>Qty</th><th>Operator</th><th>Supplier / lot / unit cost</th><th>Notes</th></tr></thead><tbody id="movementBody"></tbody></table></div><div id="movementPages" class="filter-toolbar"></div>';
  document.getElementById('movementType').value = type;
  if (item.supplier_id) document.getElementById('movementSupplier').value = item.supplier_id;
  updateMovementFields();
  document.getElementById('stockDetailWrap').classList.remove('hidden');
  document.getElementById('movementQty').focus();
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
  try {
    const result = await API.post('inventory/' + stockDetailId + '/movements', payload);
    API.replaceInventory(result.item);
    const advisories = await API.advisories();
    DB.advisories = advisories.advisories || [];
    renderInvTable(); renderInsights(); saveDB();
    await openStockDetail(stockDetailId, type);
  } catch (failure) {
    error.textContent = failure.message || 'Unable to save movement.';
    error.classList.remove('hidden');
  } finally { button.disabled = false; }
  return false;
}

async function renderStockHistory() {
  const request = ++stockDetailRequest;
  try {
    const response = await API.getWithQuery('inventory/' + stockDetailId + '/movements', {
      search: document.getElementById('movementSearch').value,
      order: document.getElementById('movementOrder').value, page: stockHistoryPage,
    });
    if (request !== stockDetailRequest) return;
    document.getElementById('movementBody').innerHTML = response.data.map(row => '<tr><td>' + esc(row.created_at) + '</td><td>' + esc(row.type) + '</td><td>' + row.qty + '</td><td>' + esc(row.user) + '</td><td>' + esc(row.supplier || '-') + ' / ' + esc(row.lot_number || '-') + ' / ' + esc(row.unit_cost || '-') + '</td><td>' + esc(row.notes || '') + '</td></tr>').join('') || '<tr><td colspan="6" class="empty-cell">No matching movements.</td></tr>';
    const meta = response.meta;
    document.getElementById('movementPages').innerHTML = '<button class="btn btn-ghost btn-sm" onclick="stockHistoryPage--;renderStockHistory()"' + (meta.current_page <= 1 ? ' disabled' : '') + '>Previous</button><span>Page ' + meta.current_page + ' of ' + meta.last_page + '</span><button class="btn btn-ghost btn-sm" onclick="stockHistoryPage++;renderStockHistory()"' + (meta.current_page >= meta.last_page ? ' disabled' : '') + '>Next</button>';
  } catch (failure) {
    if (request === stockDetailRequest) document.getElementById('movementBody').innerHTML = '<tr><td colspan="6" class="empty-cell">' + esc(failure.message) + '</td></tr>';
  }
}
