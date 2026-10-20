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

  // Scale each balance against the largest one on screen, so the biggest
  // debtor is obvious at a glance rather than by reading down a column.
  const owedRows = rows.filter(c => Number(c.debt) > 0);
  const maxDebt = owedRows.reduce((n, c) => Math.max(n, Number(c.debt)), 0);

  body.innerHTML = rows.map(c => {
    const p = pending(c);
    const owing = Number(c.debt) > 0;
    return '<tr><td><b>' + esc(c.name) + '</b><span class="cell-sub">' + esc(c.addr) + ' | ' + esc(c.contact) + '</span></td>' +
      '<td class="num">' + c.tx + '</td>' +
      '<td class="num ' + (owing ? 'balance-amount' : 'balance-clear') + '">' +
        (owing
          ? '<span class="debt-cell">' + money(Number(c.debt)) + magBar(Number(c.debt), maxDebt, 'debt') + '</span>'
          : money(Number(c.debt))) +
      '</td><td>' + c.last + '</td>' +
      '<td class="text-right"><button onclick="openDrawer(' + c.id + ')" class="btn btn-ghost btn-sm">View account</button></td></tr>';
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
    '<div class="drawer-actions"><button onclick="payDebt(' + c.id + ')" class="btn btn-primary btn-sm">Record payment</button>' +

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
/**
 * Owner-side customer registration.
 *
 * The page invites the owner to "open a customer account", but until now the
 * only way to create one was to ring up a sale in the POS. This adds the same
 * registration the cashier flow uses, straight from the ledger screen.
 */
function toggleAddCustomerForm(button) {
  const existing = document.getElementById('customerCreatePanel');
  if (existing) {
    existing.remove();
    if (button) { button.setAttribute('aria-expanded', 'false'); button.textContent = 'Add customer'; }
    return;
  }

  const panel = document.createElement('div');
  panel.id = 'customerCreatePanel';
  panel.className = 'card form-card';
  panel.innerHTML =
    '<h3 class="panel-heading">Add customer</h3>' +
    '<div class="admin-entry-form">' +
      '<div class="admin-entry-field"><label class="form-label" for="newCustName">Customer name</label>' +
        '<input id="newCustName" class="form-input field-min-160" placeholder="Customer name *" required maxlength="150"></div>' +
      '<div class="admin-entry-field"><label class="form-label" for="newCustAddr">Address</label>' +
        '<input id="newCustAddr" class="form-input field-min-160" placeholder="Address" maxlength="255"></div>' +
      '<div class="admin-entry-field"><label class="form-label" for="newCustContact">Contact number</label>' +
        '<input id="newCustContact" type="tel" class="form-input field-min-140" placeholder="09XXXXXXXXX or +639XXXXXXXXX" required maxlength="13"></div>' +
    '</div>' +
    '<button type="button" class="btn btn-primary btn-sm" onclick="createCustomerFromLedger()">Save customer</button>' +
    '<span id="newCustError" role="alert"></span>';

  const summary = document.getElementById('customerSummary');
  (summary?.parentNode || document.body).insertBefore(panel, summary?.nextSibling || null);

  if (button) { button.setAttribute('aria-expanded', 'true'); button.textContent = 'Cancel'; }
  document.getElementById('newCustName')?.focus();
}

async function createCustomerFromLedger() {
  const name = (document.getElementById('newCustName')?.value || '').trim();
  const address = (document.getElementById('newCustAddr')?.value || '').trim();
  const contact = (document.getElementById('newCustContact')?.value || '').trim();
  const errorEl = document.getElementById('newCustError');

  if (!name) {
    if (errorEl) errorEl.textContent = 'Enter a customer name.';
    appNotice('Enter a customer name.');
    return;
  }

  if (!/^(09\d{9}|\+639\d{9})$/.test(contact)) {
    if (errorEl) errorEl.textContent = 'Enter a mobile number: 09XXXXXXXXX or +639XXXXXXXXX.';
    return;
  }

  try {
    const result = await API.createCustomer({ name, address, contact });
    API.replaceCustomer(result.customer);
    saveDB();
    renderCustTable(currentCustFilters().q, currentCustFilters().f);
    renderSidebarBadges();
    appNotice('Customer added: ' + result.customer.name);
    document.getElementById('customerCreatePanel')?.remove();
    const button = [...document.querySelectorAll('button')]
      .find(b => /^(Add customer|Cancel)$/.test(b.textContent.trim()));
    if (button) { button.setAttribute('aria-expanded', 'false'); button.textContent = 'Add customer'; }
  } catch (error) {
    if (errorEl) errorEl.textContent = error.message || 'Could not add the customer.';
    appNotice(error.message || 'Could not add the customer.');
  }
}

/**
 * Record a payment against a customer's outstanding balance.
 *
 * The ledger accepts a partial amount, so the operator is asked how much was
 * actually handed over rather than always settling the whole balance. The
 * biggest-value action on this page is confirmed before it is written, and the
 * resulting balance is reported back.
 */
async function payDebt(id) {
  if (payDebt.busy) return;
  payDebt.busy = true;
  try {
    const c = DB.customers.find(x => x.id === id);
    if (!c) return;
    if (c.debt <= 0) {
      appNotice('This customer has no outstanding balance.');
      return;
    }

    const owed = Number(c.debt);
    const entry = await appPrompt(
      c.name + ' owes ' + money(owed) + '.\n\nEnter the amount received. Leave the full balance to settle it in full.',
      String(owed.toFixed(2)),
      'Record payment'
    );
    if (entry === null) return;

    const cleaned = String(entry).replace(/[^0-9.]/g, '');
    const amount = Number(cleaned);
    if (!cleaned || !Number.isFinite(amount) || amount <= 0) {
      appNotice('Enter an amount greater than zero.');
      return;
    }
    // Cash a cashier has already recorded but a manager has not approved is not
    // collectible again, and neither is cash a rider is still carrying.
    const collectible = Number(c.collectible !== undefined ? c.collectible : owed);
    if (amount > collectible + 0.005) {
      appNotice(Number(c.settlement_pending) > 0
        ? 'Part of this balance is a collection waiting for approval. Approve it before collecting again.'
        : 'That is more than the ' + money(collectible) + ' collectible. Enter ' + money(collectible) + ' or less.');
      return;
    }

    const partial = amount < collectible - 0.005;
    const confirmed = await appConfirm(
      'Record ' + money(amount) + ' from ' + c.name + '?\n\n' +
      (partial
        ? money(collectible - amount) + ' will still be outstanding.'
        : 'This settles the collectible balance in full.'),
      partial ? 'Record partial payment' : 'Settle in full'
    );
    if (!confirmed) return;

    try {
      const result = await API.settleDebt(c.id, amount);
      API.replaceCustomer(result.customer);
      openDrawer(id);
      const filters = currentCustFilters();
      renderCustTable(filters.q, filters.f);
      saveDB();
      Submissions.clear();
      appNotice(money(amount) + ' recorded. ' + c.name + ' now owes ' + money(result.customer.debt) + '.');
    } catch (error) {
      appNotice(error.message || 'Could not record the payment.');
    }
  } finally { payDebt.busy = false; }
}

async function logReturn(id) {
  const c = DB.customers.find(x => x.id === id);
  if (!c) return;
  const p = pending(c);
  const kind = p.s > 0 ? 'slim' : (p.r > 0 ? 'round' : null);
  if (!kind) {
    appNotice('This customer has no pending bottles to return.');
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
    appNotice(error.message || 'Could not log the bottle return.');
  }
}
