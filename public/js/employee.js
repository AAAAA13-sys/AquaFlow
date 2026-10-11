let employeeBusy = false;
let employeeRefresh = 0;
let currentAttendance = null;

function getEmployeeRole() {
  const container = document.querySelector('.emp-container');
  return container?.dataset.role || getSession()?.role || '';
}

function formatClockTime(isoStr) {
  if (!isoStr || isoStr === '--') return '--:--';
  try {
    const d = new Date(isoStr);
    if (isNaN(d.getTime())) return isoStr;
    return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
  } catch {
    return isoStr;
  }
}

function getAttendanceBadge(status) {
  const map = {
    late: { text: 'Late', cls: 'emp-badge-late' },
    present: { text: 'On Time', cls: 'emp-badge-present' },
    checked_in: { text: 'Checked In', cls: 'emp-badge-checked_in' },
    completed: { text: 'Shift Done', cls: 'emp-badge-completed' },
    absent: { text: 'Absent', cls: 'emp-badge-absent' },
    leave: { text: 'On Leave', cls: 'emp-badge-neutral' },
    not_recorded: { text: 'Not Clocked In', cls: 'emp-badge-neutral' },
    needs_review: { text: 'Needs Review', cls: 'emp-badge-late' }
  };
  return map[status] || {
    text: (status || 'Unknown').charAt(0).toUpperCase() + (status || '').slice(1).replaceAll('_', ' '),
    cls: 'emp-badge-neutral'
  };
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

async function refreshEmployee() {
  const sequence = ++employeeRefresh;
  try {
    const data = await API.request('employee/attendance');
    if (sequence !== employeeRefresh) return;
    currentAttendance = data.row;

    // Update Attendance Badges & Displays
    const badgeInfo = getAttendanceBadge(data.row.status);
    const badgeEl = document.getElementById('attendanceBadge');
    if (badgeEl) {
      badgeEl.textContent = badgeInfo.text;
      badgeEl.className = 'emp-badge ' + badgeInfo.cls;
    }

    const timeInEl = document.getElementById('timeInDisplay');
    const timeOutEl = document.getElementById('timeOutDisplay');
    if (timeInEl) timeInEl.textContent = formatClockTime(data.row.time_in);
    if (timeOutEl) timeOutEl.textContent = formatClockTime(data.row.time_out);

    // Button states & labels
    const btnIn = document.getElementById('btnTimeIn');
    const btnOut = document.getElementById('btnTimeOut');
    const btnInText = document.getElementById('btnTimeInText');
    const btnOutText = document.getElementById('btnTimeOutText');
    const shiftNotice = document.getElementById('shiftNotice');

    if (data.row.time_out) {
      // Employee has timed out
      if (btnIn) {
        btnIn.disabled = false;
        btnIn.style.opacity = '1';
        if (btnInText) btnInText.textContent = 'Resume shift';
      }
      if (btnOut) {
        btnOut.disabled = true;
        btnOut.style.opacity = '0.5';
        if (btnOutText) btnOutText.textContent = 'Timed out';
      }
      if (shiftNotice) {
        shiftNotice.textContent = 'Shift completed today at ' + formatClockTime(data.row.time_out) + '. You can still process orders or resume your shift.';
        shiftNotice.classList.remove('hidden');
      }
    } else if (data.row.time_in) {
      // Active shift
      if (btnIn) {
        btnIn.disabled = true;
        btnIn.style.opacity = '0.5';
        if (btnInText) btnInText.textContent = 'Timed in';
      }
      if (btnOut) {
        btnOut.disabled = false;
        btnOut.style.opacity = '1';
        if (btnOutText) btnOutText.textContent = 'Time out';
      }
      if (shiftNotice) {
        shiftNotice.classList.add('hidden');
      }
    } else {
      // Not timed in yet
      if (btnIn) {
        btnIn.disabled = false;
        btnIn.style.opacity = '1';
        if (btnInText) btnInText.textContent = 'Time in';
      }
      if (btnOut) {
        btnOut.disabled = true;
        btnOut.style.opacity = '0.5';
        if (btnOutText) btnOutText.textContent = 'Time out';
      }
      if (shiftNotice) {
        shiftNotice.classList.add('hidden');
      }
    }

    await refreshJobs(sequence);

    // Update status dot
    const pulseDot = document.getElementById('pulseDot');
    if (pulseDot) pulseDot.classList.remove('offline');
    const conn = document.getElementById('connection');
    if (conn) {
      const timeStr = new Date(data.server_time || Date.now()).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
      conn.textContent = 'Updated ' + timeStr;
    }
  } catch (error) {
    if (sequence !== employeeRefresh) return;
    const pulseDot = document.getElementById('pulseDot');
    if (pulseDot) pulseDot.classList.add('offline');
    const conn = document.getElementById('connection');
    if (conn) conn.textContent = 'Offline';
  }
}

// Cashier-style Attendance Validation Checks
async function punchWithValidation(action) {
  if (employeeBusy) return;

  if (action === 'time-in') {
    const isResuming = currentAttendance?.time_out;
    const confirmMessage = isResuming
      ? 'Resume your shift for today?'
      : 'Confirm Time In for today?';
    const acceptLabel = isResuming ? 'Resume Shift' : 'Time In';
    if (!await appConfirm(confirmMessage, acceptLabel)) return;
  } else if (action === 'time-out') {
    if (!currentAttendance?.time_in) {
      appNotice('You must Time In first before timing out.');
      return;
    }
    if (!await appConfirm('Are you sure you want to Time Out? This will complete your shift for today.', 'Time Out')) return;
  }

  await executePunch(action);
}

async function executePunch(action) {
  if (employeeBusy) return;
  employeeBusy = true;
  const errEl = document.getElementById('error');
  try {
    await API.request('employee/attendance/' + action, {
      method: 'POST',
      body: { reason: null }
    });
    if (errEl) {
      errEl.textContent = '';
      errEl.classList.add('hidden');
    }
    await refreshEmployee();
  } catch (error) {
    if (errEl) {
      errEl.textContent = error.message || 'Could not record time. Please try again.';
      errEl.classList.remove('hidden');
    }
  } finally {
    employeeBusy = false;
  }
}

let employeeJobs = [];
async function refreshJobs(sequence) {
  const data = await API.request('employee/orders');
  if (sequence !== employeeRefresh) return;
  employeeJobs = data.orders || [];

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

    card.append(header, lines, meta);

    // Actions depending on role & status
    let action = null;
    let buttonLabel = null;

    if (role === 'refiller' && job.status === 'preparing') {
      action = 'ready';
      buttonLabel = 'Mark as Ready';
    } else if (role === 'rider') {
      if (job.status === 'preparing') {
        if (!job.rider_id) {
          action = 'assign';
          buttonLabel = 'Claim Delivery';
        } else {
          const note = document.createElement('div');
          note.style.fontSize = '12px';
          note.style.color = '#0284c7';
          note.style.fontWeight = '500';
          note.style.padding = '4px 0';
          note.textContent = 'Claimed · Awaiting refill preparation';
          card.append(note);
        }
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
      button.className = 'emp-action-btn';
      button.textContent = buttonLabel;
      button.onclick = () => changeJob(job, action);
      card.append(button);
    }

    container.append(card);
  }

  if (!employeeJobs.length) {
    const empty = document.createElement('div');
    empty.className = 'emp-empty-state';
    empty.textContent = role === 'rider' ? 'No delivery orders in queue right now.' : (role === 'refiller' ? 'No refill orders in queue right now.' : 'No active orders in queue right now.');
    container.append(empty);
  }
}

// Cashier-style Order Validation Checks
async function changeJob(job, action, reason = null) {
  if (employeeBusy) return;
  const key = 'aquaflow_job:' + (getSession()?.u || 'emp');
  let pending = JSON.parse(sessionStorage.getItem(key) || 'null');
  const errEl = document.getElementById('error');

  if (pending && (pending.id !== job.id || pending.body.action !== action)) {
    if (errEl) {
      errEl.textContent = 'Please tap Refresh first to update pending orders.';
      errEl.classList.remove('hidden');
    }
    return;
  }

  // 1. Shift check: if not timed in, ask employee
  if (!currentAttendance?.time_in) {
    if (!await appConfirm('You have not timed in yet today. Do you want to Time In now and proceed with ' + job.receipt + '?', 'Time In & Proceed')) {
      return;
    }
    await executePunch('time-in');
  } else if (currentAttendance?.time_out) {
    // 2. Shift check: if timed out, ask employee to resume
    if (!await appConfirm('You are currently timed out. Do you want to resume your shift and proceed with ' + job.receipt + '?', 'Resume & Proceed')) {
      return;
    }
    await executePunch('time-in');
  }

  // 3. Action confirmation check (Cashier terminal style)
  const actionLabels = {
    assign: 'Claim Delivery',
    ready: 'Mark as Ready',
    dispatch: 'Start Delivery',
    deliver: 'Confirm Delivered',
    issue: 'Report Problem'
  };
  const label = actionLabels[action] || action;
  if (!pending && !await appConfirm('Confirm ' + label + ' for ' + job.receipt + ' (' + job.customer + ')?', label)) {
    return;
  }

  employeeBusy = true;
  try {
    pending ||= {
      id: job.id,
      body: { action, reason, version: job.version, submission_key: crypto.randomUUID() }
    };
    sessionStorage.setItem(key, JSON.stringify(pending));

    const result = await API.request('employee/orders/' + pending.id, {
      method: 'POST',
      body: pending.body
    });

    if (!result?.order) {
      throw new Error('Order update was not completed. Please tap Refresh.');
    }

    sessionStorage.removeItem(key);
    if (errEl) {
      errEl.textContent = '';
      errEl.classList.add('hidden');
    }
    await refreshEmployee();
  } catch (error) {
    if ([422, 409].includes(error.status)) sessionStorage.removeItem(key);
    if (errEl) {
      errEl.textContent = error.message || 'Action failed. Please tap Refresh.';
      errEl.classList.remove('hidden');
    }
  } finally {
    employeeBusy = false;
  }
}

// Initial load & periodic refresh
refreshEmployee();
window.addEventListener('focus', refreshEmployee);
window.addEventListener('online', refreshEmployee);
setInterval(() => {
  if (!document.hidden && !employeeBusy) refreshEmployee();
}, 5000);

function openRiderIssueDialog(job) {
  let modal = document.getElementById('empIssueModal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'empIssueModal';
    modal.className = 'overlay-modal hidden';
    modal.innerHTML = `
      <div class="receipt-card" style="width:320px;max-width:92vw;padding:22px;background:#fff;border-radius:14px;box-shadow:0 12px 28px rgba(0,0,0,0.18);">
        <h3 style="margin:0 0 6px;font-size:16px;color:#0f172a;">Report Delivery Issue</h3>
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
          <input type="text" id="customIssueReason" class="form-input" placeholder="Or enter specific reason..." style="width:100%;font-size:12px;box-sizing:border-box;padding:8px 10px;">
        </div>
        <div class="button-grid-2">
          <button type="button" class="btn btn-ghost" onclick="closeRiderIssueDialog()">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="submitCustomRiderIssue()">Submit</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
  }
  window._activeIssueJob = job;
  document.getElementById('empIssueReceipt').textContent = 'Order ' + job.receipt + ' (' + job.customer + ')';
  document.getElementById('customIssueReason').value = '';
  modal.classList.remove('hidden');
}

function closeRiderIssueDialog() {
  const modal = document.getElementById('empIssueModal');
  if (modal) modal.classList.add('hidden');
  window._activeIssueJob = null;
}

function submitRiderIssue(reason) {
  const job = window._activeIssueJob;
  closeRiderIssueDialog();
  if (job) changeJob(job, 'issue', reason);
}

function submitCustomRiderIssue() {
  const custom = (document.getElementById('customIssueReason')?.value || '').trim();
  if (!custom) {
    appNotice('Please select an option or enter a reason.');
    return;
  }
  const job = window._activeIssueJob;
  closeRiderIssueDialog();
  if (job) changeJob(job, 'issue', custom);
}
