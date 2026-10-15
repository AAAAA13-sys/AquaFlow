const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

function page(firstRun) {
  const elements = {};
  const element = id => elements[id] ||= {
    value: '', textContent: '', dataset: {firstRun: firstRun ? '1' : '0'},
    classList: {add(){}, remove(){}}, reportValidity: () => true, focus(){}, querySelectorAll: () => []
  };
  const calls = [];
  const ctx = vm.createContext({
    document: {getElementById: element},
    window: {location: {href: ''}},
    setSession(user) { ctx.user = user; },
    API: {
      post: async (path, body) => {
        calls.push({path, body});
        return {user: {role: 'admin'}};
      }
    }
  });
  vm.runInContext(fs.readFileSync('public/js/auth/admin-login.js', 'utf8'), ctx);
  return {ctx, element, calls};
}

(async () => {
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
  assert.equal(setup.calls[1].body.email, null);
  assert.equal(setup.calls[1].body.password_confirmation, 'Private123');
  assert.equal(setup.ctx.user.role, 'admin');

  console.log('PASS: owner signs in with username and password in one call; setup takes the install code then the owner details, with no emailed code.');
})().catch(error => { console.error(error); process.exitCode = 1; });
