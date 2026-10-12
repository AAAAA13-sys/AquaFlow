// Till & Prices - owner management of what the cashier terminal sells.
//
// The catalog used to be seed-only: nothing could change a price or add an item.
//
// Two things worth knowing while reading this. Prices are VAT inclusive - the
// checkout extracts the tax from the displayed amount rather than adding it - so
// the number entered here is exactly what the customer pays. And changing a price
// never rewrites history: transaction_items snapshots unit_price and line_total
// at the moment of sale, so past receipts keep their original figures.
//
// Products are never deleted. Transaction history references them, so an item the
// store stops selling is hidden instead (sold_at_pos = false).

const TILL_CATEGORY_LABELS = {
  refill: 'Refill',
  container: 'New jug',
  consumable: 'Supply',
  cleaning: 'Cleaning'
};

/** Server order - the same order the cashier's buttons appear in. */
function tillItems() {
  return (DB.products || [])
    .slice()
    .sort((a, b) => (Number(a.sort_order) - Number(b.sort_order)) || String(a.id).localeCompare(String(b.id)));
}

function renderTill() {
  const grid = document.getElementById('tillGrid');
  if (!grid) return;

  fillTillStockOptions();

  const items = tillItems();
  const onTill = items.filter(p => p.sold_at_pos !== false);

  const set = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value; };
  set('tillOnCount', onTill.length);
  set('tillOffCount', items.length - onTill.length);
  set('tillWalkInCount', onTill.filter(p => p.walk_in_only).length);

  const rows = filterTableRows(items, 'tillGrid');

  grid.innerHTML = rows.map(product => {
    const id = String(product.id).replace(/'/g, "\\'");
    const on = product.sold_at_pos !== false;
    const stock = (product.stock === null || product.stock === undefined) ? '—' : product.stock;
    const walkIn = product.walk_in_only;

    return '<div class="card till-card' + (on ? '' : ' till-card-off') + '">' +
      '<div class="flex-between">' +
        '<h3 class="panel-heading">' + esc(product.name) + '</h3>' +
        '<span class="order-receipt-tag">' + esc(TILL_CATEGORY_LABELS[product.cat] || product.cat) + '</span>' +
      '</div>' +
      '<p class="cell-sub">' + esc(product.id) + ' &middot; ' + (on ? 'On the till' : 'Hidden') +
        (walkIn ? ' &middot; Walk-in only' : '') + '</p>' +
      '<div class="insight-row"><span>Price (VAT inclusive)</span>' +
        '<input type="number" step="0.01" min="0.01" max="999999.99" class="form-input till-price" ' +
          'value="' + Number(product.price).toFixed(2) + '" ' +
          'aria-label="Price for ' + esc(product.name) + '" ' +
          'onchange="saveTillPrice(\'' + id + '\', this)"></div>' +
      '<div class="insight-row"><span>Stock on hand</span><b>' + esc(String(stock)) + '</b></div>' +
      '<div class="drawer-actions">' +
        '<button type="button" class="btn btn-ghost btn-sm" onclick="toggleTill(\'' + id + '\', ' + (on ? 'false' : 'true') + ')">' +
          (on ? 'Hide from till' : 'Show on till') + '</button>' +
        '<button type="button" class="btn btn-ghost btn-sm" onclick="toggleTillWalkIn(\'' + id + '\', ' + (walkIn ? 'false' : 'true') + ')">' +
          (walkIn ? 'Allow delivery' : 'Walk-in only') + '</button>' +
      '</div>' +
    '</div>';
  }).join('') || '<p class="empty-cell">No till items match.</p>';
}

/** The stock rows a product can draw down. */
function fillTillStockOptions() {
  const select = document.getElementById('tillStock');
  if (!select) return;

  const current = select.value;
  select.innerHTML = '<option value="">Nothing extra (recipe only)</option>' +
    (DB.inventory || []).map(item =>
      '<option value="' + item.id + '">' + esc(item.item) + ' (' + esc(String(item.on)) + ' ' + esc(item.unit || '') + ')</option>'
    ).join('');

  if (current) select.value = current;
}

/**
 * Supplying sensible defaults per category. A refill is the customer's own jug;
 * a jug sold for delivery is counter merchandise; cleaning supplies are not.
 */
function onTillCategoryChange() {
  const category = (document.getElementById('tillCategory') || {}).value;
  const kind = document.getElementById('tillKind');
  const units = document.getElementById('tillUnits');

  if (kind) {
    kind.value = category === 'refill' || category === 'container' ? (kind.value === 'R' ? 'R' : 'S') : 'X';
    kind.disabled = !(category === 'refill' || category === 'container');
  }
  if (units) units.disabled = !document.getElementById('tillStock')?.value;
}

function applyTillProduct(product) {
  API.replaceProduct(product);
  saveDB();
  renderTill();
}

async function saveTillPrice(id, input) {
  const price = Number(input && input.value);
  const previous = (DB.products.find(p => p.id === id) || {}).price;

  if (!Number.isFinite(price) || price <= 0) {
    appNotice('Enter a price greater than zero.');
    if (input) input.value = Number(previous || 0).toFixed(2);
    return;
  }

  try {
    const result = await API.updateProduct(id, { price: price });
    applyTillProduct(result.product);
    appNotice('Price updated. Past receipts are unchanged.');
  } catch (error) {
    appNotice(error.message || 'Could not save the price.');
    if (input) input.value = Number(previous || 0).toFixed(2);
  }
}

async function toggleTill(id, soldAtPos) {
  try {
    const result = await API.updateProduct(id, { sold_at_pos: soldAtPos });
    applyTillProduct(result.product);
    appNotice(soldAtPos ? 'Added to the cashier terminal.' : 'Hidden from the cashier terminal. Records were kept.');
  } catch (error) {
    appNotice(error.message || 'Could not update the till.');
  }
}

async function toggleTillWalkIn(id, walkInOnly) {
  try {
    const result = await API.updateProduct(id, { walk_in_only: walkInOnly });
    applyTillProduct(result.product);
    appNotice(walkInOnly ? 'This item can no longer be delivered.' : 'This item can now be delivered.');
  } catch (error) {
    appNotice(error.message || 'Could not update the item.');
  }
}

async function createTillItem(event) {
  if (event) event.preventDefault();

  const value = id => { const el = document.getElementById(id); return el ? el.value.trim() : ''; };
  const payload = {
    name: value('tillName'),
    price: Number(value('tillPrice')),
    category: value('tillCategory'),
    container_kind: value('tillKind') || 'X',
    inventory_item_id: value('tillStock') ? Number(value('tillStock')) : null,
    inventory_units_per_sale: Number(value('tillUnits')) || 1
  };

  if (!payload.name) { appNotice('Give the item a name.'); return false; }
  if (!Number.isFinite(payload.price) || payload.price <= 0) { appNotice('Enter a price greater than zero.'); return false; }

  try {
    const result = await API.createProduct(payload);
    applyTillProduct(result.product);
    const form = document.getElementById('tillForm');
    if (form) form.reset();
    onTillCategoryChange();
    appNotice('"' + result.product.name + '" is now for sale on the till.');
  } catch (error) {
    // The server refuses an item that would consume nothing, so the till can
    // never be given a button it cannot sell.
    appNotice(error.message || 'Could not add the item.');
  }

  return false;
}
