let recoveryBusy = false;
const recoveryField = id => document.getElementById(id);
function recoveryValidFields(ids) { return ids.every(id => recoveryField(id).reportValidity()); }
async function recoveryAction(action, fallback) {
  if (recoveryBusy) return;
  recoveryBusy = true;
  recoveryField('err')?.classList.add('hidden');
  const buttons = [...(recoveryField('forgotPasswordForm')?.querySelectorAll('button') || [])];
  buttons.forEach(button => button.disabled = true);
  try { await action(); }
  catch (error) { showLoginError(error.message || fallback); }
  finally {
    recoveryBusy = false;
    buttons.forEach(button => button.disabled = false);
    if (recoveryField('btnResendOtp')) {
      recoveryField('btnResendOtp').disabled = resendSeconds > 0;
    }
  }
}

// Shared admin and employee password recovery.
let resendTimer = null;
let resendSeconds = 0;

function submitForgotPassword() {
  if (!recoveryField('forgotStepEmail').classList.contains('hidden')) {
    return submitForgotEmail();
  }
  if (!recoveryField('forgotStepOtp').classList.contains('hidden')) {
    return submitVerifyOtp();
  }
  return submitResetPassword();
}

function showForgotPasswordForm() {
  if (recoveryBusy) return;
  recoveryField('err')?.classList.add('hidden');
  if (recoveryField('loginStatus')) recoveryField('loginStatus').textContent = '';
  recoveryField('adminForm')?.classList.add('hidden');
  recoveryField('setupForm')?.classList.add('hidden');
  recoveryField('forgotPasswordForm')?.classList.remove('hidden');
  recoveryField('forgotStepEmail')?.classList.remove('hidden');
  recoveryField('forgotStepOtp')?.classList.add('hidden');
  recoveryField('forgotStepPassword')?.classList.add('hidden');
  if (recoveryField('forgotPasswordDesc')) {
    recoveryField('forgotPasswordDesc').textContent = 'Enter your account email address to receive a 6-digit verification code.';
  }
  recoveryField('loginDescription')?.classList.add('hidden');
  recoveryField('forgotEmail')?.focus();
}

function showAdminLoginForm() {
  if (recoveryBusy) return;
  if (resendTimer) clearInterval(resendTimer);
  resendSeconds = 0;
  recoveryField('err')?.classList.add('hidden');
  if (recoveryField('loginStatus')) recoveryField('loginStatus').textContent = '';
  recoveryField('forgotPasswordForm')?.classList.add('hidden');
  recoveryField('forgotStepEmail')?.classList.remove('hidden');
  recoveryField('forgotStepOtp')?.classList.add('hidden');
  recoveryField('forgotStepPassword')?.classList.add('hidden');
  recoveryField('setupForm')?.classList.add('hidden');
  recoveryField('adminForm')?.classList.remove('hidden');
  recoveryField('loginDescription')?.classList.remove('hidden');
  (recoveryField('adminEmail') || recoveryField('u'))?.focus();
}

function backToEnterEmail() {
  if (recoveryBusy) return;
  if (resendTimer) clearInterval(resendTimer);
  resendSeconds = 0;
  recoveryField('err')?.classList.add('hidden');
  if (recoveryField('loginStatus')) recoveryField('loginStatus').textContent = '';
  recoveryField('forgotStepOtp')?.classList.add('hidden');
  recoveryField('forgotStepPassword')?.classList.add('hidden');
  recoveryField('forgotStepEmail')?.classList.remove('hidden');
  if (recoveryField('forgotPasswordDesc')) {
    recoveryField('forgotPasswordDesc').textContent = 'Enter your account email address to receive a 6-digit verification code.';
  }
  if (recoveryField('forgotOtp')) recoveryField('forgotOtp').value = '';
  const emailInput = recoveryField('forgotEmail');
  if (emailInput) {
    emailInput.focus();
    if (typeof emailInput.select === 'function') {
      emailInput.select();
    }
  }
}

function startResendCooldown(seconds = 60) {
  if (resendTimer) clearInterval(resendTimer);
  resendSeconds = seconds;
  const resendBtn = recoveryField('btnResendOtp');
  const countdownEl = recoveryField('resendCountdown');
  if (resendBtn) resendBtn.disabled = true;
  if (countdownEl) countdownEl.textContent = '(' + resendSeconds + 's)';

  resendTimer = setInterval(() => {
    resendSeconds--;
    if (resendSeconds <= 0) {
      clearInterval(resendTimer);
      if (resendBtn) resendBtn.disabled = false;
      if (countdownEl) countdownEl.textContent = '';
    } else {
      if (countdownEl) countdownEl.textContent = '(' + resendSeconds + 's)';
    }
  }, 1000);
}

