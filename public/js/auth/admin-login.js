// Admin authentication and first-run setup share the email verification UI.
const firstRun = document.getElementById('adminLogin').dataset.firstRun === '1';
let adminBusy = false;
const field = id => document.getElementById(id);
function adminCredentials() { return {email: field('adminEmail').value.trim(), password: field('p').value}; }
function validFields(ids) { return ids.every(id => field(id).reportValidity()); }
async function adminAction(action) {
  if (adminBusy) return;
  adminBusy = true;
  field('err').classList.add('hidden');
  field('loginStatus').textContent = '';
  const buttons = [...field('adminLogin').querySelectorAll('button')];
  buttons.forEach(button => button.disabled = true);
  try { await action(); }
  catch (error) { field('err').textContent = error.message; field('err').classList.remove('hidden'); }
  finally { adminBusy = false; buttons.forEach(button => button.disabled = false); }
}
function finishAdmin(result) {
  setSession(result.user);
  ['adminForm', 'setupForm'].forEach(id => field(id)?.classList.add('hidden'));
  ['p', 'setupPassword', 'setupConfirm', 'otp', 'setupOtp'].forEach(id => { if (field(id)) field(id).value = ''; });
  field('loginDescription').classList.add('hidden');
  if (result.recovery_codes?.length) {
    field('recoveryCodes').textContent = result.recovery_codes.join('\n');
    field('recoverySetup').classList.remove('hidden');
  } else window.location.href = '/admin';
}
function sendAdminOtp() {
  if (!validFields(['adminEmail', 'p'])) return;
  return adminAction(async () => {
    const result = await API.post('auth/login-owner', adminCredentials());
    ['adminEmail', 'p'].forEach(id => field(id).readOnly = true);
    field('loginStatus').textContent = result.message + ' Wait 60 seconds before resending.';
    field('otp').focus();
  });
}
function submitAdminLogin() {
  return adminAction(async () => {
    if (firstRun) {
      await API.post('auth/setup-login', {...adminCredentials(), code:field('otp').value});
      field('adminForm').classList.add('hidden');
      field('setupForm').classList.remove('hidden');
      field('p').value = ''; field('otp').value = '';
      field('setupName').focus();
    } else finishAdmin(await API.post('auth/verify-email', {code:field('otp').value}));
  });
}
function sendSetupOtp() {
  if (!validFields(['setupName', 'setupEmail', 'setupPassword', 'setupConfirm'])) return;
  return adminAction(async () => {
    const result = await API.post('auth/setup-owner', {name:field('setupName').value.trim(), email:field('setupEmail').value.trim(), password:field('setupPassword').value, password_confirmation:field('setupConfirm').value});
    field('loginStatus').textContent = result.message + ' Wait 60 seconds before resending.';
    ['setupName', 'setupEmail', 'setupPassword', 'setupConfirm'].forEach(id => field(id).readOnly = true);
    field('setupOtp').focus();
  });
}
function verifySetup() { return adminAction(async () => finishAdmin(await API.post('auth/setup-verify', {code:field('setupOtp').value}))); }
