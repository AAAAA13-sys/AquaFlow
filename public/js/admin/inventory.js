// Shared HTML escaping for user-supplied values inserted via innerHTML.
function esc(value) {
  return String(value === undefined || value === null ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function supKey(s) {
  return (s && s.id !== undefined && s.id !== null) ? s.id : (s ? s.name : null);
}

function findSupplier(key) {
  return (DB.suppliers || []).find(s => String(supKey(s)) === String(key));
}

function findInventory(id) {
  return (DB.inventory || []).find(v => String(v.id) === String(id));
}

// Inventory Table Rendering
function renderInvTable() {
  const body = document.getElementById('invBody');
  if (body) {
    body.innerHTML = DB.inventory.map((v, i) => {
      const st = statusOf(v);
      return '<tr><td><b>' + esc(v.item) + '</b><span class="cell-sub">' + esc(v.cat) + ' | ' + esc(v.supplier) + '</span></td>' +
        '<td>' + esc(v.cat) + '</td><td class="num"><b>' + Number(v.on).toLocaleString() + '</b> ' + esc(v.unit) + '</td>' +
        '<td class="num">' + v.ss + '</td><td class="num" id="rop' + i + '">' + v.rop + '</td>' +
        '<td><input type="number" value="' + v.lead + '" min="1" max="14" class="table-inline-input" onchange="updLead(' + i + ',this.value)"></td>' +
        '<td><span class="pill ' + st[1] + '">' + st[0] + '</span></td>' +
        '<td style="white-space:nowrap;"><button onclick="stkAdj(' + i + ',1)" class="btn btn-ghost btn-sm">+ In</button> ' +
        '<button onclick="stkAdj(' + i + ',-1)" class="btn btn-ghost btn-sm">- Adj</button>' +
        (v.id ? ' <button onclick="openInventoryEditor(' + v.id + ')" class="btn btn-ghost btn-sm">Edit</button>' +
        ' <button onclick="deleteInventoryItem(' + v.id + ')" class="btn btn-ghost btn-sm">Del</button>' : '') + '</td></tr>';
    }).join('');
  }

  const crit = DB.inventory.filter(v => statusOf(v)[0] !== 'OK');
  const health = document.getElementById('invHealth');
  if (health) health.textContent = crit.length + ' item(s) need reorder';

  const dash = document.getElementById('invBodyDash');
  if (dash) {
    dash.innerHTML = DB.inventory.map(v => {
      const st = statusOf(v);
      return '<tr><td><b>' + esc(v.item) + '</b></td><td>' + esc(v.cat) + '</td>' +
        '<td class="num">' + Number(v.on).toLocaleString() + ' ' + esc(v.unit) + '</td><td class="num">' + v.rop + '</td>' +
        '<td>' + v.lead + 'd</td><td><span class="pill ' + st[1] + '">' + st[0] + '</span></td></tr>';
    }).join('');
  }
}


// Lead Time Updates
async function updLead(i, val) {
  const v = DB.inventory[i];
  const lead = Math.min(14, Math.max(1, +val || 1));

  if (v.id) {
    try {
      const result = await API.updateInventoryLead(v.id, lead);
      API.replaceInventory(result.item);
      renderInvTable(); renderInsights(); saveDB();
      return;
    } catch (error) {
      alert(error.message || 'Could not update the lead time.');
      renderInvTable();
      return;
    }
  }

  v.lead = lead;
  if (v.item.includes('Caps')) {
    v.rop = 150 + 225 * v.lead;
  } else if (v.item.includes('Seals')) {
    v.rop = 300 + 450 * v.lead;
  } else if (v.item.includes('Filter')) {
    v.rop = Math.round(v.ss + 1 * v.lead);
  } else {
    v.rop = Math.round(dailyUse(v.item) * v.lead + v.ss);
  }
  renderInvTable();
  renderInsights();
  saveDB();
}


// Stock Adjustments
async function stkAdj(i, d) {
  const v = DB.inventory[i];

  if (v.id) {
    try {
      const result = await API.adjustInventory(v.id, d > 0 ? 1 : -1);
      API.replaceInventory(result.item);
      renderInvTable(); renderInsights(); saveDB();
      return;
    } catch (error) {
      alert(error.message || 'Could not update the stock level.');
      return;
    }
  }

  v.on = Math.max(0, v.on + (d > 0 ? (v.unit === 'pcs' ? 500 : 5) : (v.unit === 'pcs' ? -50 : -1)));
  renderInvTable();
  renderInsights();
  saveDB();
}


// Consumable Insert + Full Edit + Delete (DOM manipulation over the table)
function fillInventorySupplierOptions() {
  const sel = document.getElementById('invSupplier');
  if (!sel) return;
  const current = sel.value;
  sel.innerHTML = '<option value="">No supplier</option>' + (DB.suppliers || []).map(s =>
    '<option value="' + esc(supKey(s)) + '">' + esc(s.name) + '</option>'
  ).join('');
  if (current) sel.value = current;
}

async function createInventoryItem(event) {
  if (event) event.preventDefault();
  const nameEl = document.getElementById('invName');
  const payload = {
    item_name: nameEl ? nameEl.value.trim() : '',
    category: document.getElementById('invCat') ? document.getElementById('invCat').value : 'Consumable',
    stock_on_hand: +(document.getElementById('invOn') ? document.getElementById('invOn').value : 0) || 0,
    unit: (document.getElementById('invUnit') ? document.getElementById('invUnit').value : 'pcs').trim() || 'pcs',
    lead_time_days: +(document.getElementById('invLead') ? document.getElementById('invLead').value : 2) || 2,
    supplier_id: (document.getElementById('invSupplier') && document.getElementById('invSupplier').value !== '')
      ? +document.getElementById('invSupplier').value : null
  };
  if (!payload.item_name) {
    alert('Item name is required.');
    return false;
  }
  try {
    const result = await API.createInventory(payload);
    API.replaceInventory(result.item);
    if (result.advisories) DB.advisories = result.advisories;
    if (nameEl) nameEl.value = '';
    renderInvTable();
    if (typeof renderInsights === 'function') renderInsights();
    saveDB();
  } catch (error) {
    alert(error.message || 'Could not add the item.');
  }
  return false;
}

function openInventoryEditor(id) {
  const v = findInventory(id);
  const wrap = document.getElementById('invEditWrap');
  const bodyEl = document.getElementById('invEditBody');
  if (!v || !wrap || !bodyEl) return;
  const supplierOptions = '<option value="">No supplier</option>' + (DB.suppliers || []).map(s =>
    '<option value="' + esc(supKey(s)) + '"' + (v.supplier === s.name ? ' selected' : '') + '>' + esc(s.name) + '</option>'
  ).join('');
  bodyEl.innerHTML =
    '<label class="cell-sub">Item name</label><input id="invEditName" class="form-input" value="' + esc(v.item) + '" maxlength="150">' +
    '<div style="height:.5rem"></div><label class="cell-sub">Category</label><select id="invEditCat" class="form-input">' +
    ['Consumable', 'Filtration', 'Cleaning', 'Asset'].map(c => '<option value="' + c + '"' + (v.cat === c ? ' selected' : '') + '>' + c + '</option>').join('') + '</select>' +
    '<div style="height:.5rem"></div><label class="cell-sub">On-hand</label><input id="invEditOn" type="number" min="0" class="form-input" value="' + v.on + '">' +
    '<div style="height:.5rem"></div><label class="cell-sub">Unit</label><input id="invEditUnit" class="form-input" value="' + esc(v.unit) + '" maxlength="20">' +
    '<div style="height:.5rem"></div><label class="cell-sub">Lead time (days)</label><input id="invEditLead" type="number" min="1" max="14" class="form-input" value="' + v.lead + '">' +
    '<div style="height:.5rem"></div><label class="cell-sub">Supplier</label><select id="invEditSupplier" class="form-input">' + supplierOptions + '</select>' +
    '<div class="drawer-actions" style="margin-top:.75rem;"><button onclick="saveInventoryEdit(' + v.id + ')" class="btn btn-primary btn-sm">Save</button>' +
    ' <button onclick="closeInventoryEditor()" class="btn btn-ghost btn-sm">Cancel</button></div>';
  wrap.classList.remove('hidden');
}

function closeInventoryEditor() {
  const wrap = document.getElementById('invEditWrap');
  if (wrap) wrap.classList.add('hidden');
}

async function saveInventoryEdit(id) {
  const sel = document.getElementById('invEditSupplier');
  const payload = {
    item_name: document.getElementById('invEditName').value.trim(),
    category: document.getElementById('invEditCat').value,
    stock_on_hand: +document.getElementById('invEditOn').value || 0,
    unit: document.getElementById('invEditUnit').value.trim() || 'pcs',
    lead_time_days: +document.getElementById('invEditLead').value || 1,
    supplier_id: (sel && sel.value !== '') ? +sel.value : null
  };
  try {
    const result = await API.updateInventory(id, payload);
    API.replaceInventory(result.item);
    if (result.advisories) DB.advisories = result.advisories;
    closeInventoryEditor();
    renderInvTable();
    if (typeof renderInsights === 'function') renderInsights();
    saveDB();
  } catch (error) {
    alert(error.message || 'Could not save the item.');
  }
}

async function deleteInventoryItem(id) {
  const v = findInventory(id);
  if (!v) return;
  if (!confirm('Delete "' + v.item + '"? Linked products will keep selling with no stock tracking.')) return;
  try {
    const result = await API.deleteInventory(id);
    API.removeInventory(id);
    if (result && result.advisories) DB.advisories = result.advisories;
    renderInvTable();
    if (typeof renderInsights === 'function') renderInsights();
    saveDB();
  } catch (error) {
    alert(error.message || 'Could not delete the item.');
  }
}


// Suppliers Directory (CRUD)
function renderSup() {
  const grid = document.getElementById('supGrid');
  if (!grid) return;
  grid.innerHTML = DB.suppliers.map(s => {
    const key = supKey(s);
    const keyAttr = (s.id !== undefined && s.id !== null) ? s.id : "'" + String(s.name).replace(/'/g, "\\'") + "'";
    return '<div class="card"><h3 class="panel-heading">' + esc(s.name) + '</h3>' +
      '<p class="cell-sub">' + esc(s.item) + '</p>' +
      '<div class="insight-row"><span>Lead time</span><b>' + s.lead + ' days</b></div>' +
      '<div class="insight-row"><span>Contact</span><b>' + esc(s.contact) + '</b></div>' +
      '<div class="insight-row"><span>Last delivery</span><b>' + esc(s.last) + '</b></div>' +
      '<div class="drawer-actions" style="margin-top:.5rem;"><button onclick="openSupplierEditor(' + keyAttr + ')" class="btn btn-ghost btn-sm">Edit</button>' +
      ' <button onclick="deleteSupplier(' + keyAttr + ')" class="btn btn-ghost btn-sm">Delete</button></div></div>';
  }).join('');
}

async function createSupplier(event) {
  if (event) event.preventDefault();
  const nameEl = document.getElementById('supName');
  const payload = {
    name: nameEl ? nameEl.value.trim() : '',
    supplied_items: document.getElementById('supItems') ? document.getElementById('supItems').value.trim() : '',
    lead_time_days: +(document.getElementById('supLead') ? document.getElementById('supLead').value : 2) || 2,
    contact: document.getElementById('supContact') ? document.getElementById('supContact').value.trim() : '',
    last_delivery: document.getElementById('supLast') && document.getElementById('supLast').value
      ? document.getElementById('supLast').value : null
  };
  if (!payload.name || !payload.supplied_items) {
    alert('Supplier name and supplied items are required.');
    return false;
  }
  try {
    const result = await API.createSupplier(payload);
    API.replaceSupplier(result.supplier);
    if (nameEl) nameEl.value = '';
    const itemsEl = document.getElementById('supItems');
    if (itemsEl) itemsEl.value = '';
    renderSup();
    fillInventorySupplierOptions();
    saveDB();
  } catch (error) {
    alert(error.message || 'Could not add the supplier.');
  }
  return false;
}

function openSupplierEditor(key) {
  const s = findSupplier(key);
  const wrap = document.getElementById('supEditWrap');
  const bodyEl = document.getElementById('supEditBody');
  if (!s || !wrap || !bodyEl) return;
  const idAttr = (s.id !== undefined && s.id !== null) ? s.id : "'" + String(s.name).replace(/'/g, "\\'") + "'";
  bodyEl.innerHTML =
    '<label class="cell-sub">Name</label><input id="supEditName" class="form-input" value="' + esc(s.name) + '" maxlength="120">' +
    '<div style="height:.5rem"></div><label class="cell-sub">Supplied items</label><input id="supEditItems" class="form-input" value="' + esc(s.item) + '">' +
    '<div style="height:.5rem"></div><label class="cell-sub">Lead time (days)</label><input id="supEditLead" type="number" min="1" max="14" class="form-input" value="' + s.lead + '">' +
    '<div style="height:.5rem"></div><label class="cell-sub">Contact</label><input id="supEditContact" class="form-input" value="' + esc(s.contact === '-' ? '' : s.contact) + '" maxlength="50">' +
    '<div style="height:.5rem"></div><label class="cell-sub">Last delivery</label><input id="supEditLast" type="date" class="form-input" value="' + (s.last && s.last !== '-' ? esc(s.last) : '') + '">' +
    '<div class="drawer-actions" style="margin-top:.75rem;"><button onclick="saveSupplierEdit(' + idAttr + ')" class="btn btn-primary btn-sm">Save</button>' +
    ' <button onclick="closeSupplierEditor()" class="btn btn-ghost btn-sm">Cancel</button></div>';
  wrap.classList.remove('hidden');
}

function closeSupplierEditor() {
  const wrap = document.getElementById('supEditWrap');
  if (wrap) wrap.classList.add('hidden');
}

async function saveSupplierEdit(key) {
  const s = findSupplier(key);
  if (!s) return;
  const lastVal = document.getElementById('supEditLast').value;
  const payload = {
    name: document.getElementById('supEditName').value.trim(),
    supplied_items: document.getElementById('supEditItems').value.trim(),
    lead_time_days: +document.getElementById('supEditLead').value || 1,
    contact: document.getElementById('supEditContact').value.trim(),
    last_delivery: lastVal ? lastVal : null
  };
  if (s.id === undefined || s.id === null) {
    Object.assign(s, { name: payload.name, item: payload.supplied_items, lead: payload.lead_time_days, contact: payload.contact || '-', last: payload.last_delivery || '-' });
    closeSupplierEditor();
    renderSup();
    saveDB();
    return;
  }
  try {
    const result = await API.updateSupplier(s.id, payload);
    API.replaceSupplier(result.supplier);
    closeSupplierEditor();
    renderSup();
    fillInventorySupplierOptions();
    saveDB();
  } catch (error) {
    alert(error.message || 'Could not save the supplier.');
  }
}

async function deleteSupplier(key) {
  const s = findSupplier(key);
  if (!s) return;
  if (!confirm('Delete supplier "' + s.name + '"? Linked items will show no supplier.')) return;
  if (s.id === undefined || s.id === null) {
    API.removeSupplier(s.name);
    renderSup();
    saveDB();
    return;
  }
  try {
    await API.deleteSupplier(s.id);
    API.removeSupplier(s.id);
    renderSup();
    fillInventorySupplierOptions();
    saveDB();
  } catch (error) {
    alert(error.message || 'Could not delete the supplier.');
  }
}
