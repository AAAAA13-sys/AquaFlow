    <form id="forgotPasswordForm" class="auth-form hidden" novalidate onsubmit="event.preventDefault(); submitForgotPassword();">
      <h2>Reset password</h2>
      <p id="forgotPasswordDesc">Enter your account email address to receive a 6-digit verification code.</p>

      {{-- Step 1: Request OTP via email --}}
      <div id="forgotStepEmail">
        <label class="form-label" for="forgotEmail">Account email address</label>
        <input id="forgotEmail" required type="email" class="form-input" autocomplete="email" placeholder="you@example.com" maxlength="255">
        <button type="submit" id="btnSendOtp" class="btn btn-primary btn-block" style="margin-top:12px;">Send verification code</button>
        <div style="margin-top:14px;text-align:center;">
          <a href="#" class="auth-link" onclick="event.preventDefault(); showAdminLoginForm();" style="font-size:13px;">&larr; Back to login</a>
        </div>
      </div>

      {{-- Step 2: Input 6-digit OTP only --}}
      <div id="forgotStepOtp" class="hidden">
        <label class="form-label" for="forgotOtp">6-digit verification code</label>
        <input id="forgotOtp" required class="form-input" autocomplete="one-time-code" placeholder="000000" maxlength="6" pattern="[0-9]{6}" inputmode="numeric">
        <p class="credential-hint" style="margin-top:4px;">Check your email inbox for the verification code sent by the system.</p>

        <button type="submit" id="btnVerifyOtp" class="btn btn-primary btn-block" style="margin-top:12px;">Continue</button>
        <div style="margin-top:10px;text-align:center;">
          <button type="button" id="btnResendOtp" class="btn btn-ghost btn-sm" onclick="resendOtp()" style="font-size:12px;">Resend code</button>
          <span id="resendCountdown" class="settings-help" style="font-size:12px;margin-left:6px;"></span>
        </div>
        <div style="margin-top:14px;text-align:center;">
          <a href="#" class="auth-link" onclick="event.preventDefault(); backToEnterEmail();" style="font-size:13px;">&larr; Back to enter email</a>
        </div>
      </div>

      {{-- Step 3: Set new password after OTP is verified --}}
      <div id="forgotStepPassword" class="hidden">
        <label class="form-label" for="forgotNewPassword">New password</label>
        <div class="credential-control">
          <input id="forgotNewPassword" required type="password" class="form-input" autocomplete="new-password" minlength="8" maxlength="64" placeholder="New password">
          <button type="button" class="password-toggle-btn" onclick="togglePassword('forgotNewPassword', this)" aria-controls="forgotNewPassword" aria-pressed="false" aria-label="Show password" title="Show password">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
        <p class="credential-hint">At least 8 characters with uppercase, lowercase and a number.</p>

        <label class="form-label" for="forgotConfirmPassword">Confirm new password</label>
        <div class="credential-control">
          <input id="forgotConfirmPassword" required type="password" class="form-input" autocomplete="new-password" minlength="8" maxlength="64" placeholder="Confirm new password">
          <button type="button" class="password-toggle-btn" onclick="togglePassword('forgotConfirmPassword', this)" aria-controls="forgotConfirmPassword" aria-pressed="false" aria-label="Show password" title="Show password">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>

        <button type="submit" id="btnResetPassword" class="btn btn-primary btn-block" style="margin-top:12px;">Reset password</button>
        <div style="margin-top:14px;text-align:center;">
          <a href="#" class="auth-link" onclick="event.preventDefault(); backToEnterEmail();" style="font-size:13px;">&larr; Back to enter email</a>
        </div>
      </div>
    </form>
