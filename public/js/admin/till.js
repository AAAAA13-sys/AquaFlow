// Till & Prices - owner management of what the cashier terminal sells.
//
// This is the "Till & Prices" half of the Stock screen. A till item is a price
// plus a recipe that draws down one of the supply rows in the other half, which
// is why the two now share a page rather than sitting on separate tabs.
//
// The catalog used to be seed-only: nothing could change a price or add an item.
//
// Two things worth knowing while reading this. Prices are VAT inclusive - the
// checkout extracts the tax from the displayed amount rather than adding it - so
// the number entered here is exactly what the customer pays. And changing a price
// never rewrites history: transaction_items snapshots unit_price and line_total
// at the moment of sale, so past receipts keep their original figures.
//
// Products with sales transactions cannot be deleted to preserve audit trails,
// while unreferenced items can be removed permanently.

/** Switch the Stock screen between the Supplies and Till & Prices halves. */
function showStockView(view) {
  const showPrices = view === 'prices';
  const suppliesPanel = document.getElementById('stockViewSuppliesPanel');
  const pricesPanel = document.getElementById('stockViewPricesPanel');
  if (suppliesPanel) suppliesPanel.classList.toggle('hidden', showPrices);
  if (pricesPanel) pricesPanel.classList.toggle('hidden', !showPrices);
  const supplies = document.getElementById('stockViewSupplies');
  const prices = document.getElementById('stockViewPrices');
  if (supplies) supplies.className = 'seg-btn' + (showPrices ? '' : ' active');
  if (prices) prices.className = 'seg-btn' + (showPrices ? ' active' : '');

  // Hide the inactive view's form panel
  const invForm = document.getElementById('invFormPanel');
  const tillForm = document.getElementById('tillFormPanel');
  if (showPrices && invForm) invForm.classList.add('hidden');
  if (!showPrices && tillForm) tillForm.classList.add('hidden');

  // One add button serves both halves, so it has to point at the right form.
  const addButton = document.getElementById('stockAddButton');
  if (addButton) {
    const activePanel = showPrices ? tillForm : invForm;
    const isExpanded = activePanel && !activePanel.classList.contains('hidden');
    const defaultLabel = showPrices ? 'Add item for sale' : 'Add consumable';
    if (!addButton.dataset) addButton.dataset = {};
    addButton.dataset.defaultLabel = defaultLabel;
    addButton.setAttribute('aria-controls', showPrices ? 'tillFormPanel' : 'invFormPanel');
    addButton.setAttribute('aria-expanded', String(Boolean(isExpanded)));
    addButton.textContent = isExpanded ? 'Cancel' : defaultLabel;
    if (addButton.classList) {
      addButton.classList.toggle('btn-secondary', Boolean(isExpanded));
      addButton.classList.toggle('btn-primary', !isExpanded);
    }
  }
  // The till grid reads the live product list, so draw it the first time it shows.
  if (showPrices && typeof renderTill === 'function') renderTill();
}

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
        '<button type="button" class="btn btn-secondary btn-sm btn-del" onclick="deleteTillItem(\'' + id + '\')" title="Remove item from till">Remove</button>' +
      '</div>' +
    '</div>';
  }).join('') || '<p class="empty-cell">No till items match.</p>';
}

/** The stock rows a product can draw down. */
function fillTillStockOptions() {
  const select = document.getElementById('tillStock');
  if (select) {
    const current = select.value;
    select.innerHTML = '<option value="">Nothing extra (recipe only)</option>' +
      (DB.inventory || []).map(item =>
        '<option value="' + item.id + '">' + esc(item.item) + ' (' + esc(String(item.on)) + ' ' + esc(item.unit || '') + ')</option>'
      ).join('');
    if (current) select.value = current;
  }

  renderTillConsumablesCheckboxes();
}

/** Render checkboxes for consumables that can be included in a refill's recipe. */
function renderTillConsumablesCheckboxes() {
  const container = document.getElementById('tillConsumablesCheckboxes');
  if (!container) return;

  const kind = (document.getElementById('tillKind')?.value || 'S');
  const items = (DB.inventory || []).filter(item =>
    item.cat === 'Consumable' || /seal|cover|cap|wrap/i.test(item.item)
  );

  const list = items.length ? items : (DB.inventory || []);

  const previousChecked = new Set(
    Array.from(container.querySelectorAll('.till-recipe-cb:checked')).map(cb => String(cb.value))
  );
  const hadManualSelection = container.querySelectorAll('.till-recipe-cb').length > 0;

  container.innerHTML = list.map(item => {
    let isChecked = false;
    if (hadManualSelection) {
      isChecked = previousChecked.has(String(item.id));
    } else {
      const name = String(item.item || '').toLowerCase();
      if (name.includes('clear cover')) {
        isChecked = true;
      } else if (kind === 'S' && name.includes('slim')) {
        isChecked = true;
      } else if (kind === 'R' && name.includes('round')) {
        isChecked = true;
      }
    }

    return '<label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;line-height:1.5;color:var(--text,#1e293b);">' +
      '<input type="checkbox" class="till-recipe-cb" value="' + item.id + '"' + (isChecked ? ' checked' : '') + ' style="width:16px;height:16px;flex-shrink:0;accent-color:var(--primary,#0284c7);">' +
      '<span>' + esc(item.item) + '</span>' +
      '<span class="cell-sub" style="font-size:12px;color:var(--text-muted,#64748b);">(' + (item.on ?? 0) + ' ' + esc(item.unit || 'pcs') + ')</span>' +
    '</label>';
  }).join('') || '<p class="cell-sub">No consumable items found in stock.</p>';
}

