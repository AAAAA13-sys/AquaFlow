let employeeBusy = false;
let employeeFlight = null;
let employeeFresh = false;
let employeeRefreshFailed = false;

function getEmployeeRole() {
  const container = document.querySelector('.emp-container');
  return container?.dataset.role || getSession()?.role || '';
}

// The role this account is acting as on the staff workspace. A rider or
// refiller is always themselves; a manager or owner working the staff tools
// from the owner portal sends the role they are acting under.
function getEmployeeMode() {
  const container = document.querySelector('.emp-container');
  return container?.dataset.mode || '';
}

function getOrderBadge(status) {
  const map = {
    preparing: { text: 'Preparing', cls: 'emp-badge-preparing' },
    ready: { text: 'Ready', cls: 'emp-badge-ready' },
    out_for_delivery: { text: 'Out for Delivery', cls: 'emp-badge-out_for_delivery' },
    delivered: { text: 'Delivered', cls: 'emp-badge-delivered' }
  };
  return map[status] || {
    text: (status || 'Pending').charAt(0).toUpperCase() + (status || '').slice(1).replaceAll('_', ' '),
    cls: 'emp-badge-neutral'
  };
}

function employeeStorageKey() {
  return 'aquaflow_job:' + document.querySelector('.emp-container').dataset.user;
}

function pendingEmployeeAction() {
  const saved = sessionStorage.getItem(employeeStorageKey());
  if (!saved) return null;
  let pending;
  try { pending = JSON.parse(saved); } catch { throw new Error('Saved action cannot be read. Keep this tab open and ask the owner to check the order before clearing browser storage.'); }
  if (!pending?.id || !pending.body?.submission_key || !['ready', 'dispatch', 'deliver', 'issue'].includes(pending.body.action)) {
    throw new Error('Saved action is invalid. Ask the owner to check the order before clearing browser storage.');
  }
  return pending;
}

function showEmployeeError(message) {
  const error = document.getElementById('error');
  error.textContent = message || '';
  error.classList.toggle('hidden', !message);
}

async function refreshEmployee() {
  const profile = document.getElementById('profileModal');
  if (profile && !profile.classList.contains('hidden')) return;
  if (getSession()?.must_change_password) { await openProfileModal(); return; }
  if (employeeBusy) return;
  if (employeeFlight) return employeeFlight;
  employeeFlight = (async () => {
    try {
      const mode = getEmployeeMode();
      const data = await API.request('employee/orders' + (mode ? '?mode=' + encodeURIComponent(mode) : ''));
      if (!Array.isArray(data?.orders)) throw new Error('Order list was incomplete. Try Refresh again.');
      employeeJobs = data.orders;
      employeeFresh = true;
      if (employeeRefreshFailed) showEmployeeError(null);
      employeeRefreshFailed = false;
      document.getElementById('pulseDot')?.classList.remove('offline');
      document.getElementById('connection').textContent = 'Updated ' + new Date(data.updated_at || Date.now()).toLocaleTimeString();
    } catch (error) {
      if (error.data?.code === 'password_change_required') { await openProfileModal(); return; }
      employeeFresh = false;
      employeeRefreshFailed = true;
      document.getElementById('pulseDot')?.classList.add('offline');
      document.getElementById('connection').textContent = 'Orders unavailable';
      showEmployeeError((error.message || 'Cannot load orders.') + ' Tap Refresh to retry. Displayed orders cannot be changed until refreshed.');
    } finally {
      renderEmployeeJobs();
    }
  })();
  try { return await employeeFlight; } finally { employeeFlight = null; }
}

let employeeJobs = [];
let employeeRenderedKey = null;
function employeeActionProblem(job, action) {
  if (!employeeFresh) return 'Refresh orders successfully before changing work.';
  const current = employeeJobs.find(row => row.id === job.id);
  if (!current || current.version !== job.version || current.status !== job.status) return 'Order changed. Refresh and use the current order card.';
  const role = getEmployeeRole();
  if (role === 'refiller' && action === 'ready' && job.status === 'preparing') return null;
  if (role !== 'rider' || job.order_type !== 'Delivery' || (job.rider_id && Number(job.rider_id) !== Number(document.querySelector('.emp-container').dataset.userId))) {
    return 'This delivery is assigned to another rider account.';
  }
  if (action === 'dispatch' && job.status === 'ready') {
    if (!job.handed_over_at) return 'Wait for the cashier to confirm the prepared quantity and hand over this order.';
    const older = employeeJobs.some(row => row.id !== job.id && ['preparing', 'ready'].includes(row.status) &&
      (row.created_at < job.created_at || (row.created_at === job.created_at && row.id < job.id)));
    return older ? 'Start the oldest assigned order first. Wait for its preparation if needed.' : null;
  }
  if (action === 'deliver' && job.status === 'out_for_delivery') return null;
  if (action === 'issue' && ['ready', 'out_for_delivery'].includes(job.status)) return null;
  return 'This action is not available for the current order. Refresh the delivery list and try again.';
}

