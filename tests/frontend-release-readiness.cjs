const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const reminderBody = {innerHTML:'', querySelectorAll:()=>[]};
const reminderCtx = vm.createContext({DB:{inventory:[]}, document:{getElementById:id=>id==='topLiaBody'?reminderBody:null}});
vm.runInContext(fs.readFileSync('public/js/admin/insights.js','utf8'),reminderCtx);
Object.assign(reminderCtx, {computeInsights:()=>({custs:[{id:1,name:'Ana',contact:'09123456789',debt:100}],totalDebt:100}),
  filterTableRows:rows=>rows, esc:String, money:String, magBar:()=>'', showTableEmpty(){}});
reminderCtx.renderLedger();
assert.match(reminderBody.innerHTML, /class="action-message" disabled aria-disabled="true"/);
assert.match(reminderBody.innerHTML, /SMS reminders are unavailable for now/);
assert.ok(!reminderBody.innerHTML.includes('data-cust='), 'disabled reminder must not bind a messaging action');
const ctx = vm.createContext({DB:{transactions:[]}, restorePOSState(){}, window:{addEventListener(){}}, document:{getElementById(){return null;}}});
vm.runInContext(fs.readFileSync('public/js/pos/cart.js','utf8'),ctx);
ctx.esc=String; ctx.money=String;
ctx.POS.cart=[{name:'Round',price:35,q:2}];
const summary=ctx.cartRowsHtml(false);
assert.ok(!summary.includes('<button'), 'payment summary must have read-only quantities');
assert.ok(ctx.cartRowsHtml().includes('<button'), 'product step must keep quantity controls');
vm.runInContext(fs.readFileSync('public/js/pos/panels.js','utf8'),ctx);
assert.equal(ctx.historyVolumeLabel('1R'), '1 Round');
assert.equal(ctx.historyVolumeLabel('2S+3R'), '2 Slim, 3 Round');
assert.equal(ctx.historyVolumeLabel('0 gal'), '0');
vm.runInContext(fs.readFileSync('public/js/admin/inventory.js','utf8'),ctx);
ctx.DB.inventory=[{item:'Clear Cover',cat:'Consumable'},{item:'5 Gallon Slim Jug',cat:'Asset'},{item:'Filter Cartridge',cat:'Filtration'}];
assert.equal(ctx.stockFiltered().length,2,'dashboard excludes unrelated equipment');
vm.runInContext("stockFilter = 'Asset'",ctx);
assert.equal(ctx.stockFiltered()[0].item,'5 Gallon Slim Jug');
console.log('PASS: editable product cart, read-only payment summary, readable jug quantities and dashboard jug stock filter.');

(async () => {
  const elements = {};
  const classes = () => ({add(){}, remove(){}, toggle(){}, contains(){return false;}});
  const el = id => elements[id] ||= {value:'', textContent:'', classList:classes(), focus(){}, reset(){}};
  let posted, writes = 0, changed = false;
  const flow = vm.createContext({console, document:{getElementById:el, querySelectorAll:()=>[], addEventListener(){}},
    getSession:()=>({must_change_password:true}), DB:{customers:[], users:[]}, POS:{}, window:{location:{reload(){changed=true;}}},
    API:{createCustomer:async data=>{writes++; posted=data; return {customer:{id:2,name:data.name}};}, replaceCustomer(){},
      createUser:async data=>{posted=data; return {user:{}};}, replaceUser(){}, get:async()=>({user:{name:'New Cashier',must_change_password:true}})},
    appConfirm:async()=>true, pickCust:async()=>{}, saveDB(){}, renderPOS(){}, showStageError(){}, validNewPassword:()=>true,
    setTimeout:callback=>callback()});
  for (const file of ['pos/checkout.js','admin/navigation.js','admin/settings.js','data/profile.js']) vm.runInContext(fs.readFileSync('public/js/'+file,'utf8'),flow);
  flow.renderUsers=()=>{}; flow.showStageError=()=>{};
  el('regName').value='Ana Santos'; el('regAddr').value='Test address';
  await flow.saveNewCustomer(); assert.equal(writes,0, 'missing mobile number prevents customer creation');
  el('regContact').value='09123456789'; await flow.saveNewCustomer();
  assert.equal(writes,1); assert.equal(posted.contact,'09123456789');
  el('usrName').value='New Cashier'; el('usrUsername').value='newcashier'; el('usrRole').value='cashier'; el('usrEmail').value='newcashier@example.com';
  await flow.createUser(); assert.equal('password' in posted,false,'server supplies the default password'); assert.equal('job_title' in posted,false);
  flow.guardAdmin=flow.guardCashier=()=>({name:'New Cashier',must_change_password:true});
  flow.startPortalClock=()=>{};
  flow.loadFromServer=async()=>{assert.fail('Onboarding must finish before loading business data');};
  assert.equal(await flow.bootCashierPortal(), null);
  assert.equal(await flow.bootAdminPortal('Dashboard'), null);
  flow.guardAdmin=flow.guardCashier=()=>({name:'New Cashier'});
  flow.loadFromServer=async()=>{const error=new Error('Password change required');error.data={code:'password_change_required'};throw error;};
  assert.equal(await flow.bootCashierPortal(), null,'server password-change flag overrides stale local session');
  assert.equal(await flow.bootAdminPortal('Dashboard'), null);
  assert.equal(el('profileCloseButton').hidden,true,'first-login password change cannot be dismissed');
  flow.closeProfileModal(); assert.match(el('profileError').textContent,/temporary password/);
  await flow.saveProfile({preventDefault(){}}); assert.match(el('profileError').textContent,/new password/);
  el('profileCurrentPasswordInput').value='12345678'; el('profileNewPasswordInput').value='ChangedPass123'; el('profileConfirmPasswordInput').value='ChangedPass123';
  flow.API.request=async()=>({user:{name:'New Cashier',must_change_password:false}});
  await flow.saveProfile({preventDefault(){}}); assert.equal(changed,true,'finishing onboarding reloads the working portal');
  console.log('PASS: required customer mobile, default-password payload and mandatory first-login profile flow.');
})().catch(error=>{console.error(error);process.exitCode=1;});
