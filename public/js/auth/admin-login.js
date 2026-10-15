// Admin sign-in and first-run setup.
//
// Sign-in is one step: a username and a password, the same credentials any other
// account uses. It used to be two - credentials, then a six-digit code emailed
// to the address on file. The code is gone, so there is no second form to swap
// in, no resend cooldown, and no recovery-code screen.
const firstRun = document.getElementById('adminLogin').dataset.firstRun === '1';
let adminBusy = false;
const field = id => document.getElementById(id);
function adminCredentials() { return {username: field('adminEmail').value.trim(), password: field('p').value}; }
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
    const message = fallbackMessage || error.message;
    field('err').textContent = message;
    field('err').classList.remove('hidden');
  }
  finally { adminBusy = false; buttons.forEach(button => button.disabled = false); }
}
function finishAdmin(result) {
  setSession(result.user);
  if (firstRun) field('setupStep').textContent = 'Setup complete. Your station and owner account are ready.';
  ['adminForm', 'setupForm'].forEach(id => field(id)?.classList.add('hidden'));
  ['p', 'setupPassword', 'setupConfirm', 'otp'].forEach(id => { if (field(id)) field(id).value = ''; });
  field('loginDescription').classList.add('hidden');
  window.location.href = '/admin';
}

/** Ordinary owner sign-in: post the credentials and go. */
async function sendAdminOtp() {
  if (!validFields(['adminEmail', 'p'])) return;
  return adminAction(async () => {
    finishAdmin(await API.post('auth/login-owner', adminCredentials()));
  }, 'Could not sign in. Please try again.');
}

/** First run: the setup code alone, then the owner's own details. */
function submitAdminLogin() {
  return adminAction(async () => {
    await API.post('auth/setup-login', {secret: field('otp').value.trim().toUpperCase()});
    field('adminForm').classList.add('hidden');
    field('setupForm').classList.remove('hidden');
    field('setupStep').textContent = 'Step 2 of 2: Enter station and owner details';
    field('setupStation').focus();
  });
}

/** Final setup step. There is no code to return, so this signs the owner straight in. */
function verifySetup() {
  if (!validFields(['setupStation', 'setupName', 'setupUsername', 'setupPassword', 'setupConfirm'])) return;
  return adminAction(async () => {
    finishAdmin(await API.post('auth/setup-owner', {
      station_name: field('setupStation').value.trim(),
      name: field('setupName').value.trim(),
      username: field('setupUsername').value.trim(),
      email: field('setupEmail')?.value.trim() || null,
      password: field('setupPassword').value,
      password_confirmation: field('setupConfirm').value
    }));
  });
}
