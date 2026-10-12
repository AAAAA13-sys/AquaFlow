let shiftFlowChecked = false;
let shiftLogoutDirectFn = null;

async function checkShiftEntrance() {
  if (shiftFlowChecked) return;
  shiftFlowChecked = true;

  // Don't pop up on auth/login pages or admin owner portal pages
  const path = window.location.pathname;
  if (path === '/' || path.startsWith('/owner') || path.startsWith('/admin')) return;

  const session = typeof getSession === 'function' ? getSession() : null;
  if (!session || session.role === 'admin') return;

  if (typeof API === 'undefined' || typeof API.request !== 'function') return;

  try {
    const res = await API.request('employee/attendance');
    const row = res?.row;
    if (!row) return;

    // If not clocked in yet today, show the Shift Time In modal
    if (!row.time_in) {
      showShiftTimeInModal(row);
    }
  } catch (_) {
    // Gracefully ignore if offline or API unreachable
  }
}

function showShiftTimeInModal(row) {
  const modal = document.getElementById('shiftTimeInModal');
  if (!modal) return;

  const lateWrap = document.getElementById('shiftTimeInLateWrap');
  const now = new Date();
  const hours = now.getHours();
  const minutes = now.getMinutes();

  // If past 8:00 AM, show late reason input
  if (hours > 8 || (hours === 8 && minutes > 0)) {
    if (lateWrap) lateWrap.classList.remove('hidden');
  } else {
    if (lateWrap) lateWrap.classList.add('hidden');
  }

  modal.classList.remove('hidden');
}

function closeShiftTimeInModal() {
  const modal = document.getElementById('shiftTimeInModal');
  if (modal) modal.classList.add('hidden');
}

async function handleShiftModalTimeIn() {
  const btn = document.getElementById('btnShiftModalTimeIn');
  const errEl = document.getElementById('shiftTimeInError');
  const reasonInput = document.getElementById('shiftTimeInReason');
  const reason = (reasonInput?.value || '').trim();

  if (errEl) errEl.classList.add('hidden');
  if (btn) btn.disabled = true;

  try {
    await API.request('employee/attendance/time-in', {
      method: 'POST',
      body: { reason: reason || null }
    });

    closeShiftTimeInModal();

    // If the active page has an attendance loader, refresh it
    if (typeof loadCashierAttendance === 'function') loadCashierAttendance();
    if (typeof refreshEmployee === 'function') refreshEmployee();
  } catch (err) {
    const msg = err.message || 'Could not time in. Please retry.';
    if (errEl) {
      errEl.textContent = msg;
      errEl.classList.remove('hidden');
    }
    const lateWrap = document.getElementById('shiftTimeInLateWrap');
    if (lateWrap && msg.includes('Explain')) {
      lateWrap.classList.remove('hidden');
      reasonInput?.focus();
    }
  } finally {
    if (btn) btn.disabled = false;
  }
}

// LOGOUT WORKFLOW
async function interceptLogoutWithAttendance(onDirectLogout) {
  const session = typeof getSession === 'function' ? getSession() : null;
  if (!session || session.role === 'admin') {
    return onDirectLogout();
  }

  if (typeof API === 'undefined' || typeof API.request !== 'function') {
    return onDirectLogout();
  }

  try {
    const res = await API.request('employee/attendance');
    const row = res?.row;

    // If active shift in progress (timed in, not yet timed out)
    if (row && row.time_in && !row.time_out) {
      showShiftLogoutModal(row, onDirectLogout);
      return;
    }
  } catch (_) {}

  // Otherwise proceed to direct confirmation/logout
  onDirectLogout();
}

function showShiftLogoutModal(row, onDirectLogout) {
  shiftLogoutDirectFn = onDirectLogout;
  const modal = document.getElementById('shiftLogoutModal');
  if (!modal) {
    return onDirectLogout();
  }

  const inTimeEl = document.getElementById('shiftLogoutInTime');
  if (inTimeEl && row.time_in) {
    try {
      const d = new Date(row.time_in);
      inTimeEl.textContent = 'Timed in today at ' + d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
    } catch (_) {
      inTimeEl.textContent = 'Timed in: ' + row.time_in;
    }
  }

  const earlyWrap = document.getElementById('shiftLogoutEarlyWrap');
  if (row.time_in) {
    try {
      const inDate = new Date(row.time_in);
      const diffMs = Date.now() - inDate.getTime();
      const diffHours = diffMs / (1000 * 60 * 60);
      if (diffHours < 9 && earlyWrap) {
        earlyWrap.classList.remove('hidden');
      } else if (earlyWrap) {
        earlyWrap.classList.add('hidden');
      }
    } catch (_) {}
  }

  modal.classList.remove('hidden');
}

function closeShiftLogoutModal() {
  const modal = document.getElementById('shiftLogoutModal');
  if (modal) modal.classList.add('hidden');
}

function performDirectLogout() {
  closeShiftLogoutModal();
  if (typeof shiftLogoutDirectFn === 'function') {
    shiftLogoutDirectFn();
  } else {
    logout();
  }
}

async function handleShiftModalTimeOutAndLogout() {
  const btn = document.getElementById('btnShiftModalTimeOut');
  const errEl = document.getElementById('shiftLogoutError');
  const reasonInput = document.getElementById('shiftLogoutReason');
  const reason = (reasonInput?.value || '').trim();

  if (errEl) errEl.classList.add('hidden');
  if (btn) btn.disabled = true;

  try {
    await API.request('employee/attendance/time-out', {
      method: 'POST',
      body: { reason: reason || null }
    });

    closeShiftLogoutModal();
    logout();
  } catch (err) {
    const msg = err.message || 'Could not record time out.';
    if (errEl) {
      errEl.textContent = msg;
      errEl.classList.remove('hidden');
    }
    const earlyWrap = document.getElementById('shiftLogoutEarlyWrap');
    if (earlyWrap && msg.includes('Explain')) {
      earlyWrap.classList.remove('hidden');
      reasonInput?.focus();
    }
    if (btn) btn.disabled = false;
  }
}

// Auto-check entrance on DOM load
if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(checkShiftEntrance, 300));
  } else {
    setTimeout(checkShiftEntrance, 300);
  }
}
