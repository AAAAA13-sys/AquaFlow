const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
function page(firstRun) {
  const elements = {};
  const element = id => elements[id] ||= {value:'',textContent:'',dataset:{firstRun:firstRun?'1':'0'},classList:{add(){},remove(){}},reportValidity:()=>true,focus(){},querySelectorAll:()=>[]};
  const calls=[];
  const ctx=vm.createContext({document:{getElementById:element},window:{location:{}},setSession(user){ctx.user=user;},API:{post:async(path,body)=>{calls.push({path,body}); return {message:'Code sent',user:{role:'admin'},recovery_codes:['saved-code']};}}});
  vm.runInContext(fs.readFileSync('public/js/auth/admin-login.js','utf8'),ctx);
  return {ctx,element,calls};
}
(async()=>{
  const normal=page(false);
  normal.element('adminEmail').value='owner@example.com'; normal.element('p').value='MyPassword123'; normal.element('otp').value='123456';
  await normal.ctx.sendAdminOtp();
  assert.equal(normal.calls[0].path,'auth/login-owner'); assert.equal(normal.calls[0].body.email,'owner@example.com'); assert.equal(normal.calls[0].body.username,undefined);
  await normal.ctx.submitAdminLogin(); assert.equal(normal.calls[1].path,'auth/verify-email'); assert.equal(normal.element('recoveryCodes').textContent,'saved-code'); assert.equal(normal.element('p').value,'');
  const setup=page(true);
  setup.element('setupStation').value='My Water Station';
  // First-run unlock takes the per-install setup code and nothing else: no email,
  // no password, and not the retired 'code' field.
  setup.element('otp').value='k7m2qx9b4tzp';
  await setup.ctx.submitAdminLogin();
  assert.equal(setup.calls[0].path,'auth/setup-login');
  assert.equal(setup.calls[0].body.secret,'K7M2QX9B4TZP');
  assert.equal(setup.calls[0].body.email,undefined);
  assert.equal(setup.calls[0].body.password,undefined);
  assert.equal(setup.calls[0].body.code,undefined);
  assert.equal(setup.ctx.user,undefined);
  setup.element('setupName').value='Owner'; setup.element('setupEmail').value='private@example.com'; setup.element('setupPassword').value='Private123'; setup.element('setupConfirm').value='Private123';
  await setup.ctx.sendSetupOtp(); assert.equal(setup.calls[1].path,'auth/setup-owner'); assert.equal(setup.calls[1].body.email,'private@example.com');
  assert.equal(setup.calls[1].body.station_name,'My Water Station');
  setup.element('setupOtp').value='123456'; await setup.ctx.verifySetup(); assert.equal(setup.calls[2].path,'auth/setup-verify'); assert.equal(setup.ctx.user.role,'admin');
  console.log('PASS: admin email payload, OTP verification, setup-code-only unlock, restricted setup flow and recovery code display.');
})().catch(error=>{console.error(error);process.exitCode=1;});