function renderEmployeeJobs() {
  let pending = null, storageBlocked = false;
  try { pending = pendingEmployeeAction(); } catch (error) { storageBlocked = true; showEmployeeError(error.message); }
  const recovery = document.getElementById('employeeRecovery');
  if (recovery) recovery.hidden = !pending;
  const retry = document.getElementById('retryEmployeeAction');
  if (retry) retry.disabled = employeeBusy;
  const recoveryText = document.getElementById('employeeRecoveryText');
  if (recoveryText && pending) recoveryText.textContent = 'Confirm the saved ' + pending.body.action + ' action for ' + (pending.receipt || 'order ' + pending.id) + ' before changing another order. Retry uses the original request.';
  const blocked = employeeBusy || !employeeFresh || !!pending || storageBlocked;
  const countPill = document.getElementById('orderCount');
  if (countPill) countPill.textContent = employeeJobs.length;

  const container = document.getElementById('jobs');
  if (!container) return;
  const role = getEmployeeRole();
  const renderKey = JSON.stringify([employeeJobs, blocked, employeeFresh, role]);
  if (renderKey === employeeRenderedKey) return;
  container.replaceChildren();
  const queueTitleEl = document.getElementById('queueSectionTitle');
  if (queueTitleEl) queueTitleEl.textContent = role === 'rider' ? 'Ready to deliver' : (role === 'refiller' ? 'To prepare' : 'My Orders');

  for (const job of employeeJobs) {
    const card = document.createElement('article');
    card.className = 'emp-order-card emp-card-' + (job.status || 'neutral');

    // Top Header
    const header = document.createElement('div');
    header.className = 'emp-order-header';

    const textGroup = document.createElement('div');
    const receipt = document.createElement('div');
    receipt.className = 'emp-order-receipt';
    receipt.textContent = job.receipt;

    const customer = document.createElement('div');
    customer.className = 'emp-order-customer';
    customer.textContent = job.customer;
    textGroup.append(receipt, customer);

    const rightGroup = document.createElement('div');
    rightGroup.className = 'emp-order-header-actions';

    const badgeData = getOrderBadge(job.status);
    const badge = document.createElement('span');
    badge.className = 'emp-badge ' + badgeData.cls;
    badge.textContent = badgeData.text;
    rightGroup.append(badge);

    // Secondary action: rendered under the primary button so it is a full
    // touch target and is never mistaken for a status badge.
    let issueBtn = null;
    if (role === 'rider' && ['ready', 'out_for_delivery'].includes(job.status)) {
      issueBtn = document.createElement('button');
      issueBtn.type = 'button';
      issueBtn.className = 'emp-issue-top-btn';
      issueBtn.textContent = 'Report Issue';
      issueBtn.disabled = blocked || !!employeeActionProblem(job, 'issue');
      issueBtn.onclick = () => openRiderIssueDialog(job);
    }

    header.append(textGroup, rightGroup);

    // Items: one row per product so quantity reads first at arm's length.
    const lines = document.createElement('div');
    lines.className = 'emp-order-lines';
    // Older queue rows only carry a "2 × Slim, 1 × Round" description; parse it
    // into the same rows when every part has a quantity, otherwise show it as-is.
    const parsedLines = String(job.description || '').split(/,\s*(?=\d+\s*[×x]\s)/)
      .map(part => part.trim().match(/^(\d+)\s*[×x]\s*(.+)$/))
      .map(match => match && { quantity: match[1], product: match[2] });
    const itemLines = Array.isArray(job.lines) && job.lines.length
      ? job.lines
      : (parsedLines.length && parsedLines.every(Boolean) ? parsedLines : null);
    if (itemLines) {
      for (const item of itemLines) {
        const row = document.createElement('div');
        row.className = 'emp-line';
        const qty = document.createElement('span');
        qty.className = 'emp-line-qty';
        qty.textContent = item.quantity + ' ×';
        const product = document.createElement('span');
        product.className = 'emp-line-product';
        product.textContent = item.product;
        row.append(qty, product);
        lines.append(row);
      }
    } else {
      lines.textContent = job.description || 'Water Refill';
    }

    // Details meta (contact details removed per requirements)
    const meta = document.createElement('div');
    meta.className = 'emp-order-meta';

    if (job.address) {
      const row = document.createElement('div');
      row.className = 'emp-meta-row';
      row.innerHTML = `<a class="emp-map-btn" href="https://maps.google.com/?q=${encodeURIComponent(job.address)}" target="_blank" rel="noopener" aria-label="${esc(job.address)}, open in Maps (new tab)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg><span class="emp-map-text">${esc(job.address)}</span><span class="emp-map-hint">Open in Maps</span></a>`;
      meta.append(row);
    }

    if (job.issues && job.issues.length) {
      const row = document.createElement('div');
      row.className = 'emp-meta-row';
      row.style.color = '#dc2626';
      row.style.fontWeight = '500';
      row.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> <span>Issue: ${esc(job.issues[job.issues.length - 1])}</span>`;
      meta.append(row);
    }

    if (job.proof) {
      const cash = document.createElement('p');
      cash.className = 'emp-order-meta';
      cash.textContent = 'Reported cash: ' + Number(job.proof.reported_cash).toFixed(2) + '. Still to remit: ' + Number(job.proof.pending_remittance).toFixed(2) + '. Buyer debt is reduced only by accepted remittances.';
      meta.append(cash);
      const proof = document.createElement('a'); proof.href = job.proof.photo_url; proof.target = '_blank'; proof.rel = 'noopener'; proof.textContent = 'View submitted delivery photo'; meta.append(proof);
    }
    card.append(header, lines, meta);

    // Actions depending on role & status
    let action = null;
    let buttonLabel = null;

    if (role === 'refiller' && job.status === 'preparing') {
      action = 'ready';
      buttonLabel = 'Mark as Ready';
    } else if (role === 'rider') {
      if (job.status === 'preparing') {
        const note = document.createElement('p');
        note.className = 'emp-order-meta';
        note.textContent = 'Wait for the refiller to mark this order ready. New deliveries are assigned automatically.';
        card.append(note);
      } else if (job.status === 'ready') {
        action = 'dispatch';
        buttonLabel = 'Start Delivery';
      } else if (job.status === 'out_for_delivery') {
        action = 'deliver';
        buttonLabel = 'Confirm Delivered';
      }
    }

    if (action && buttonLabel) {
      const actionRow = document.createElement('div');
      actionRow.className = 'emp-order-actions-row';

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'emp-action-btn';
      const problem = employeeActionProblem(job, action);
      button.disabled = blocked || !!problem;
      if (problem && employeeFresh) {
        const note = document.createElement('p'); note.className = 'emp-order-meta'; note.textContent = problem; card.append(note);
      }
      button.textContent = buttonLabel;
      button.onclick = () => changeJob(job, action);
      actionRow.append(button);
      if (issueBtn) actionRow.append(issueBtn);
      card.append(actionRow);
    } else if (issueBtn) {
      const actionRow = document.createElement('div');
      actionRow.className = 'emp-order-actions-row';
      actionRow.append(issueBtn);
      card.append(actionRow);
    }

    container.append(card);
  }

  if (!employeeJobs.length) {
    const empty = document.createElement('div');
    empty.className = 'emp-empty-state';
    const emptyTitle = document.createElement('strong');
    emptyTitle.className = 'emp-empty-title';
    const emptyBody = document.createElement('span');
    emptyBody.className = 'emp-empty-body';
    if (!employeeFresh) {
      empty.classList.add('emp-empty-state--error');
      emptyTitle.textContent = 'Orders could not be loaded';
      emptyBody.textContent = 'Tap Refresh to try again.';
    } else if (role === 'rider') {
      emptyTitle.textContent = 'No deliveries yet';
      emptyBody.textContent = 'New deliveries appear here automatically when they are ready.';
    } else {
      emptyTitle.textContent = 'No orders to prepare';
      emptyBody.textContent = 'New orders appear here automatically.';
    }
    empty.append(emptyTitle, emptyBody);
    container.append(empty);
  }
  employeeRenderedKey = renderKey;
}

async function changeJob(job, action, reason = null, recovering = false, delivery = null) {
  if (employeeBusy) return;
  employeeBusy = true;
  let refresh = false;
  let evidenceKey = null;
  try {
    let pending = pendingEmployeeAction();
    if (pending?.body.action === 'deliver') evidenceKey = pending.body.submission_key;
    if (pending && !recovering) {
      showEmployeeError('A saved action needs confirmation. Use Retry saved action before changing another order.');
      return;
    }
    if (recovering && !pending) return;
    if (!pending) {
      const problem = employeeActionProblem(job, action);
      if (problem) { showEmployeeError(problem); return; }
      if (action === 'deliver' && !delivery) { openDeliveryEvidence(job); return; }
      if (action === 'issue') {
        reason = String(reason || '').trim();
        if (reason.length < 5 || reason.length > 500) { showEmployeeError('Describe the issue using 5 to 500 characters.'); return; }
      }
      const labels = {ready:'Mark prepared order ready', dispatch:'Start delivery', deliver:'Confirm customer received order', issue:'Report delivery issue'};
      const details = (job.lines || []).map(line => line.quantity + ' x ' + line.product).join(', ') || job.description || '';
      if (!await appConfirm(labels[action] + ' for ' + job.receipt + ' (' + job.customer + ')? ' + details, labels[action])) return;
      const photo = delivery?.photo;
      const detailsBody = delivery ? {...delivery} : {};
      delete detailsBody.photo;
      const mode = getEmployeeMode();
      pending = {id:job.id, receipt:job.receipt, body:{action, reason, ...detailsBody, version:job.version, submission_key:newSubmissionKey(), ...(mode ? {mode} : {})}};
      if (photo) { evidenceKey = pending.body.submission_key; await deliveryPhoto(evidenceKey, 'put', photo); }
      sessionStorage.setItem(employeeStorageKey(), JSON.stringify(pending));
    }
    renderEmployeeJobs();
    const result = await API.request('employee/orders/' + pending.id, {method:'POST', body:await employeeRequestBody(pending), timeout:pending.body.action === 'deliver' ? 60000 : undefined});
    if (!result?.order) throw new Error('Order update was not confirmed. Retry the saved action before changing other work.');
    sessionStorage.removeItem(employeeStorageKey());
    if (pending.body.action === 'deliver') deliveryPhoto(pending.body.submission_key, 'delete').catch(() => {});
    showEmployeeError(null);
    const notice = document.getElementById('employeeResult');
    if (notice) notice.textContent = (pending.receipt || 'Order') + ': ' + pending.body.action + ' confirmed.';
    refresh = true;
  } catch (error) {
    if ([400, 401, 403, 404, 409, 419, 422].includes(error.status)) {
      sessionStorage.removeItem(employeeStorageKey());
      if (evidenceKey) deliveryPhoto(evidenceKey, 'delete').catch(() => {});
      refresh = true;
    }
    showEmployeeError(error.message || 'Unable to save the action. Retry the saved action when connected.');
  } finally {
    employeeBusy = false;
    if (refresh) await refreshEmployee();
    renderEmployeeJobs();
  }
}

function retryEmployeeAction() {
  return changeJob(null, null, null, true);
}

// Initial load & periodic refresh.
// The history tab shares this file (for the shared dialogs and logout), so the
// queue only loads on the page that actually has a queue.
if (document.getElementById('jobs')) {
  if (typeof startPortalClock === 'function') startPortalClock('employeeClock');
  refreshEmployee();
  window.addEventListener('focus', refreshEmployee);
  window.addEventListener('online', refreshEmployee);
  setInterval(() => {
    if (!document.hidden && !employeeBusy && !document.querySelector('dialog[open]') && !document.activeElement?.closest('#jobs')) refreshEmployee();
  }, 5000);
}

function openRiderIssueDialog(job) {
  let modal = document.getElementById('empIssueModal');
  if (!modal) {
    modal = document.createElement('dialog');
    modal.id = 'empIssueModal';
    modal.className = 'employee-issue-dialog';
    modal.classList.add('emp-issue-modal');
    modal.setAttribute('aria-labelledby', 'empIssueTitle');
    modal.innerHTML = `
      <div class="emp-dialog-body">
        <h3 id="empIssueTitle" class="emp-dialog-title">Report Delivery Issue</h3>
        <p class="emp-dialog-sub" id="empIssueReceipt"></p>
        <div class="emp-issue-choices">
          <button type="button" class="btn btn-secondary emp-issue-choice" onclick="submitRiderIssue('Delivery not Finished')">Delivery not Finished</button>
          <button type="button" class="btn btn-secondary emp-issue-choice" onclick="submitRiderIssue('No one receiving')">No one receiving</button>
        </div>
        <div class="emp-dialog-fields">
          <label for="customIssueReason" class="form-label">Issue details (5 to 500 characters)</label>
          <input type="text" id="customIssueReason" minlength="5" maxlength="500" oninput="this.setCustomValidity('')" class="form-input" placeholder="Or enter specific reason...">
        </div>
        <div class="emp-dialog-actions">
          <button type="button" class="btn btn-ghost emp-dialog-btn" onclick="closeRiderIssueDialog()">Cancel</button>
          <button type="button" class="btn btn-primary emp-dialog-btn" onclick="submitCustomRiderIssue()">Submit</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
  }
  if (employeeBusy) return;
  const problem = employeeActionProblem(job, 'issue');
  if (problem) { showEmployeeError(problem); return; }
  window._activeIssueJob = job;
  document.getElementById('empIssueReceipt').textContent = 'Order ' + job.receipt + ' (' + job.customer + ')';
  document.getElementById('customIssueReason').value = '';
  if (!modal.open) modal.showModal();
  document.getElementById('customIssueReason').focus();
}

function closeRiderIssueDialog() {
  const modal = document.getElementById('empIssueModal');
  if (modal) modal.close();
  window._activeIssueJob = null;
}

function submitRiderIssue(reason) {
  const job = window._activeIssueJob;
  closeRiderIssueDialog();
  if (job) changeJob(job, 'issue', reason);
}

function submitCustomRiderIssue() {
  const custom = (document.getElementById('customIssueReason')?.value || '').trim();
  if (custom.length < 5 || custom.length > 500) {
    const input = document.getElementById('customIssueReason');
    input.setCustomValidity('Describe the issue using 5 to 500 characters.');
    input.reportValidity();
    return;
  }
  const job = window._activeIssueJob;
  closeRiderIssueDialog();
  if (job) changeJob(job, 'issue', custom);
}

async function confirmEmployeeLogout() {
  if (employeeBusy) return;
  try {
    if (pendingEmployeeAction()) { showEmployeeError('Retry the saved action before signing out.'); return; }
    if (await appConfirm('Sign out of your work queue?', 'Sign out')) logout();
  } catch (error) { showEmployeeError(error.message); }
}

// ---------- Report Defect ----------

let empDefectSubmitBusy = false;
let empDefectItems = [];

async function openEmployeeDefectDialog() {
  if (employeeBusy) return;
  if (getEmployeeRole() !== 'refiller') return;
  const dialog = document.getElementById('empDefectDialog');
  if (!dialog) return;
  try {
    const data = await API.request('employee/items');
    empDefectItems = Array.isArray(data.items) ? data.items : [];
  } catch (error) {
    showEmployeeError((error.message || 'Could not load stock items.') + ' Try again.');
    return;
  }
  const select = document.getElementById('empDefectItem');
  if (select) {
    select.innerHTML = empDefectItems.map(item =>
      '<option value="' + Number(item.id) + '">' + esc(item.item) + ' (' + Number(item.on ?? 0).toLocaleString() + ' ' + esc(item.unit || 'pcs') + ' in stock)</option>'
    ).join('');
  }
  document.getElementById('empDefectQty').value = '';
  document.getElementById('empDefectReason').value = '';
  const err = document.getElementById('empDefectError');
  if (err) { err.textContent = ''; err.classList.add('hidden'); }
  if (!dialog.open) dialog.showModal();
  const qty = document.getElementById('empDefectQty');
  if (qty) qty.focus();
}

function closeEmployeeDefectDialog() {
  const dialog = document.getElementById('empDefectDialog');
  if (dialog) dialog.close();
}

async function submitEmployeeDefect() {
  if (empDefectSubmitBusy) return;
  const itemId = Number(document.getElementById('empDefectItem')?.value || 0);
  const qty = Number(document.getElementById('empDefectQty')?.value || 0);
  const reason = (document.getElementById('empDefectReason')?.value || '').trim();
  const err = document.getElementById('empDefectError');
  const showError = msg => { if (err) { err.textContent = msg; err.classList.remove('hidden'); } };

  if (!itemId) return showError('Choose an item to report.');
  if (!Number.isFinite(qty) || qty < 1) return showError('Enter a quantity of at least 1.');
  if (reason.length < 3) return showError('Describe the defect in a few words.');

  const item = empDefectItems.find(r => Number(r.id) === itemId);
  if (item && qty > Number(item.on ?? 0)) return showError('Quantity exceeds available stock');

  empDefectSubmitBusy = true;
  const btn = document.getElementById('empDefectSubmitBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }
  try {
    await API.createDefectReport({ inventory_item_id: itemId, quantity: qty, reason: reason });
    closeEmployeeDefectDialog();
    const notice = document.getElementById('employeeResult');
    if (notice) notice.textContent = 'Defect reported. A manager will review it.';
  } catch (error) {
    showError(error.message || 'Could not submit the report. Try again.');
  } finally {
    empDefectSubmitBusy = false;
    if (btn) { btn.disabled = false; btn.textContent = 'Submit report'; }
  }
}
