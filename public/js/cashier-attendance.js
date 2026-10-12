let cashierAttendanceBusy = false;
let currentCashierAttendance = null;

function formatAttendanceClockTime(isoStr) {
  if (!isoStr || isoStr === '--') return '--:--';
  try {
    const d = new Date(isoStr);
    if (isNaN(d.getTime())) return isoStr;
    return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
  } catch {
    return isoStr;
  }
}

function getCashierAttendanceBadge(status) {
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

async function loadCashierAttendance() {
  try {
    const data = await API.request('employee/attendance');
    currentCashierAttendance = data.row;

    const badgeInfo = getCashierAttendanceBadge(data.row.status);
    const badgeEl = document.getElementById('attendanceBadge');
    if (badgeEl) {
      badgeEl.textContent = badgeInfo.text;
      badgeEl.className = 'emp-badge ' + badgeInfo.cls;
    }

    const timeInEl = document.getElementById('timeInDisplay');
    const timeOutEl = document.getElementById('timeOutDisplay');
    if (timeInEl) timeInEl.textContent = formatAttendanceClockTime(data.row.time_in);
    if (timeOutEl) timeOutEl.textContent = formatAttendanceClockTime(data.row.time_out);

    const btnIn = document.getElementById('btnTimeIn');
    const btnOut = document.getElementById('btnTimeOut');
    const btnInText = document.getElementById('btnTimeInText');
    const btnOutText = document.getElementById('btnTimeOutText');
    const noticeEl = document.getElementById('attendanceNotice');

    if (data.row.time_out) {
      if (btnIn) {
        btnIn.disabled = false;
        if (btnInText) btnInText.textContent = 'Resume shift';
      }
      if (btnOut) {
        btnOut.disabled = true;
        if (btnOutText) btnOutText.textContent = 'Timed out';
      }
      if (noticeEl) {
        noticeEl.textContent = 'Shift completed today at ' + formatAttendanceClockTime(data.row.time_out) + '.';
        noticeEl.classList.remove('hidden');
      }
    } else if (data.row.time_in) {
      if (btnIn) {
        btnIn.disabled = true;
        if (btnInText) btnInText.textContent = 'Timed in';
      }
      if (btnOut) {
        btnOut.disabled = false;
        if (btnOutText) btnOutText.textContent = 'Time out';
      }
      if (noticeEl) noticeEl.classList.add('hidden');
    } else {
      if (btnIn) {
        btnIn.disabled = false;
        if (btnInText) btnInText.textContent = 'Time in';
      }
      if (btnOut) {
        btnOut.disabled = true;
        if (btnOutText) btnOutText.textContent = 'Time out';
      }
      if (noticeEl) noticeEl.classList.add('hidden');
    }
  } catch (error) {
    const errEl = document.getElementById('attendanceError');
    if (errEl) {
      errEl.textContent = error.message || 'Unable to load attendance details.';
      errEl.classList.remove('hidden');
    }
  }
}

async function punchCashierAttendance(action) {
  if (cashierAttendanceBusy) return;

  const isResuming = currentCashierAttendance?.time_out;
  const confirmPrompt = action === 'time-in'
    ? (isResuming ? 'Resume your shift for today?' : 'Confirm Time In for today?')
    : 'Are you sure you want to Time Out? This will complete your shift for today.';
  const acceptText = action === 'time-in' ? (isResuming ? 'Resume Shift' : 'Time In') : 'Time Out';

  if (!await appConfirm(confirmPrompt, acceptText)) return;

  await executeCashierPunch(action, null);
}

async function executeCashierPunch(action, reason = null) {
  if (cashierAttendanceBusy) return;
  cashierAttendanceBusy = true;
  const errEl = document.getElementById('attendanceError');
  if (errEl) errEl.classList.add('hidden');

  try {
    await API.request('employee/attendance/' + action, {
      method: 'POST',
      body: { reason }
    });
    await loadCashierAttendance();
  } catch (error) {
    const errorMsg = error.message || '';
    if (errorMsg.includes('Explain') || error.data?.errors?.reason) {
      cashierAttendanceBusy = false;
      const userReason = await appPrompt(errorMsg + ' Please enter an explanation (5-500 characters):', 'e.g., Heavy traffic or approved early departure');
      if (userReason) {
        return await executeCashierPunch(action, userReason);
      }
      return;
    }

    if (errEl) {
      errEl.textContent = errorMsg || 'Could not record time. Please retry.';
      errEl.classList.remove('hidden');
    }
  } finally {
    cashierAttendanceBusy = false;
  }
}
