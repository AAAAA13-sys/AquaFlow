<div id="profileModal" class="overlay-modal hidden" role="dialog" aria-modal="true" aria-labelledby="profileModalTitle">
  <div class="card" style="width:100%;max-width:480px;background:#ffffff;border-radius:16px;box-shadow:0 20px 40px rgba(15,23,42,0.2);padding:0;overflow:hidden;border:1px solid #e2e8f0;margin:auto;">
    <!-- Modal Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid #f1f5f9;background:#f8fafc;">
      <div>
        <h3 id="profileModalTitle" style="margin:0;font-size:17px;font-weight:700;color:#0f172a;">Personal Profile & Settings</h3>
        <p style="margin:2px 0 0;font-size:12px;color:#64748b;">Manage your display name and login password</p>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeProfileModal()" aria-label="Close dialog" style="font-size:18px;line-height:1;padding:4px 8px;">&times;</button>
    </div>

    <!-- User Identity Summary -->
    <div style="padding:16px 24px;background:#f1f5f9;display:flex;align-items:center;gap:14px;border-bottom:1px solid #e2e8f0;">
      <div id="profileAvatarBadge" style="width:46px;height:46px;border-radius:12px;background:#0284c7;color:#ffffff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;flex-shrink:0;">
        --
      </div>
      <div style="flex:1;min-width:0;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <b id="profileOverviewName" style="font-size:15px;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Loading...</b>
          <span id="profileOverviewRole" class="pill pill-neutral" style="font-size:11px;text-transform:capitalize;">--</span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;margin-top:2px;font-size:12px;color:#64748b;flex-wrap:wrap;">
          <span>Username: <strong id="profileOverviewUsername" style="color:#334155;">--</strong></span>
          <span id="profileOverviewJobWrapper" class="hidden">· <span id="profileOverviewJob" style="color:#0284c7;font-weight:600;"></span></span>
        </div>
      </div>
    </div>

    <!-- Edit Form -->
    <form id="profileForm" onsubmit="return saveProfile(event)" style="padding:20px 24px;margin:0;">
      <div class="form-group" style="margin-bottom:18px;">
        <label class="form-label" for="profileNameInput" style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;color:#334155;">Full name</label>
        <input type="text" id="profileNameInput" name="name" class="form-input" required maxlength="100" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
      </div>

      <!-- Password Section Divider -->
      <div style="margin:20px 0 14px;padding-top:14px;border-top:1px solid #f1f5f9;">
        <h4 style="margin:0 0 2px;font-size:14px;font-weight:700;color:#0f172a;">Change password</h4>
        <p style="margin:0 0 12px;font-size:12px;color:#64748b;">Leave password fields blank if you only want to update your name.</p>

        <div class="form-group" style="margin-bottom:12px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <label class="form-label" for="profileCurrentPasswordInput" style="font-size:13px;font-weight:600;color:#334155;margin:0;">Current password</label>
            <button type="button" class="btn btn-ghost btn-sm" onclick="toggleProfilePassword('profileCurrentPasswordInput', this)" style="padding:2px 6px;font-size:11px;">Show</button>
          </div>
          <input type="password" id="profileCurrentPasswordInput" name="current_password" class="form-input" autocomplete="current-password" placeholder="Enter current password to confirm" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
        </div>

        <div class="form-group" style="margin-bottom:12px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <label class="form-label" for="profileNewPasswordInput" style="font-size:13px;font-weight:600;color:#334155;margin:0;">New password</label>
            <button type="button" class="btn btn-ghost btn-sm" onclick="toggleProfilePassword('profileNewPasswordInput', this)" style="padding:2px 6px;font-size:11px;">Show</button>
          </div>
          <input type="password" id="profileNewPasswordInput" name="new_password" class="form-input" autocomplete="new-password" minlength="8" maxlength="64" placeholder="8 to 64 characters, uppercase, lowercase and number" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
        </div>

        <div class="form-group" style="margin-bottom:4px;">
          <label class="form-label" for="profileConfirmPasswordInput" style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;color:#334155;">Confirm new password</label>
          <input type="password" id="profileConfirmPasswordInput" name="new_password_confirmation" class="form-input" autocomplete="new-password" minlength="8" maxlength="64" placeholder="Re-type new password" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
        </div>
      </div>

      <!-- Alerts -->
      <div id="profileError" class="auth-error hidden" role="alert" style="margin:12px 0;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;border-radius:8px;font-size:13px;"></div>
      <div id="profileSuccess" class="hidden" role="status" style="margin:12px 0;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;border-radius:8px;font-size:13px;font-weight:600;"></div>

      <!-- Action Buttons -->
      <div style="display:flex;justify-content:flex-end;align-items:center;gap:10px;margin-top:20px;padding-top:14px;border-top:1px solid #f1f5f9;">
        <button type="button" class="btn btn-ghost" onclick="closeProfileModal()">Cancel</button>
        <button type="submit" id="profileSaveBtn" class="btn btn-primary" style="padding:9px 18px;font-weight:600;">Save changes</button>
      </div>
    </form>
  </div>
</div>
