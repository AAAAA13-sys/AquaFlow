// Admin authentication and first-run setup share the email verification UI.
const firstRun = document.getElementById('adminLogin').dataset.firstRun === '1';
let adminBusy = false;
const field = id => document.getElementById(id);
function adminCredentials() { return {email: field('adminEmail').value.trim(), password: field('p').value}; }
function validFields(ids) { return ids.every(id => field(id).reportValidity()); }
async function adminAction(action, fallbackMessage = '') {
  if (adminBusy) return;
  adminBusy = true;
  field('err').classList.add('hidden');
  field('loginStatus').textContent = '';
  const buttons = [...field('adminLogin').querySelectorAll('button')];
  buttons.forEach(button => button.disabled = true);
  try { await action(); }
  catch (error) {
    // A mail failure carries internal detail ("Check the private SMTP
    // configuration"). Show a plain retry message instead of leaking the
    // server-side configuration state to the login screen.
    const message = fallbackMessage || error.message;
    field('err').textContent = message;
    field('err').classList.remove('hidden');
  }
  finally { adminBusy = false; buttons.forEach(button => button.disabled = false); }
}
function finishAdmin(result) {
  setSession(result.user);
  if (firstRun) field('setupStep').textContent = 'Setup complete. Your station and owner account are ready.';
  ['adminForm', 'otpForm', 'setupForm'].forEach(id => field(id)?.classList.add('hidden'));
  ['p', 'setupPassword', 'setupConfirm', 'otp', 'setupOtp'].forEach(id => { if (field(id)) field(id).value = ''; });
  field('loginDescription').classList.add('hidden');
  if (result.recovery_codes?.length) {
    field('recoveryCodes').textContent = result.recovery_codes.join('\n');
    field('recoverySetup').classList.remove('hidden');
  } else window.location.href = '/admin';
}

/** Show the code step. Shared by the first send and every resend. */
function showOtpStep(message) {
  field('otpEmail').textContent = field('adminEmail').value.trim();
  field('adminForm').classList.add('hidden');
  field('otpForm').classList.remove('hidden');
  field('loginStatus').textContent = message;
  field('otp').value = '';
  field('otp').focus();
}

/** Back to the credential step, so a mistyped address can be corrected. */
function backToAdminStepOne() {
  field('otpForm').classList.add('hidden');
  field('adminForm').classList.remove('hidden');
  field('loginStatus').textContent = '';
  field('err').classList.add('hidden');
  ['adminEmail', 'p'].forEach(id => field(id).readOnly = false);
  field('adminEmail').focus();
}

async function sendAdminOtp() {
  if (!validFields(['adminEmail', 'p'])) return;
  return adminAction(async () => {
    const result = await API.post('auth/login-owner', adminCredentials());
    ['adminEmail', 'p'].forEach(id => field(id).readOnly = true);
    showOtpStep(result.message + ' Wait 60 seconds before resending.');
  }, 'Could not send the sign-in code. Please try again.');
}
function submitAdminLogin() {
  return adminAction(async () => {
    if (firstRun) {
      // Step 1 needs only the per-install setup code. The owner supplies their own
      // email and password in step 2, so there are no credentials to collect here.
      await API.post('auth/setup-login', {secret: field('otp').value.trim().toUpperCase()});
      field('adminForm').classList.add('hidden');
      field('setupForm').classList.remove('hidden');
      field('setupStep').textContent = 'Step 2 of 3: Enter station and owner details';
      // The email/password fields are absent from the first-run form, so clear
      // whatever is actually present rather than assuming they exist.
      ['p', 'otp'].forEach(id => { const el = field(id); if (el) el.value = ''; });
      field('setupStation').focus();
    } else {
      // Step 2 only. The code box is deliberately not `required` in the
      // markup, so the browser will not block the step-1 form; it is checked
      // here so a bad entry is named instead of failing with a server error.
      const code = field('otp').value.trim();
      if (!code) throw new Error('Enter the 6-digit code from your inbox.');
      if (!/^[0-9]{6}$/.test(code)) throw new Error('The sign-in code is 6 digits.');
      finishAdmin(await API.post('auth/verify-email', {code}));
    }
  });
}
function sendSetupOtp() {
  if (!validFields(['setupStation', 'setupName', 'setupEmail', 'setupPassword', 'setupConfirm'])) return;
  return adminAction(async () => {
    const result = await API.post('auth/setup-owner', {station_name:field('setupStation').value.trim(), name:field('setupName').value.trim(), email:field('setupEmail').value.trim(), password:field('setupPassword').value, password_confirmation:field('setupConfirm').value});
    field('loginStatus').textContent = result.message + ' Wait 60 seconds before resending.';
    ['setupStation', 'setupName', 'setupEmail', 'setupPassword', 'setupConfirm'].forEach(id => field(id).readOnly = true);
    field('setupStep').textContent = 'Step 3 of 3: Verify your email to activate your store';
    field('setupOtp').focus();
  });
}
function verifySetup() { return adminAction(async () => finishAdmin(await API.post('auth/setup-verify', {code:field('setupOtp').value}))); }