function onTillKindChange() {
  const container = document.getElementById('tillConsumablesCheckboxes');
  if (!container) return;
  const kind = document.getElementById('tillKind')?.value || 'S';

  container.querySelectorAll('.till-recipe-cb').forEach(cb => {
    const parentLabel = cb.closest('label');
    const text = parentLabel ? parentLabel.textContent.toLowerCase() : '';
    if (text.includes('clear cover')) {
      cb.checked = true;
    } else if (kind === 'S') {
      cb.checked = text.includes('slim');
    } else if (kind === 'R') {
      cb.checked = text.includes('round');
    } else {
      cb.checked = false;
    }
  });
}

/**
 * Supplying sensible defaults per category. A refill is the customer's own jug;
 * a jug sold for delivery is counter merchandise; cleaning supplies are not.
 */
function onTillCategoryChange() {
  const category = (document.getElementById('tillCategory') || {}).value;
  const kind = document.getElementById('tillKind');
  const recipeGroup = document.getElementById('tillRecipeGroup');
  const stockGroup = document.getElementById('tillStockGroup');
  const unitsGroup = document.getElementById('tillUnitsGroup');
  const units = document.getElementById('tillUnits');

  const isRefill = category === 'refill';
  const isContainer = category === 'container';

  if (recipeGroup) {
    recipeGroup.style.display = isRefill ? '' : 'none';
    if (isRefill) renderTillConsumablesCheckboxes();
  }
  if (stockGroup) stockGroup.style.display = isRefill ? 'none' : '';
  if (unitsGroup) unitsGroup.style.display = isRefill ? 'none' : '';

  if (kind) {
    if (isRefill || isContainer) {
      kind.value = (kind.value === 'R') ? 'R' : 'S';
      kind.disabled = false;
    } else {
      kind.value = 'X';
      kind.disabled = true;
    }
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

async function deleteTillItem(id) {
  const item = (DB.products || []).find(p => String(p.id) === String(id));
  if (!item) return;
  if (!await appConfirm('Remove "' + item.name + '" from the till? This permanently deletes the item.')) return;
  try {
    await API.deleteProduct(id);
    API.removeProduct(id);
    saveDB();
    renderTill();
    appNotice('"' + item.name + '" has been removed from the till.');
  } catch (error) {
    appNotice(error.message || 'Could not remove the item.');
  }
}

async function createTillItem(event) {
  if (event) event.preventDefault();

  const value = id => { const el = document.getElementById(id); return el ? el.value.trim() : ''; };
  const category = value('tillCategory');
  const name = value('tillName');
  const price = Number(value('tillPrice'));
  const kind = value('tillKind') || (category === 'refill' ? 'S' : 'X');

  if (!name) { appNotice('Give the item a name.'); return false; }
  if (!Number.isFinite(price) || price <= 0) { appNotice('Enter a price greater than zero.'); return false; }

  const resetFormAndPanel = () => {
    const form = document.getElementById('tillForm');
    if (form) form.reset();
    onTillCategoryChange();
    const panel = document.getElementById('tillFormPanel');
    if (panel) panel.classList.add('hidden');
    const addButton = document.getElementById('stockAddButton');
    if (addButton && addButton.getAttribute('aria-controls') === 'tillFormPanel') {
      addButton.textContent = addButton.dataset?.defaultLabel || 'Add item for sale';
      addButton.setAttribute('aria-expanded', 'false');
      if (addButton.classList) {
        addButton.classList.remove('btn-secondary');
        addButton.classList.add('btn-primary');
      }
    }
  };

  const payload = {
    name: name,
    price: price,
    category: category,
    container_kind: kind,
    inventory_item_id: value('tillStock') ? Number(value('tillStock')) : null,
    inventory_units_per_sale: Number(value('tillUnits')) || 1
  };

  if (category === 'refill') {
    const checkedCheckboxes = document.querySelectorAll('#tillConsumablesCheckboxes .till-recipe-cb:checked');
    if (checkedCheckboxes.length === 0) {
      appNotice('Select at least one consumable in the recipe contents.');
      return false;
    }
    const consumables = [];
    checkedCheckboxes.forEach(cb => {
      consumables.push({
        inventory_item_id: Number(cb.value),
        qty_per_sale: 1
      });
    });
    payload.consumables = consumables;
    payload.inventory_item_id = null;
    payload.inventory_units_per_sale = 1;
  }

  try {
    const result = await API.createProduct(payload);
    applyTillProduct(result.product);
    resetFormAndPanel();
    appNotice('"' + result.product.name + '" is now for sale on the till.');
  } catch (error) {
    // The server refuses an item that would consume nothing, so the till can
    // never be given a button it cannot sell.
    appNotice(error.message || 'Could not add the item.');
  }

  return false;
}
