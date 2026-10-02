// Inventory Table Rendering
//
// Filter state for the dashboard's "Stock Check" card. Kept module-level so the
// active tab survives a re-render (e.g. after a lead-time edit).
let stockFilter = 'all';

const STOCK_FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'Consumable', label: 'Consumables' },
  { key: 'Filtration', label: 'Filtration' },
  { key: 'Asset', label: 'Assets' },
  { key: 'Cleaning', label: 'Cleaning' },
  { key: 'reorder', label: 'Needs Reorder' },
];

function stockFilterMatch(v, key) {
  if (key === 'all') return true;
  if (key === 'reorder') return statusOf(v)[0] !== 'OK';
  return v.cat === key;
}

function stockFiltered() {
  return DB.inventory.filter(v => stockFilterMatch(v, stockFilter));
}

function renderStockFilterTabs() {
  const host = document.getElementById('invFilterTabs');
  if (!host) return;
  host.innerHTML = STOCK_FILTERS.map(f => {
    const n = DB.inventory.filter(v => stockFilterMatch(v, f.key)).length;
    const active = stockFilter === f.key;
    return '<button type="button" class="filter-tab' + (active ? ' is-active' : '') + '" ' +
      'onclick="setStockFilter(\'' + f.key + '\')" aria-pressed="' + active + '">' +
      f.label + ' <span class="filter-tab-count">' + n + '</span></button>';
  }).join('');
}

function setStockFilter(key) {
  stockFilter = STOCK_FILTERS.some(f => f.key === key) ? key : 'all';
  renderStockFilterTabs();
  renderInvTable();
}

function renderInvTable() {
  const body = document.getElementById('invBody');
  if (body) {
    body.innerHTML = DB.inventory.map((v, i) => {
      const st = statusOf(v);
      return '<tr><td><b>' + v.item + '</b><span class="cell-sub">' + v.cat + ' | ' + v.supplier + '</span></td>' +
        '<td>' + v.cat + '</td><td class="num"><b>' + v.on.toLocaleString() + '</b><span class="num-unit">' + v.unit + '</span></td>' +
        '<td class="num">' + v.ss + '</td><td class="num" id="rop' + i + '">' + v.rop + '</td>' +
        '<td><input type="number" value="' + v.lead + '" min="1" max="14" class="table-inline-input" onchange="updLead(' + i + ',this.value)"></td>' +
        '<td><span class="pill ' + st[1] + '">' + st[0] + '</span></td>' +
        '<td><button onclick="stkAdj(' + i + ',1)" class="btn btn-secondary btn-sm">+ In</button> ' +
        '<button onclick="stkAdj(' + i + ',-1)" class="btn btn-secondary btn-sm">- Adj</button></td></tr>';
    }).join('');
  }

  const crit = DB.inventory.filter(v => statusOf(v)[0] !== 'OK');
  const health = document.getElementById('invHealth');
  if (health) health.textContent = crit.length + ' item(s) need reorder';

  renderStockFilterTabs();

  const dash = document.getElementById('invBodyDash');
  if (dash) {
    const rows = stockFiltered();
    if (!rows.length) {
      dash.innerHTML = '<tr><td colspan="5" class="empty-cell">Nothing matches this filter.</td></tr>';
    } else {
      dash.innerHTML = rows.map(v => {
        const st = statusOf(v);
        const days = v.days_left === null || v.days_left === undefined
          ? '&mdash;'
          : Number(v.days_left).toFixed(1) + 'd';
        return '<tr><td><b>' + v.item + '</b><span class="cell-sub">' + v.cat + ' | ' + v.supplier + '</span></td>' +
          '<td class="num"><b>' + v.on.toLocaleString() + '</b><span class="num-unit">' + v.unit + '</span></td>' +
          '<td class="num">' + v.rop + '</td>' +
          '<td class="num">' + days + '</td>' +
          '<td><span class="pill ' + st[1] + '">' + st[0] + '</span></td></tr>';
      }).join('');
    }
  }
}


// Lead Time Updates
async function updLead(i, val) {
  const v = DB.inventory[i];
  const lead = +val || 1;

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


// Suppliers Directory
function renderSup() {
  const grid = document.getElementById('supGrid');
  if (!grid) return;
  grid.innerHTML = DB.suppliers.map(s =>
    '<div class="card"><h3 class="panel-heading">' + s.name + '</h3>' +
    '<p class="cell-sub">' + s.item + '</p>' +
    '<div class="insight-row"><span>Lead time</span><b>' + s.lead + ' days</b></div>' +
    '<div class="insight-row"><span>Contact</span><b>' + s.contact + '</b></div>' +
    '<div class="insight-row"><span>Last delivery</span><b>' + s.last + '</b></div></div>'
  ).join('');
}