async function submitForgotEmail() {
  if (!recoveryValidFields(['forgotEmail'])) return;
  const email = recoveryField('forgotEmail').value.trim();

  return recoveryAction(async () => {
    await API.post('auth/forgot-password/send-otp', { email });
    recoveryField('forgotStepEmail').classList.add('hidden');
    recoveryField('forgotStepOtp').classList.remove('hidden');
    recoveryField('forgotStepPassword').classList.add('hidden');
    if (recoveryField('forgotPasswordDesc')) {
      recoveryField('forgotPasswordDesc').textContent = 'If this address belongs to an active account, check its inbox for a 6-digit code.';
    }
    recoveryField('forgotOtp').value = '';
    recoveryField('forgotOtp').focus();
    startResendCooldown(60);
  }, 'Could not send verification code. Please try again.');
}

async function resendOtp() {
  if (resendSeconds > 0) return;
  const email = recoveryField('forgotEmail')?.value.trim();
  if (!email) return;

  return recoveryAction(async () => {
    await API.post('auth/forgot-password/send-otp', { email });
    startResendCooldown(60);
    if (recoveryField('loginStatus')) {
      recoveryField('loginStatus').textContent = 'A new verification code has been dispatched to your email.';
    }
  }, 'Could not resend verification code.');
}

async function submitVerifyOtp() {
  if (!recoveryValidFields(['forgotEmail', 'forgotOtp'])) return;
  const email = recoveryField('forgotEmail').value.trim();
  const otp = recoveryField('forgotOtp').value.trim();

  if (!/^[0-9]{6}$/.test(otp)) {
    showLoginError('Please enter a valid 6-digit verification code.');
    recoveryField('forgotOtp').focus();
    return;
  }

  return recoveryAction(async () => {
    await API.post('auth/forgot-password/verify-otp', { email, otp });
    recoveryField('forgotStepEmail').classList.add('hidden');
    recoveryField('forgotStepOtp').classList.add('hidden');
    recoveryField('forgotStepPassword').classList.remove('hidden');
    if (recoveryField('forgotPasswordDesc')) {
      recoveryField('forgotPasswordDesc').textContent = 'Verification code confirmed. Choose your new password.';
    }
    recoveryField('forgotNewPassword').value = '';
    recoveryField('forgotConfirmPassword').value = '';
    recoveryField('forgotNewPassword').focus();
  }, 'Invalid verification code. Please check your code and try again.');
}

async function submitResetPassword() {
  if (!recoveryValidFields(['forgotEmail', 'forgotOtp', 'forgotNewPassword', 'forgotConfirmPassword'])) return;

  const email = recoveryField('forgotEmail').value.trim();
  const otp = recoveryField('forgotOtp').value.trim();
  const password = recoveryField('forgotNewPassword').value;
  const password_confirmation = recoveryField('forgotConfirmPassword').value;

  if (password !== password_confirmation) {
    showLoginError('Passwords do not match.');
    recoveryField('forgotConfirmPassword').focus();
    return;
  }

  return recoveryAction(async () => {
    const res = await API.post('auth/forgot-password/verify-reset', {
      email,
      otp,
      password,
      password_confirmation
    });

    if (resendTimer) clearInterval(resendTimer);
    ['forgotEmail', 'forgotOtp', 'forgotNewPassword', 'forgotConfirmPassword'].forEach(id => {
      if (recoveryField(id)) recoveryField(id).value = '';
    });
    recoveryField('forgotPasswordForm').classList.add('hidden');
    recoveryField('adminForm').classList.remove('hidden');
    recoveryField('loginDescription').classList.remove('hidden');
    if (recoveryField('loginStatus')) {
      recoveryField('loginStatus').textContent = res.message || 'Password reset successfully. Sign in with your new password.';
    }
    if (recoveryField('p')) recoveryField('p').value = '';
    (recoveryField('adminEmail') || recoveryField('u')).focus();
  }, 'Failed to reset password. Please check your verification code.');
}
