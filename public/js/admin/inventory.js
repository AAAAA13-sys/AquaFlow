// Inventory Table Rendering
function renderInvTable() {
  const body = document.getElementById('invBody');
  if (body) {
    body.innerHTML = DB.inventory.map((v, i) => {
      const st = statusOf(v);
      return '<tr><td><b>' + v.item + '</b><span class="cell-sub">' + v.cat + ' | ' + v.supplier + '</span></td>' +
        '<td>' + v.cat + '</td><td class="num"><b>' + v.on.toLocaleString() + '</b> ' + v.unit + '</td>' +
        '<td class="num">' + v.ss + '</td><td class="num" id="rop' + i + '">' + v.rop + '</td>' +
        '<td><input type="number" value="' + v.lead + '" min="1" max="14" class="table-inline-input" onchange="updLead(' + i + ',this.value)"></td>' +
        '<td><span class="pill ' + st[1] + '">' + st[0] + '</span></td>' +
        '<td><button onclick="stkAdj(' + i + ',1)" class="btn btn-ghost btn-sm">+ In</button> ' +
        '<button onclick="stkAdj(' + i + ',-1)" class="btn btn-ghost btn-sm">- Adj</button></td></tr>';
    }).join('');
  }

  const crit = DB.inventory.filter(v => statusOf(v)[0] !== 'OK');
  const health = document.getElementById('invHealth');
  if (health) health.textContent = crit.length + ' item(s) need reorder';

  const dash = document.getElementById('invBodyDash');
  if (dash) {
    dash.innerHTML = DB.inventory.map(v => {
      const st = statusOf(v);
      return '<tr><td><b>' + v.item + '</b></td><td>' + v.cat + '</td>' +
        '<td class="num">' + v.on.toLocaleString() + ' ' + v.unit + '</td><td class="num">' + v.rop + '</td>' +
        '<td>' + v.lead + 'd</td><td><span class="pill ' + st[1] + '">' + st[0] + '</span></td></tr>';
    }).join('');
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
