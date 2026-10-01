// Customer Table Rendering
function renderCustTable(q, f) {
  const body = document.getElementById('custBody');
  if (!body) return;
  q = (q || '').toLowerCase();
  const rows = DB.customers.filter(c => c.name.toLowerCase().includes(q))
    .filter(c => !f || (f === 'bottles' ? (pending(c).s + pending(c).r) > 0 : c.debt > 0));
  body.innerHTML = rows.map(c => {
    const p = pending(c);
    return '<tr><td><b>' + c.name + '</b><span class="cell-sub">' + c.addr + ' | ' + c.contact + '</span></td>' +
      '<td class="num">' + c.tx + '</td><td>' + c.issuedS + 'S / ' + c.issuedR + 'R</td><td>' + c.returnedS + 'S / ' + c.returnedR + 'R</td>' +
      '<td><span class="pill ' + (p.s + p.r > 0 ? 'pill-debt' : 'pill-ok') + '">' + p.s + 'S / ' + p.r + 'R</span></td>' +
      '<td class="num">P' + c.debt.toLocaleString() + '</td><td>' + c.last + '</td>' +
      '<td><button onclick="openDrawer(' + c.id + ')" class="btn btn-ghost btn-sm">View</button></td></tr>';
  }).join('');
}


// Detail Drawer & Filtering
function openDrawer(id) {
  const c = DB.customers.find(x => x.id === id), p = pending(c);
  const body = document.getElementById('drawerBody');
  const drawer = document.getElementById('drawer');
  if (!body || !drawer) return;
  body.innerHTML =
    '<b>' + c.name + '</b><p class="cell-sub">' + c.addr + ' | ' + c.contact + '</p>' +
    '<div class="drawer-stat-grid"><div class="drawer-stat">Issued<b>' + c.issuedS + 'S / ' + c.issuedR + 'R</b></div>' +
    '<div class="drawer-stat">Returned<b>' + c.returnedS + 'S / ' + c.returnedR + 'R</b></div>' +
    '<div class="drawer-stat">Pending<b>' + p.s + 'S / ' + p.r + 'R</b></div>' +
    '<div class="drawer-stat">Cash debt<b>P' + c.debt.toLocaleString() + '</b></div></div>' +
    '<div class="drawer-actions"><button onclick="payDebt(' + c.id + ')" class="btn btn-primary btn-sm">Pay Debt</button>' +
    '<button onclick="logReturn(' + c.id + ')" class="btn btn-ghost btn-sm">Log 1 Bottle Returned</button>' +
    '<button onclick="alert(\'SMS reminder queued (demo).\')" class="btn btn-ghost btn-sm">Send SMS Reminder</button></div>';
  drawer.classList.remove('hidden');
}

function closeDrawer() {
  const drawer = document.getElementById('drawer');
  if (drawer) drawer.classList.add('hidden');
}

function currentCustFilters() {
  const q = document.getElementById('custQ');
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
