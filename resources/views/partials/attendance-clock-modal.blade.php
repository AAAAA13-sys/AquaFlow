@if(auth()->check() && !auth()->user()->isAdmin())
<!-- Shift Time In Modal (Entrance Clock-in Popup) -->
<div id="shiftTimeInModal" class="modal-backdrop hidden" style="position:fixed;inset:0;background:rgba(15,23,42,0.65);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;">
  <div class="card" style="width:100%;max-width:440px;background:#FFFFFF;border-radius:16px;box-shadow:0 20px 40px rgba(15,23,42,0.2);padding:24px;border:1px solid #E2E8F0;position:relative;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
      <div style="width:44px;height:44px;border-radius:12px;background:#EFF6FF;color:#1D4ED8;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
      <div>
        <h3 style="margin:0;font-size:1.15rem;font-weight:700;color:#0F172A;">Welcome! Time In</h3>
        <span style="font-size:0.8rem;color:#64748B;">Staff: <b>{{ auth()->user()->name }}</b></span>
      </div>
    </div>

    <p style="margin:0 0 16px;font-size:0.875rem;line-height:1.5;color:#475569;">
      You have not clocked in for today yet. Time in now to record your shift attendance before starting operations.
    </p>

    <div id="shiftTimeInLateWrap" class="hidden" style="margin-bottom:16px;">
      <label for="shiftTimeInReason" style="display:block;font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:#334155;margin-bottom:6px;">
        Late Arrival Reason (Store opened at 8:00 AM)
      </label>
      <textarea id="shiftTimeInReason" rows="2" placeholder="e.g., Heavy traffic or approved delay" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #CBD5E1;background:#FFFFFF;color:#0F172A;border-radius:8px;font-size:0.875rem;resize:vertical;"></textarea>
      <span style="display:block;font-size:0.75rem;color:#64748B;margin-top:4px;">5-500 characters required when arriving after 8:00 AM.</span>
    </div>

    <div id="shiftTimeInError" class="emp-alert hidden" style="margin-bottom:14px;background:#FEE2E2;border:1px solid #FECACA;color:#B91C1C;padding:10px 12px;border-radius:8px;font-size:0.8rem;font-weight:600;"></div>

    <div style="display:flex;flex-direction:column;gap:8px;">
      <button type="button" id="btnShiftModalTimeIn" onclick="handleShiftModalTimeIn()" style="width:100%;height:44px;background:#1D4ED8;color:#FFFFFF;border:0;border-radius:8px;font-weight:600;font-size:0.95rem;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:background 0.15s;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Time In & Start Shift
      </button>
      <button type="button" onclick="closeShiftTimeInModal()" style="width:100%;height:36px;background:transparent;border:0;color:#64748B;font-size:0.825rem;font-weight:500;cursor:pointer;">
        Skip for now
      </button>
    </div>
  </div>
</div>

<!-- Shift Time Out on Logout Modal -->
<div id="shiftLogoutModal" class="modal-backdrop hidden" style="position:fixed;inset:0;background:rgba(15,23,42,0.65);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;">
  <div class="card" style="width:100%;max-width:440px;background:#FFFFFF;border-radius:16px;box-shadow:0 20px 40px rgba(15,23,42,0.2);padding:24px;border:1px solid #E2E8F0;position:relative;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
      <div style="width:44px;height:44px;border-radius:12px;background:#EFF6FF;color:#1D4ED8;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </div>
      <div>
        <h3 style="margin:0;font-size:1.15rem;font-weight:700;color:#0F172A;">Clock Out & End Shift?</h3>
        <span id="shiftLogoutInTime" style="font-size:0.8rem;color:#64748B;">Shift in progress</span>
      </div>
    </div>

    <p style="margin:0 0 14px;font-size:0.875rem;line-height:1.5;color:#475569;">
      Are you finishing your shift for today? You can clock out now to record your departure, or remain clocked in if this is a temporary log out.
    </p>

    <div id="shiftLogoutEarlyWrap" class="hidden" style="margin-bottom:14px;">
      <label for="shiftLogoutReason" style="display:block;font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:#334155;margin-bottom:6px;">
        Early Departure Reason (Under 9 hours)
      </label>
      <textarea id="shiftLogoutReason" rows="2" placeholder="e.g., Doctor appointment or store permission" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #CBD5E1;background:#FFFFFF;color:#0F172A;border-radius:8px;font-size:0.875rem;resize:vertical;"></textarea>
      <span style="display:block;font-size:0.75rem;color:#64748B;margin-top:4px;">5-500 characters required if leaving before full 9-hour shift.</span>
    </div>

    <div id="shiftLogoutError" class="emp-alert hidden" style="margin-bottom:14px;background:#FEE2E2;border:1px solid #FECACA;color:#B91C1C;padding:10px 12px;border-radius:8px;font-size:0.8rem;font-weight:600;"></div>

    <div style="display:flex;flex-direction:column;gap:8px;">
      <button type="button" id="btnShiftModalTimeOut" onclick="handleShiftModalTimeOutAndLogout()" style="width:100%;height:44px;background:#1D4ED8;color:#FFFFFF;border:0;border-radius:8px;font-weight:600;font-size:0.95rem;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:background 0.15s;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Time Out & Log Out
      </button>
      <button type="button" onclick="performDirectLogout()" style="width:100%;height:40px;background:#FFFFFF;border:1px solid #CBD5E1;color:#1E293B;border-radius:8px;font-size:0.85rem;font-weight:600;cursor:pointer;transition:background 0.15s;">
        Log Out Only (Stay Clocked In)
      </button>
      <button type="button" onclick="closeShiftLogoutModal()" style="width:100%;height:32px;background:transparent;border:0;color:#64748B;font-size:0.825rem;font-weight:500;cursor:pointer;">
        Cancel
      </button>
    </div>
  </div>
</div>
@endif
