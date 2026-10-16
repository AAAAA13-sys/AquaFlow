let employeeBusy = false;
let employeeFlight = null;
let employeeFresh = false;
let employeeRefreshFailed = false;

function getEmployeeRole() {
  const container = document.querySelector('.emp-container');
  return container?.dataset.role || getSession()?.role || '';
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
      const data = await API.request('employee/orders');
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
  container.replaceChildren();

  const role = getEmployeeRole();
  const queueTitleEl = document.getElementById('queueSectionTitle');
  if (queueTitleEl) queueTitleEl.textContent = role === 'rider' ? 'Delivery Queue' : (role === 'refiller' ? 'Refill Queue' : 'My Orders');

  for (const job of employeeJobs) {
    const card = document.createElement('article');
    card.className = 'emp-order-card';

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
    rightGroup.style.display = 'flex';
    rightGroup.style.alignItems = 'center';
    rightGroup.style.gap = '8px';

    const badgeData = getOrderBadge(job.status);
    const badge = document.createElement('span');
    badge.className = 'emp-badge ' + badgeData.cls;
    badge.textContent = badgeData.text;
    rightGroup.append(badge);

    if (role === 'rider' && ['ready', 'out_for_delivery'].includes(job.status)) {
      const issueBtn = document.createElement('button');
      issueBtn.type = 'button';
      issueBtn.className = 'emp-issue-top-btn';
      issueBtn.style.background = '#fef2f2';
      issueBtn.style.color = '#dc2626';
      issueBtn.style.border = '1px solid #fecaca';
      issueBtn.style.borderRadius = '6px';
      issueBtn.style.fontSize = '11px';
      issueBtn.style.fontWeight = '600';
      issueBtn.style.padding = '3px 8px';
      issueBtn.style.cursor = 'pointer';
      issueBtn.textContent = 'Report Issue';
      issueBtn.disabled = blocked || !!employeeActionProblem(job, 'issue');
      issueBtn.onclick = () => openRiderIssueDialog(job);
      rightGroup.append(issueBtn);
    }

    header.append(textGroup, rightGroup);

    // Items line
    const lines = document.createElement('div');
    lines.className = 'emp-order-lines';
    const linesText = Array.isArray(job.lines) && job.lines.length
      ? job.lines.map(i => i.quantity + ' × ' + i.product).join(', ')
      : (job.description || 'Water Refill');
    lines.textContent = linesText;

    // Details meta (contact details removed per requirements)
    const meta = document.createElement('div');
    meta.className = 'emp-order-meta';

    if (job.address) {
      const row = document.createElement('div');
      row.className = 'emp-meta-row';
      row.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> <a href="https://maps.google.com/?q=${encodeURIComponent(job.address)}" target="_blank" rel="noopener" style="color:var(--brand-primary);text-decoration:underline;">${esc(job.address)}</a>`;
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
      card.append(button);
    }

    container.append(card);
  }

  if (!employeeJobs.length) {
    const empty = document.createElement('div');
    empty.className = 'emp-empty-state';
    empty.textContent = !employeeFresh ? 'Orders could not be loaded. Tap Refresh to try again.' : role === 'rider' ? 'No deliveries assigned yet. New deliveries appear automatically when they are ready.' : 'No orders waiting for preparation. New orders appear automatically.';
    container.append(empty);
  }
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
      pending = {id:job.id, receipt:job.receipt, body:{action, reason, ...detailsBody, version:job.version, submission_key:newSubmissionKey()}};
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

// Initial load & periodic refresh
refreshEmployee();
window.addEventListener('focus', refreshEmployee);
window.addEventListener('online', refreshEmployee);
setInterval(() => {
  if (!document.hidden && !employeeBusy && !document.querySelector('dialog[open]') && !document.activeElement?.closest('#jobs')) refreshEmployee();
}, 5000);

function openRiderIssueDialog(job) {
  let modal = document.getElementById('empIssueModal');
  if (!modal) {
    modal = document.createElement('dialog');
    modal.id = 'empIssueModal';
    modal.className = 'employee-issue-dialog';
    modal.setAttribute('aria-labelledby', 'empIssueTitle');
    modal.innerHTML = `
      <div class="receipt-card" style="width:380px;max-width:calc(100vw - 48px);box-sizing:border-box;font-family:inherit;padding:22px;background:#fff;border-radius:14px;box-shadow:0 12px 28px rgba(0,0,0,0.18);">
        <h3 id="empIssueTitle" style="margin:0 0 6px;font-size:16px;color:#0f172a;">Report Delivery Issue</h3>
        <p style="margin:0 0 16px;font-size:12px;color:#64748b;" id="empIssueReceipt"></p>
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px;">
          <button type="button" class="btn btn-secondary" style="text-align:left;font-size:13px;padding:10px 14px;font-weight:600;" onclick="submitRiderIssue('Delivery not Finished')">
            Delivery not Finished
          </button>
          <button type="button" class="btn btn-secondary" style="text-align:left;font-size:13px;padding:10px 14px;font-weight:600;" onclick="submitRiderIssue('no one receiving')">
            No one receiving
          </button>
        </div>
        <div style="margin-bottom:14px;">
          <label for="customIssueReason">Issue details (5 to 500 characters)</label><input type="text" id="customIssueReason" minlength="5" maxlength="500" oninput="this.setCustomValidity('')" class="form-input" placeholder="Or enter specific reason..." style="width:100%;font-size:12px;box-sizing:border-box;padding:8px 10px;">
        </div>
        <div class="button-grid-2">
          <button type="button" class="btn btn-ghost" onclick="closeRiderIssueDialog()">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="submitCustomRiderIssue()">Submit</button>
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
