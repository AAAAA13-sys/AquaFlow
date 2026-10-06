// Customer Table Rendering
function renderCustTable(q, f) {
  const body = document.getElementById('custBody');
  if (!body) return;
  const summary = document.getElementById('customerSummary');
  if (summary) {
    const owing = DB.customers.filter(c => Number(c.debt) > 0);
    summary.innerHTML = '<article><span>Outstanding balance</span><strong>' + money(owing.reduce((n, c) => n + Number(c.debt), 0)) + '</strong><small>Across all customer accounts</small></article><article><span>Customers with a balance</span><strong>' + owing.length + '</strong><small>Accounts awaiting payment</small></article><article><span>Customer accounts</span><strong>' + DB.customers.length + '</strong><small>Registered in your station</small></article>';
  }
  q = (q || '').toLowerCase();
  const rows = filterTableRows(DB.customers, 'custBody')
    .filter(c => !f || (f === 'bottles' ? (pending(c).s + pending(c).r) > 0 : c.debt > 0));
  body.innerHTML = rows.map(c => {
    const p = pending(c);
    return '<tr><td><b>' + esc(c.name) + '</b><span class="cell-sub">' + esc(c.addr) + ' | ' + esc(c.contact) + '</span></td>' +
      '<td class="num">' + c.tx + '</td>' +
      '<td class="num ' + (c.debt > 0 ? 'balance-amount' : 'balance-clear') + '">' + money(Number(c.debt)) + '</td><td>' + c.last + '</td>' +
      '<td><button onclick="openDrawer(' + c.id + ')" class="btn btn-ghost btn-sm">View account</button></td></tr>';
  }).join('');
  showTableEmpty(body, 5);
}


// Detail Drawer & Filtering
function openDrawer(id) {
  const c = DB.customers.find(x => x.id === id), p = pending(c);
  const body = document.getElementById('drawerBody');
  const drawer = document.getElementById('drawer');
  if (!body || !drawer) return;
  body.innerHTML =
    '<b>' + esc(c.name) + '</b><p class="cell-sub">' + esc(c.addr) + ' | ' + esc(c.contact) + '</p>' +
    '<div class="drawer-stat">Outstanding balance<b>' + money(Number(c.debt)) + '</b></div>' +
    '<div class="drawer-actions"><button onclick="payDebt(' + c.id + ')" class="btn btn-primary btn-sm">Record full payment</button>' +

    '</div>';
  drawer.classList.remove('hidden');
}

function closeDrawer() {
  const drawer = document.getElementById('drawer');
  if (drawer) drawer.classList.add('hidden');
}

function currentCustFilters() {
  const q = document.getElementById('custBodySearch');
  const f = document.getElementById('custF');
  return { q: q ? q.value : '', f: f ? f.value : '' };
}


// Settlement & Return Actions
async function payDebt(id) {
  const c = DB.customers.find(x => x.id === id);
  if (!c) return;
  if (c.debt <= 0) {
    alert('This customer has no outstanding balance.');
    return;
  }
  try {
    const result = await API.settleDebt(c.id, c.debt);
    API.replaceCustomer(result.customer);
    openDrawer(id);
    const filters = currentCustFilters();
    renderCustTable(filters.q, filters.f);
    saveDB();
  } catch (error) {
    alert(error.message || 'Could not record the payment.');
  }
}

async function logReturn(id) {
  const c = DB.customers.find(x => x.id === id);
  if (!c) return;
  const p = pending(c);
  const kind = p.s > 0 ? 'slim' : (p.r > 0 ? 'round' : null);
  if (!kind) {
    alert('This customer has no pending bottles to return.');
    return;
  }
  try {
    const result = await API.logReturn(c.id, kind);
    API.replaceCustomer(result.customer);
    openDrawer(id);
    const filters = currentCustFilters();
    renderCustTable(filters.q, filters.f);
    saveDB();
  } catch (error) {
    alert(error.message || 'Could not log the bottle return.');
  }
}
