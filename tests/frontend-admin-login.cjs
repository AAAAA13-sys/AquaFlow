const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

function page(firstRun, employee = false) {
  const elements = {};
  const element = id => elements[id] ||= {
    value: '', textContent: '', dataset: {firstRun: firstRun ? '1' : '0'},
    classList: {add(){}, remove(){}}, reportValidity: () => true, focus(){}, querySelectorAll: () => []
  };
  const calls = [];
  const timers = new Map();
  let timerId = 0;
  element('forgotPasswordForm').querySelectorAll = () => [element('btnResendOtp')];
  const ctx = vm.createContext({
    setInterval: () => 1,
    clearInterval() {},
    setTimeout(callback, delay) { const id = ++timerId; timers.set(id, {callback, delay}); return id; },
    clearTimeout(id) { timers.delete(id); },
    document: {getElementById: id => employee && id === 'adminEmail' ? null : element(id)},
    window: {location: {href: ''}},
    setSession(user) { ctx.user = user; },
    API: {
      post: async (path, body) => {
        calls.push({path, body});
        return {user: {role: 'admin'}};
      }
    }
  });
  vm.runInContext(fs.readFileSync('public/js/auth/auth.js', 'utf8'), ctx);
  ctx.setSession = user => { ctx.user = user; };
  vm.runInContext(fs.readFileSync('public/js/auth/password-recovery.js', 'utf8'), ctx);
  if (!employee) vm.runInContext(fs.readFileSync('public/js/auth/admin-login.js', 'utf8'), ctx);
  return {ctx, element, calls, timers};
}

(async () => {
  const failed = page(false);
  const visibility = new Set(['hidden']);
  failed.element('err').classList = {add:value=>visibility.add(value), remove:value=>visibility.delete(value)};
  failed.ctx.API.post = async () => { throw new Error('Invalid username or password.'); };
  await failed.ctx.sendAdminOtp();
  assert.equal(failed.element('err').textContent, 'Invalid username or password.');
  assert.equal(visibility.has('hidden'), false);
  assert.equal([...failed.timers.values()][0].delay, 5000);
  failed.ctx.showLoginError('Try again.');
  assert.equal(failed.timers.size, 1, 'a new error resets the five-second timer');
  [...failed.timers.values()][0].callback();
  assert.equal(visibility.has('hidden'), true);
  // ---- Owner sign-in: username + password, one step -------------------------
  const normal = page(false);
  normal.element('adminEmail').value = 'owner';
  normal.element('p').value = 'MyPassword123';
  await normal.ctx.sendAdminOtp();

  assert.equal(normal.calls[0].path, 'auth/login-owner');
  assert.equal(normal.calls[0].body.username, 'owner');
  assert.equal(normal.calls[0].body.password, 'MyPassword123');
  // No email, and no second call for a code: the session opens on the response.
  assert.equal(normal.calls[0].body.email, undefined);
  assert.equal(normal.calls.length, 1);
  assert.equal(normal.ctx.user.role, 'admin');
  assert.equal(normal.ctx.window.location.href, '/admin');

  // ---- First run: setup code, then the owner's own details -------------------
  const setup = page(true);
  setup.element('otp').value = 'k7m2qx9b4tzp';
  await setup.ctx.submitAdminLogin();

  assert.equal(setup.calls[0].path, 'auth/setup-login');
  assert.equal(setup.calls[0].body.secret, 'K7M2QX9B4TZP');
  assert.equal(setup.calls[0].body.email, undefined);
  assert.equal(setup.calls[0].body.password, undefined);
  assert.equal(setup.calls[0].body.code, undefined);
  assert.equal(setup.ctx.user, undefined);

  setup.element('setupEmail').value = 'owner@example.com';
  setup.element('setupStation').value = 'My Water Station';
  setup.element('setupName').value = 'Owner';
  setup.element('setupUsername').value = 'owner';
  setup.element('setupPassword').value = 'Private123';
  setup.element('setupConfirm').value = 'Private123';
  await setup.ctx.verifySetup();

  // One call finishes setup. There is no OTP request and no verification call.
  assert.equal(setup.calls.length, 2);
  assert.equal(setup.calls[1].path, 'auth/setup-owner');
  assert.equal(setup.calls[1].body.username, 'owner');
  assert.equal(setup.calls[1].body.station_name, 'My Water Station');
  assert.equal(setup.calls[1].body.email, 'owner@example.com');
  assert.equal(setup.calls[1].body.password_confirmation, 'Private123');
  assert.equal(setup.ctx.user.role, 'admin');

  // ---- Forgot password flow: request OTP then verify & reset ---------------
  const forgot = page(false);
  forgot.ctx.showForgotPasswordForm();
  forgot.element('forgotEmail').value = 'admin@example.com';
  await forgot.ctx.submitForgotEmail();

  assert.equal(forgot.calls[0].path, 'auth/forgot-password/send-otp');
  assert.equal(forgot.calls[0].body.email, 'admin@example.com');
  assert.equal(forgot.element('err').textContent, '');
  assert.equal(forgot.element('btnResendOtp').disabled, true);

  // User can click "Back to enter email" if they mistyped email
  forgot.ctx.backToEnterEmail();
  assert.equal(forgot.element('forgotOtp').value, '');

  // Proceed with valid email
  forgot.element('forgotEmail').value = 'admin@example.com';
  await forgot.ctx.submitForgotEmail();

  forgot.element('forgotOtp').value = '123456';
  await forgot.ctx.submitVerifyOtp();

  assert.equal(forgot.calls[2].path, 'auth/forgot-password/verify-otp');
  assert.equal(forgot.calls[2].body.email, 'admin@example.com');
  assert.equal(forgot.calls[2].body.otp, '123456');

  forgot.element('forgotNewPassword').value = 'NewPass123';
  forgot.element('forgotConfirmPassword').value = 'NewPass123';
  await forgot.ctx.submitResetPassword();

  assert.equal(forgot.calls[3].path, 'auth/forgot-password/verify-reset');
  assert.equal(forgot.calls[3].body.email, 'admin@example.com');
  assert.equal(forgot.calls[3].body.otp, '123456');
  assert.equal(forgot.calls[3].body.password, 'NewPass123');
  assert.equal(forgot.calls[3].body.password_confirmation, 'NewPass123');

  const employee = page(false, true);
  employee.ctx.showForgotPasswordForm();
  employee.element('forgotEmail').value = 'cashier@example.com';
  await employee.ctx.submitForgotEmail();
  employee.element('forgotOtp').value = '123456';
  await employee.ctx.submitVerifyOtp();
  employee.element('forgotNewPassword').value = 'Private123!';
  employee.element('forgotConfirmPassword').value = 'Private123!';
  await employee.ctx.submitResetPassword();
  assert.equal(employee.calls.length, 3);
  assert.equal(employee.calls[2].body.email, 'cashier@example.com');
  assert.equal(employee.ctx.window.location.href, '', 'Recovery must not sign in or grant a role');
  console.log('PASS: owner signs in with username and password in one call; setup takes the install code then the owner details; forgot password requests OTP and resets password.');
})().catch(error => { console.error(error); process.exitCode = 1; });
