/**
 * Unified AquaFlow Frontend Test Suite
 * Consolidates all 24 frontend test modules into a single entry point.
 */
// =====================================================================
// SUITE: frontend-cash-drawer.cjs
// =====================================================================
(() => {
const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm');
(async () => {
 const elements = {};
 for (const id of ['cashDrawerPanel','cashHistory','cashOverview','cashStatus','cashOpenForm','cashCloseForm','cashResultCard','cashSummary','cashResult','cashFloat','cashCounted','cashNote','cashOpenBtn','cashCloseBtn']) elements[id] = {value:'',disabled:false,dataset:{overview:'0'},innerHTML:'',textContent:'',classList:{hidden:false,add(){this.hidden=true;},remove(){this.hidden=false;}}};
 let history=[]; let shift={id:42,is_open:true,expected_cash:200,opening_float:200,total_sales:0,collections:0,pending_collections:0,date:'2026-10-21',cashier:'<Cashier>',status:'open'},fail=false,writes=0,body,resolveConfirm,pending=null;
 const ctx=vm.createContext({document:{getElementById:id=>elements[id]},money:String,esc:s=>String(s).replaceAll('<','&lt;'),Submissions:{pending:()=>pending},appNotice(){},appConfirm:()=>new Promise(r=>resolveConfirm=r),API:{get:async()=>{if(fail)throw Error('offline');return {shift,history,shifts:[shift]};},post:async(path,payload)=>{writes++;body=payload;}}});
 vm.runInContext(fs.readFileSync('public/js/admin/cash.js','utf8'),ctx);
 await ctx.renderCashShift();assert.match(elements.cashSummary.innerHTML,/&lt;Cashier>/);
 elements.cashCounted.value='200';pending={};await ctx.closeCashDrawer();assert.equal(writes,0);pending=null;
 const close=ctx.closeCashDrawer();await ctx.closeCashDrawer();resolveConfirm(true);await close;assert.equal(writes,1);assert.equal(body.shift_id,42);assert.equal(body.expected_cash,200);
 shift={...shift,is_open:false,status:'balanced'};history=[shift,{...shift,id:41,cashier:'Earlier cashier'}];await ctx.renderCashShift();assert.doesNotMatch(elements.cashHistory.innerHTML,/&lt;Cashier>/);assert.match(elements.cashHistory.innerHTML,/Earlier cashier/);assert.equal(elements.cashOpenForm.classList.hidden,true);assert.equal(elements.cashCloseForm.classList.hidden,true);
 elements.cashFloat.value='NaN';await ctx.openCashDrawer();assert.equal(writes,1);
 elements.cashDrawerPanel.dataset.overview='1';
 elements.cashOverviewSearch={value:''};elements.cashOverviewStatus={value:''};elements.cashOverviewSort={value:'newest'};
 await ctx.renderCashShift();
 assert.match(elements.cashOverview.innerHTML,/<table/,'Management reviews drawers in a table');
 assert.equal((elements.cashOverview.innerHTML.match(/data-shift-id="42"/g)||[]).length,1,'Current/history duplicates appear once');
 assert.match(elements.cashOverview.innerHTML,/Earlier cashier/);
 assert.match(elements.cashOverview.innerHTML,/&lt;Cashier>/,'Operator names remain escaped');
 elements.cashOverview.innerHTML='Expanded notes DOM';await ctx.renderCashShift();
 assert.equal(elements.cashOverview.innerHTML,'Expanded notes DOM','Unchanged refresh preserves details and focus');
 elements.cashOverviewSearch.value='missing-person';ctx.renderCashOverviewRows();
 assert.match(elements.cashOverview.innerHTML,/No drawers match/);
 elements.cashOverviewSearch.value='';elements.cashOverviewStatus.value='open';ctx.renderCashOverviewRows();
 assert.match(elements.cashOverview.innerHTML,/No drawers match/,'Closed drawers are excluded by open filter');
 fail=true;await ctx.renderCashShift();assert.match(elements.cashStatus.textContent,/outdated/);assert.equal(elements.cashCloseForm.classList.hidden,true);
 console.log('PASS: cash drawer identity, reviewed totals, pending-payment guard, duplicate clicks, closed controls and offline state.');
})().catch(error=>{console.error(error);process.exitCode=1;});

})();

// =====================================================================
// SUITE: frontend-role-workspaces.cjs
// =====================================================================
(() => {
const fs=require('node:fs'), vm=require('node:vm'), assert=require('node:assert/strict');
const grid={innerHTML:''}; let role='admin';
const ctx=vm.createContext({document:{getElementById:id=>id==='tillGrid'?grid:null},getSession:()=>({role}),DB:{products:[{id:1,name:'Refill',cat:'refill',price:35,stock:10}]},esc:String,filterTableRows:rows=>rows});
vm.runInContext(fs.readFileSync('public/js/admin/till.js','utf8'),ctx);
ctx.renderTill(); assert.doesNotMatch(grid.innerHTML,/<input|<button/); assert.match(grid.innerHTML,/35.00/);
role='super_admin';ctx.renderTill();assert.match(grid.innerHTML,/saveTillPrice/);assert.match(grid.innerHTML,/deleteTillItem/);
(async()=>{
 const children=[]; const status={textContent:''}; let riders=[];
 const create=tag=>({tag,style:{},elements:{quantity:{}},append(...nodes){this.children=(this.children||[]).concat(nodes);}});
 const root={dataset:{},innerHTML:'',replaceChildren(){children.length=0;},append(n){children.push(n);}};
 const c=vm.createContext({document:{hidden:false,activeElement:null,addEventListener(){},createElement:create,getElementById:id=>id==='fulfillmentRows'?root:status},window:{addEventListener(){}},setInterval(){},esc:String,money:String,sessionStorage:{getItem:()=>null},getSession:()=>({u:'cashier'}),API:{request:async()=>({updated_at:new Date().toISOString(),riders,orders:[{id:1,receipt:'OR-1',customer:'Guest',status:'ready',description:'1 refill',issues:[],order_type:'Delivery',rider_id:null,handed_over_at:null}]})}});
 vm.runInContext(fs.readFileSync('public/js/fulfillment.js','utf8'),c);await c.refreshFulfillment();
 assert.ok(children[0].children.some(n=>n.tag==='form'),'Ready unassigned delivery must allow cashier handover before rider dispatch');
 riders=[{id:7,name:'Rider One'}]; await c.refreshFulfillment();
 const assignment=children[0].children.find(n=>n.tag==='form' && n.children?.some(child=>child.tag==='button'));
 assert.equal(assignment,undefined,'Manual rider assignment is not offered');
 root.dataset.mode='monitor';await c.refreshFulfillment();
 assert.match(root.innerHTML,/<table/,'Management receives a monitoring table');
 assert.doesNotMatch(root.innerHTML,/<form|sendFulfillmentAction|acceptDeliveryRemittance|confirmation code/,'Monitoring has no operational controls or buyer secrets');
 console.log('PASS: owner-only catalog controls and unassigned delivery handover.');
})().catch(e=>{console.error(e);process.exitCode=1;});

})();

// =====================================================================
// SUITE: frontend-forecast.cjs
// =====================================================================
(() => {
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const elements = {fcTable: {}, forecastInsights: {}};
const record = {history: Array(30).fill(25), forecast: Array(7).fill(25), model: {method: 'arima', mape: 8.5}};
const ctx = vm.createContext({document: {getElementById: id => elements[id] || null}, DB: {forecasts: {'Refill Jugs': record}}, sum: values => values.reduce((a,b)=>a+b,0), esc: String});
vm.runInContext(fs.readFileSync(path.join(__dirname,'../public/js/admin/arima.js'),'utf8'),ctx);
ctx.renderForecast();
assert.match(elements.forecastInsights.innerHTML, /8.50%/);
assert.match(elements.forecastInsights.innerHTML, /91.50%/);
assert.match(elements.forecastInsights.innerHTML, /Target met/);
assert.ok(!elements.fcTable.innerHTML.includes('How reliable'));
record.model.mape = 10;
ctx.renderForecast();
assert.match(elements.forecastInsights.innerHTML, /Target not met/);
record.model.mape = null;
ctx.renderForecast();
assert.match(elements.forecastInsights.innerHTML, /Not validated/);
assert.ok(!elements.forecastInsights.innerHTML.includes('100.00%'));
record.forecast = [];
ctx.renderForecast();
assert.equal(elements.forecastInsights.innerHTML, '');
console.log('PASS: forecast metrics show measured accuracy, strict targets and unavailable validation; insights render separately.');

(async () => {
  record.series = 'Refill Jugs';
  record.forecast = Array(7).fill(25);
  record.model = {method: 'arima_seasonal', order: 'ARIMA(0,0,1)', mape: 8, diagnostics: {data_source: 'synthetic_demo'}};
  ctx.DB.inventory = [];
  ctx.DB.advisories = [];
  ctx.renderForecast();
  assert.doesNotMatch(elements.forecastInsights.innerHTML, /Synthetic demo data|tailored example|Updating the forecast uses recorded sales/);
  elements.chArima = {toDataURL: () => 'data:image/png;base64,chart'};
  let html = '', decoded = false, printed = false, closed = false;
  const popup = {document: {write: value => {html=value;}, close() {}, images: [{decode: async () => {decoded=true;}}]}, focus() {}, print() {assert.equal(decoded,true);printed=true;}, close() {closed=true;}};
  ctx.window = {open: () => popup};
  ctx.reportSales = async () => [{no:'OR-1',date:'2026-10-16',cust:'Sample customer',total:35,pay:'Cash'}];
  ctx.appNotice = message => {ctx.lastNotice=message;};
  await ctx.exportReportPDF();
  assert.equal(printed, true);
  for (const section of ['Model validation','Demand outlook','Insights and actions','Seven-day forecast','Historical demand','Inventory','Restock advisories','Sales','Presentation dataset','92.00%','data:image/png;base64,chart']) assert.ok(html.includes(section), section);
  printed=false;
  ctx.reportSales = async () => {throw new Error('Sales unavailable');};
  await ctx.exportReportPDF();
  assert.equal(printed,false);
  assert.equal(closed,true);
  assert.equal(ctx.lastNotice,'Sales unavailable');
  ctx.window.open = () => null;
  await ctx.exportReportPDF();
  assert.match(ctx.lastNotice,/Allow popups/);
  console.log('PASS: full printable report includes chart, metrics, insights, history and datasets; waits for images and handles failures.');
})().catch(error=>{console.error(error);process.exitCode=1;});

})();

// =====================================================================
// SUITE: frontend-release-readiness.cjs
// =====================================================================
(() => {
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
assert.equal(ctx.historyVolumeLabel('1R'), '1 × Round 5-Gal Refill');
assert.equal(ctx.historyVolumeLabel('2S+3R'), '2 × Slim 5-Gal Refill, 3 × Round 5-Gal Refill');
assert.equal(ctx.historyVolumeLabel('0 gal'), '—');
assert.equal(ctx.historyVolumeLabel('2 × New Slim 5-Gal Jug'), '2 × New Slim 5-Gal Jug');
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
    roleLabel:role=>role, getSession:()=>({must_change_password:true}), DB:{customers:[], users:[]}, POS:{}, window:{location:{reload(){changed=true;}}},
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

})();

// =====================================================================
// SUITE: frontend-users.cjs
// =====================================================================
(() => {
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const elements = {};
const el = id => elements[id] ||= {value:'',disabled:false,classList:{add(){},remove(){},toggle(){}},reportValidity:()=>true,reset(){}};
let resolveRequest, rejectRequest, creates=0, updates=0, payload;
const deferred = () => new Promise((resolve,reject)=>{resolveRequest=resolve;rejectRequest=reject;});
const ctx = vm.createContext({document:{getElementById:el},validNewPassword:()=>true,saveDB(){},DB:{users:[]},API:{
  createUser(data){creates++;payload=data;return deferred();},updateUser(id,data){updates++;payload=data;return deferred();},replaceUser(){}
}});
vm.runInContext(fs.readFileSync('public/js/auth/auth.js','utf8'),ctx);
vm.runInContext(fs.readFileSync('public/js/admin/settings.js','utf8'),ctx);
ctx.renderUsers=()=>{};
(async()=>{
  el('usrName').value='Cashier';el('usrUsername').value='staff';el('usrPassword').value='Password123';el('usrRole').value='cashier';el('usrEmail').value='staff@example.com';
  const first=ctx.createUser({preventDefault(){}});const duplicate=ctx.createUser({preventDefault(){}});
  assert.equal(creates,1,'Repeated create must not submit a second request');
  await duplicate;
  assert.equal(el('userCreateButton').disabled,true);
  assert.equal('pin' in payload,false);
  rejectRequest(Error('Cannot save'));await first;
  assert.equal(el('userCreateButton').disabled,false);assert.equal(el('usrUsername').value,'staff');
  const retry=ctx.createUser();assert.equal(creates,2);resolveRequest({user:{}});await retry;
  el('usrEditName').value='Cashier';el('usrEditRole').value='cashier';el('usrEditEmail').value='staff@example.com';
  const save=ctx.saveUserEdit(1);const duplicateSave=ctx.saveUserEdit(1);
  assert.equal(updates,1,'Repeated save must not submit a second request');
  await duplicateSave;
  assert.equal(el('userSaveButton').disabled,true);assert.equal('pin' in payload,false);
  rejectRequest(Error('Cannot save'));await save;
  assert.equal(el('userSaveButton').disabled,false);
  const saveRetry=ctx.saveUserEdit(1);assert.equal(updates,2);resolveRequest({user:{}});await saveRetry;
  el('usrRole').value='admin'; ctx.updateUserRoleFields();
  assert.equal(el('usrPassword').required,true);
  assert.equal(el('usrPassword').disabled,false);
  el('usrPassword').value='12345678';
  await ctx.createUser(); assert.equal(creates,2,'Admin cannot use the cashier default');
  el('usrPassword').value='Private123!';
  const admin=ctx.createUser(); assert.equal(payload.password,'Private123!');
  resolveRequest({user:{}}); await admin;
  el('usrRole').value='cashier'; ctx.updateUserRoleFields();
  assert.equal(el('usrPassword').disabled,true);
  assert.equal(el('usrPassword').value,'');
  el('usrEmail').value=''; await ctx.createUser(); assert.equal(creates,3,'Cashier email is mandatory');
  console.log('PASS: account create/save duplicate guards, retry after failure and no legacy PIN payload.');
})().catch(error=>{console.error(error);process.exitCode=1;});

})();

// =====================================================================
// SUITE: frontend-csv.cjs
// =====================================================================
(() => {
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');

(async () => {
  const root = path.resolve(__dirname, '..');
  const downloads = [];
  const ctx = vm.createContext({ window: {}, document: {getElementById: () => null, addEventListener() {}},
    filterTableRows: rows => rows, appNotice: message => {throw new Error(message);},
    DB: {transactions: [], inventory: [], advisories: []} });
  for (const file of ['data/store.js', 'admin/sales.js', 'pos/panels.js', 'admin/arima.js', 'admin/insights.js']) {
    vm.runInContext(fs.readFileSync(path.join(root, 'public/js', file), 'utf8'), ctx);
  }
  ctx.downloadCSV = (csv, filename) => downloads.push({csv, filename});
  const row = {no: 'OR-1', date: '2026-10-11', t: '08:00', cust: '=1+1', type: 'Walk-in', gal: '1S', total: 35, pay: 'Cash', by: '+SUM(1,2)'};
  ctx.fetchSalesRows = ctx.historyRows = ctx.reportSales = async () => [row];
  await ctx.exportSalesCSV();
  await ctx.exportHistoryCsv();
  for (const {csv} of downloads) {
    assert.ok(csv.includes(",'=1+1,"), 'Customer formula must be exported as text');
    assert.ok(csv.includes('"\'+SUM(1,2)"'), 'Cashier formula must be quoted and exported as text');
  }
  for (const value of ['=1+1', '+1', '-1', '@SUM(A1)', '\t=1', '\r=1', '\n=1', '  =1']) {
    assert.ok(ctx.csvCell(value).includes("'"), 'Unsafe prefix: ' + JSON.stringify(value));
  }
  assert.equal(ctx.csvCell(-12.5), '-12.5');
  assert.equal(ctx.csvCell(null), '');
  assert.equal(ctx.csvCell('Supplier, "A"\nBranch'), '"Supplier, ""A""\nBranch"');
  ctx.DB.advisories = [{item: '=1+1', order_quantity: 4, order_qty: 4, supplier: 'Supplier, "A"', reorder_at: 2}];
  ctx.draftPO();
  assert.ok(downloads.at(-1).csv.includes("'=1+1,4,\"Supplier, \"\"A\"\"\""));
  ctx.DB.inventory = [{item: '=1+1', on: 2, unit: 'pcs', ss: 1, rop: 2, supplier: 'Supplier, "A"'}];
  ctx.currentSeriesRecord = () => ({series: '=1+1', history: [-2], forecast: [3], model: {order: 'ARIMA(1,0,1)'}});
  await ctx.exportReport();
  assert.ok(downloads.at(-1).csv.includes("# Series,'=1+1"));
  assert.ok(downloads.at(-1).csv.includes('# Model,"ARIMA(1,0,1)"'));
  assert.ok(downloads.at(-1).csv.includes("'=1+1,2,pcs,1,2,"));
  assert.ok(downloads.at(-1).csv.includes(",'=1+1,35,Cash"));
  console.log('PASS: all four CSV exports escape columns and neutralize spreadsheet formulas; numeric values stay numeric.');
})().catch(error => {console.error(error); process.exitCode = 1;});

})();

// =====================================================================
// SUITE: frontend-admin-login.cjs
// =====================================================================
(() => {
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
  await failed.ctx.submitLogin();
  assert.equal(failed.element('err').textContent, 'Invalid username or password.');
  assert.equal(visibility.has('hidden'), false);
  assert.equal(failed.timers.size, 0, 'Sign-in errors stay readable until the next action');
  failed.ctx.showLoginError('Try again.');
  assert.equal(failed.element('err').textContent, 'Try again.');
  assert.equal(visibility.has('hidden'), false);
  // ---- Owner sign-in: username + password, one step -------------------------
  const normal = page(false);
  normal.element('adminEmail').value = 'owner';
  normal.element('p').value = 'MyPassword123';
  await normal.ctx.submitLogin();

  assert.equal(normal.calls[0].path, 'auth/login');
  assert.equal(normal.calls[0].body.username, 'owner');
  assert.equal(normal.calls[0].body.password, 'MyPassword123');
  // No email, and no second call for a code: the session opens on the response.
  assert.equal(normal.calls[0].body.email, undefined);
  assert.equal(normal.calls.length, 1);
  assert.equal(normal.ctx.user.role, 'admin');
  assert.equal(normal.ctx.window.location.href, '/admin');

  for (const [role, destination] of [['super_admin', '/admin'], ['admin', '/admin'], ['cashier', '/cashier'], ['refiller', '/employee'], ['rider', '/employee']]) {
    const login = page(false);
    login.ctx.API.post = async (path, body) => {
      login.calls.push({path, body});
      return {user: {role}};
    };
    await login.ctx.submitLogin();
    assert.equal(login.calls[0].path, 'auth/login');
    assert.equal(login.ctx.window.location.href, destination, role + ' reaches its own workspace');
  }

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

})();

// =====================================================================
// SUITE: frontend-pos.cjs
// =====================================================================
(() => {
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const elements = {tender:{value:100}, debtPay:{value:50}};
let confirmed = false, pending = null, quote = {valid:true, additional:{slim:2}};
const ctx = vm.createContext({console, DB:{transactions:[], customers:[{id:1},{id:2}], products:[{id:'slim', name:'Refill', price:35}]},
  restorePOSState(){}, savePOSState(){}, saveDB(){}, document:{getElementById:id=>elements[id] || null}, window:{addEventListener(){}},
  API:{request:async()=>quote, customers:async()=>({customers:[]}), replaceCustomer(){}}, Submissions:{pending:()=>pending}, appConfirm:async()=>confirmed,
  showStageError(message){ctx.error=message;}, posStep(){}});
vm.runInContext(fs.readFileSync('public/js/pos/cart.js','utf8'),ctx);
ctx.renderPOS=()=>{};
(async()=>{
  elements.custSearch={value:''}; elements.custList={innerHTML:''};
  ctx.DB.customers=[{id:1,name:'Regular Customer',addr:'',debt:0},{id:2,name:'Renamed Guest',is_guest:true},{id:3,name:'Walk-in Guest'}];
  ctx.esc=value=>String(value); ctx.icon=()=>'';

  // --- The list is a server query, not a filter over the whole snapshot. -----
  let lastParams = null;
  ctx.API.customers = async params => {
    lastParams = params;
    // The server is responsible for excluding the synthetic guest; mirror that.
    return {customers:[{id:1,name:'Regular Customer',addr:'Blk 1',debt:0}]};
  };
  await ctx.searchCustomers();
  assert.equal(lastParams.exclude_guest, 1, 'the guest must be excluded server-side');
  assert.equal(lastParams.limit, 50, 'the terminal lists every customer available');
  assert.equal(lastParams.order, 'oldest', 'default ordering is oldest first');
  assert.equal(lastParams.q, '', 'empty search browses the first page');
  assert.ok(elements.custList.innerHTML.includes('Regular Customer'));
  assert.ok(!elements.custList.innerHTML.includes('Renamed Guest'));
  assert.ok(!elements.custList.innerHTML.includes('Walk-in Guest'));

  // --- The search term is forwarded. ----------------------------------------
  elements.custSearch.value = '  reyes  ';
  await ctx.searchCustomers();
  assert.equal(lastParams.q, 'reyes', 'term is trimmed before it is sent');

  // --- Newest-first ordering is forwarded. ----------------------------------
  elements.customerOrder = {value:'newest'};
  await ctx.searchCustomers();
  assert.equal(lastParams.order, 'newest');

  // --- An empty result shows an actionable message, not a blank panel. ------
  ctx.API.customers = async ()=>({customers:[]});
  await ctx.searchCustomers();
  assert.ok(elements.custList.innerHTML.includes('No customer matches'));

  // --- A failed request is surfaced instead of silently showing stale rows. --
  ctx.API.customers = async ()=>{ throw Error('offline'); };
  await ctx.searchCustomers();
  assert.ok(elements.custList.innerHTML.includes('Could not load customers'));

  // --- Out-of-order responses: the older one must not overwrite the newer. --
  let releaseFirst;
  const firstCall = new Promise(resolve => { releaseFirst = resolve; });
  let call = 0;
  ctx.API.customers = async () => {
    call += 1;
    if (call === 1) return firstCall;
    return {customers:[{id:9,name:'Second Query',addr:'X',debt:0}]};
  };
  elements.custSearch.value = 'first';
  const older = ctx.searchCustomers();
  elements.custSearch.value = 'second';
  await ctx.searchCustomers();
  releaseFirst({customers:[{id:8,name:'First Query',addr:'Y',debt:0}]});
  await older;
  assert.ok(elements.custList.innerHTML.includes('Second Query'), 'newer result kept');
  assert.ok(!elements.custList.innerHTML.includes('First Query'), 'stale response discarded');

  // Mock the manager approval boundary; cancellation must keep the draft.
  ctx.voidCart = async () => { if (!confirmed) return false; ctx.POS.cart = []; return true; };
  // --- Customer switching, draft reset and the pending-payment guard. -------
  ctx.API.customers = async ()=>({customers:[]});
  ctx.POS.custId=1; ctx.POS.cart=[{id:'slim',q:2}];
  assert.equal(await ctx.pickCust(2),false); assert.equal(ctx.POS.cart.length,1);
  confirmed=true;
  assert.equal(await ctx.pickCust(2),true); assert.equal(ctx.POS.cart.length,0); assert.equal(elements.tender.value,0);
  ctx.POS.cart=[{id:'slim',q:1}];
  assert.equal(await ctx.pickCust(2),true); assert.equal(ctx.POS.cart.length,1);
  pending={}; assert.equal(await ctx.pickCust(1),false); pending=null;

  // --- Stock increments and offline guards. --------------------------------
  await ctx.addQty(0,5); assert.equal(ctx.POS.cart[0].q,1);
  await ctx.addQty(0,1); assert.equal(ctx.POS.cart[0].q,2);
  quote={valid:false,additional:{slim:0}}; await ctx.addProduct('slim'); assert.equal(ctx.POS.cart[0].q,2);
  ctx.API.request=async()=>{throw Error('offline');}; await ctx.addQty(0,1); assert.equal(ctx.POS.cart[0].q,2);
  let retries = 0;
  ctx.stockSignature = ctx.cartSignature();
  await ctx.refreshStockPreview();
  ctx.API.request = async () => { retries++; return {valid:true, additional:{slim:4}, checked_at:new Date().toISOString()}; };
  ctx.scheduleStockPreview();
  await Promise.resolve();
  assert.equal(retries,1,'A failed stock check must be retryable for the unchanged cart');
  let changes = 0;
  confirmed = false;
  await ctx.confirmCartAction('Change quantity?', () => { changes++; });
  assert.equal(changes,0);
  confirmed = true;
  await ctx.confirmCartAction('Change quantity?', () => { changes++; });
  assert.equal(changes,1);
  console.log('PASS: server-driven customer search (guest exclusion, ordering, empty/offline states, stale-response guard) plus customer switch, draft reset, pending-payment guard and stock guards.');
})().catch(error=>{console.error(error);process.exitCode=1;});

})();

// =====================================================================
// SUITE: frontend-pos-confirmations.cjs
// =====================================================================
(() => {
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
(async()=>{
 let sent=0,opened=0,closed=0;
 const elements={tender:{value:100},debtPay:{value:20},registerModal:{open:false,showModal(){opened++;},close(){closed++;}},regName:{focus(){}},registrationError:{textContent:'old'}};
 const ctx=vm.createContext({document:{getElementById:id=>elements[id]||null,querySelectorAll:()=>[]},POS:{cart:[{id:'slim',q:1}],custId:1,type:'Walk-in'},DB:{},posChangeAllowed:()=>true,hasCustomer:()=>true,posCust:()=>({id:1,name:'Test',debt:50}),isDelivery:()=>false,posTotals:()=>({total:35}),money:String,appConfirm:async()=>false,API:{createTransaction(){sent++;},settleDebt(){sent++;}}});
 vm.runInContext(fs.readFileSync('public/js/pos/checkout.js','utf8'),ctx);
 await ctx.completeSale();await ctx.settleDebt();assert.equal(sent,0,'Cancelled confirmation cannot write financial records');
 ctx.openRegisterModal();ctx.closeRegisterModal();assert.equal(opened,1);assert.equal(closed,1);assert.equal(elements.registrationError.textContent,'');
 let loggedOut=0;ctx.logout=()=>{loggedOut++;};
 await ctx.confirmCashierLogout();await ctx.requestCloseRegisterModal();
 assert.equal(loggedOut,0);assert.equal(closed,1,'Rejecting cancellation keeps registration open');
 ctx.renderStage(2);
 await ctx.posPrev();
 assert.equal(ctx.getPosCur(),1,'Back returns directly to customer selection');
 ctx.renderStage(3);
 ctx.appConfirm=async()=>true;
 await ctx.posPrev();
 assert.equal(ctx.getPosCur(),2,'Back returns directly to products for order type changes');
 await ctx.confirmCashierLogout();await ctx.requestCloseRegisterModal();
 assert.equal(loggedOut,1);assert.equal(closed,2);
 console.log('PASS: native registration dialog, direct back navigation and cancelled sale/debt confirmations.');
})().catch(error=>{console.error(error);process.exitCode=1;});

})();

// =====================================================================
// SUITE: frontend-pos-settlement-request.cjs
// =====================================================================
(() => {
// A cashier's counter collection is a request, not a receipt.
//
// The POS must not print a receipt, must not move the customer's balance and
// must reserve the cash it just took - otherwise the same pesos could be
// collected twice before a manager approves the first collection.
//
// `showStageError` is defined in checkout.js itself and writes to the
// `posStageError` element, so the inline message is read from that node rather
// than captured from the context.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');

(async () => {
  const notices = [];
  let prints = 0, snapshots = 0, replaced = null;
  const elements = {
    debtPay: { value: '150' },
    posStageError: { textContent: '', classList: { add() {}, remove() {} } }
  };

  const ctx = vm.createContext({
    document: { getElementById: id => elements[id] || null, querySelectorAll: () => [] },
    POS: { cart: [], custId: 7, type: 'Walk-in' },
    DB: { customers: [] },
    posChangeAllowed: () => true,
    hasCustomer: () => true,
    posCust: () => ({
      id: 7, name: 'Santos Family', debt: 150,
      settlement_pending: 0, collectible: 150
    }),
    isDelivery: () => false,
    posTotals: () => ({ total: 0 }),
    money: n => 'P' + Number(n).toFixed(2),
    appConfirm: async () => true,
    appNotice: m => notices.push(m),
    saveDB: () => {},
    updateDebtPanel: () => {},
    renderPOS: () => {},
    reprint: () => { prints++; },
    API: {
      settleDebt: async () => ({
        replayed: false, applied: 0, pending_approval: true,
        message: 'Collection recorded. A manager must approve it before the customer\'s balance changes.',
        request: { id: 1, type: 'settlement.create', state: 'pending' },
        customer: { id: 7, name: 'Santos Family', debt: 150, settlement_pending: 150, collectible: 0 }
      }),
      replaceCustomer: c => { replaced = c; },
      replaceSnapshot: () => { snapshots++; }
    },
    Submissions: { clear: () => {} }
  });

  vm.runInContext(fs.readFileSync('public/js/pos/checkout.js', 'utf8'), ctx);

  await ctx.settleDebt();

  // The balance must not move, and no receipt may be issued for money a manager
  // has not yet approved.
  assert.equal(prints, 0, 'a pending collection must not print a receipt');
  assert.equal(snapshots, 0, 'a pending collection must not post a transaction snapshot');
  assert.equal(replaced.settlement_pending, 150, 'the reservation from the server must be reflected');
  assert.equal(replaced.collectible, 0, 'nothing is collectible while the collection waits');
  assert.ok(notices.some(n => /manager must approve/i.test(n)), 'the cashier must be told approval is required');
  assert.ok(!notices.some(n => /payment recorded|settled/i.test(n)), 'the debt must not be reported as settled');

  // With cash already reserved, the counter must refuse a second collection of
  // the same balance before it reaches the server.
  let calls = 0;
  ctx.posCust = () => ({ id: 7, name: 'Santos Family', debt: 150, settlement_pending: 150, collectible: 0 });
  ctx.API.settleDebt = async () => { calls++; return { pending_approval: true, customer: replaced }; };
  // The first collection zeroed the field; a fresh attempt must re-enter an
  // amount for the reservation guard to be what blocks it.
  elements.debtPay.value = '150';
  elements.posStageError.textContent = '';
  await ctx.settleDebt();
  assert.equal(calls, 0, 'a reserved balance must not be collected again');
  assert.match(elements.posStageError.textContent, /already waiting for a manager/i, 'the cashier must be told why the collection is blocked');

  console.log('PASS: cashier collections are reserved requests with no receipt, and a reserved balance cannot be collected twice.');
})().catch(error => { console.error(error); process.exitCode = 1; });

})();

// =====================================================================
// SUITE: frontend-queue.cjs
// =====================================================================
(() => {
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const elements={queue:{innerHTML:''},queueSearch:{value:''}};
const ctx=vm.createContext({document:{getElementById:id=>elements[id]},DB:{queue:[]},esc:s=>String(s??'').replaceAll('<','&lt;')});
vm.runInContext(fs.readFileSync('public/js/pos/panels.js','utf8'),ctx);
assert.equal(ctx.queueWaitLabel(7635),'5 d 7 hr');
assert.equal(ctx.queueWaitLabel(61),'1 hr 1 min');
assert.equal(ctx.queueWaitLabel(0),'0 min');
ctx.DB.queue=[{id:1,no:'OR-1',cust:'<Customer>',items:'6S',orderType:'Walk-in',fulfillment_status:'ready',mins:7635}];
ctx.renderQueuePage();assert.match(elements.queue.innerHTML,/5 d 7 hr/);assert.match(elements.queue.innerHTML,/&lt;Customer>/);assert.match(elements.queue.innerHTML,/Confirm handover/);
ctx.DB.queue[0].orderType='Delivery';ctx.renderQueuePage();assert.doesNotMatch(elements.queue.innerHTML,/Confirm handover/);assert.doesNotMatch(elements.queue.innerHTML,/Confirm delivery/);
ctx.DB.queue[0].completed=true;ctx.renderQueuePage();assert.doesNotMatch(elements.queue.innerHTML,/Waiting 5/);assert.match(elements.queue.innerHTML,/Handover time unavailable/);
console.log('PASS: readable queue ages, escaped customer names and cashier handover eligibility.');

ctx.DB.queue[0].completed=false;ctx.DB.queue[0].orderType='Walk-in';ctx.DB.queue[0].fulfillment_status='preparing';ctx.renderQueuePage();assert.doesNotMatch(elements.queue.innerHTML,/Confirm handover/);assert.match(elements.queue.innerHTML,/Waiting for preparation/);

})();

// =====================================================================
// SUITE: frontend-refresh.cjs
// =====================================================================
(() => {
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
(async()=>{
let timer,resolve, calls=0;const handlers={};
const ctx=vm.createContext({console,localStorage:{getItem(){return null;},setItem(){}},DB:{transactions:[]},document:{hidden:false,addEventListener(){}},window:{addEventListener:(name,fn)=>handlers[name]=fn},setInterval:fn=>{timer=fn;return 1;}});
vm.runInContext(fs.readFileSync('public/js/data/store.js','utf8'),ctx);
ctx.startPageRefresh(()=>{calls++;return new Promise(r=>resolve=r);});
const first=timer();await handlers.focus();assert.equal(calls,1);resolve();await first;
ctx.document.hidden=true;await timer();assert.equal(calls,1);
ctx.document.hidden=false;const online=handlers.online();assert.equal(calls,2);resolve();await online;
console.log('PASS: page synchronization pauses when hidden, prevents overlapping refreshes, and resumes on focus/reconnection.');
})().catch(error=>{console.error(error);process.exitCode=1;});

})();

// =====================================================================
// SUITE: frontend-cashier-stocks.cjs
// =====================================================================
(() => {
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const elements = {};
const el = id => elements[id] ||= {value:'',innerHTML:'',textContent:'',disabled:false};
let resolve, calls=0;
const ctx = vm.createContext({document:{getElementById:el}, esc:value=>String(value).replaceAll('<','&lt;').replaceAll('>','&gt;'),
  API:{inventory:()=>{calls++;return new Promise(r=>resolve=r);}}});
vm.runInContext(fs.readFileSync('public/js/pos/stocks.js','utf8'),ctx);
(async()=>{
  const first=ctx.loadCashierStocks();
  await ctx.loadCashierStocks();
  assert.equal(calls,1,'Manual and automatic refresh must not overlap');
  assert.equal(el('refreshStocksBtn').disabled,true);
  resolve({inventory:[{item:'<script>',on:0},{item:'Caps',on:2,needs_reorder:true}]});
  await first;
  assert.match(el('cStockBody').innerHTML,/Out of stock/);
  assert.match(el('cStockBody').innerHTML,/Low stock/);
  assert.doesNotMatch(el('cStockBody').innerHTML,/<script>/);
  ctx.API.inventory=async()=>{throw Error('offline');};
  await ctx.loadCashierStocks();
  el('cStockSearchInput').value='Caps';ctx.renderCashierStocksTable();
  assert.match(el('cStockBody').innerHTML,/Retry/,'Filtering must not resurrect stale stock after a failed refresh');
  assert.equal(el('refreshStocksBtn').disabled,false);
  ctx.API.inventory=async()=>({inventory:[]});await ctx.loadCashierStocks();
  assert.match(el('cStockBody').innerHTML,/No stock items/);
  console.log('PASS: cashier stock refresh, errors, retry, statuses and escaping.');
})().catch(e=>{console.error(e);process.exitCode=1;});

})();

// =====================================================================
// HELPER: testInventoryEditor (frontend-inventory-editor.cjs)
// =====================================================================
(() => {
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

globalThis.testInventoryEditor = root => {
  const body = {innerHTML: ''};
  const item = {id: 1, supplier_id: 10, supplier: 'Shared name', cat: 'Consumable'};
  const ctx = vm.createContext({
    DB: {inventory: [item], suppliers: [{id: 10, name: 'Shared name'}, {id: 20, name: 'Shared name'}]},
    document: {getElementById: id => id === 'invEditBody' ? body : {classList: {remove() {}}}},
    esc: value => String(value ?? '')
  });
  vm.runInContext(fs.readFileSync(root + '/public/js/admin/inventory.js', 'utf8'), ctx);
  ctx.openInventoryEditor(1);
  assert.equal((body.innerHTML.match(/ selected/g) || []).length, 2, 'Only category and the linked supplier should be selected');
  assert.ok(body.innerHTML.includes('value="10" selected'));
  assert.ok(!body.innerHTML.includes('value="20" selected'));
  item.supplier = 'Old name before supplier rename';
  ctx.openInventoryEditor(1);
  assert.ok(body.innerHTML.includes('value="10" selected'), 'Stale display name must not unlink the supplier');
  item.supplier_id = null;
  item.supplier = 'Shared name';
  ctx.openInventoryEditor(1);
  assert.ok(!body.innerHTML.includes('value="10" selected'));
  assert.ok(!body.innerHTML.includes('value="20" selected'));
  console.log('PASS: inventory editor preserves supplier IDs with duplicate or stale names and unassigned items.');
};

})();

// =====================================================================
// HELPER: testEmployees (frontend-employees.cjs)
// =====================================================================
(() => {
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

globalThis.testEmployees = async root => {
  function element() {
    const classes = new Set();
    return {children:[], dataset:{}, style:{}, textContent:'', innerHTML:'', hidden:false,
      classList:{add:c=>classes.add(c), remove:c=>classes.delete(c), contains:c=>classes.has(c), toggle(c,on){if(on)classes.add(c);else classes.delete(c);}},
      append(...children){this.children.push(...children);}, replaceChildren(...children){this.children=children;},
      setAttribute(k,v){this[k]=v;}, addEventListener(){}, focus(){}, showModal(){this.open=true;}, close(){this.open=false;}};
  }
  const elements = Object.fromEntries(['jobs','error','connection','pulseDot','orderCount','queueSectionTitle','employeeRecovery','employeeRecoveryText','retryEmployeeAction','employeeResult'].map(id=>[id,element()]));
  const container=element();container.dataset={role:'rider',user:'rider1',userId:'7'};
  const storage=new Map();let orders=[], failure=null, calls=[], confirmations=0, confirm=async()=>true;
  const ctx=vm.createContext({console,crypto:require('node:crypto').webcrypto,Date,
    document:{hidden:false,querySelector:s=>s==='.emp-container'?container:null,getElementById:id=>elements[id]||null,createElement:element,addEventListener(){},body:element()},
    window:{addEventListener(){}},setInterval(){},getSession:()=>({u:container.dataset.user,role:container.dataset.role}),
    sessionStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},
    esc:String,appConfirm:(...args)=>{confirmations++;return confirm(...args);},appNotice(){},logout(){},
    API:{request:async(route,options)=>{if(options){calls.push({route,body:JSON.parse(JSON.stringify(options.body))});if(failure)throw failure;return {order:{...orders.find(j=>j.id===Number(route.split('/').pop())),status:'delivered'}};}return {orders,updated_at:new Date().toISOString()};}}
  });
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/data/submissions.js'),'utf8'),ctx);
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/employee-delivery.js'),'utf8'),ctx);
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/employee.js'),'utf8'),ctx);
  await ctx.refreshEmployee();
  const job=(id,status='ready')=>({id,status,version:0,rider_id:7,order_type:'Delivery',receipt:'OR-'+id,customer:'Customer',lines:[{quantity:2,product:'Refill'}],issues:[],handed_over_at:'2026-10-15T01:00:00Z',created_at:'2026-10-15T01:00:00Z'});
  orders=[job(1)];await ctx.refreshEmployee();
  const stableCard=elements.jobs.children[0];await ctx.refreshEmployee();
  assert.equal(elements.jobs.children[0],stableCard,'Unchanged refresh preserves the focused order card');

  // Lock before confirmation: repeated taps must not open two dialogs or send twice.
  let resolveConfirm;confirm=()=>new Promise(resolve=>{resolveConfirm=resolve;});
  const first=ctx.changeJob(orders[0],'dispatch');const duplicate=ctx.changeJob(orders[0],'dispatch');
  assert.equal(confirmations,1,'Only one action confirmation may be open');
  resolveConfirm(false);await Promise.all([first,duplicate]);assert.equal(calls.length,0);
  confirm=async()=>true;

  // Failed issue reports must retain the original key/body and remain retryable after reload/list removal.
  failure=new Error('Response lost');
  await ctx.changeJob(orders[0],'issue','No one receiving');
  assert.equal(calls.length,1);const original=calls[0].body;
  orders=[];await ctx.refreshEmployee();
  assert.equal(elements.employeeRecovery.hidden,false,'Recovery survives disappearance of a completed order');
  const reloaded=vm.createContext({...ctx});
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/employee.js'),'utf8'),reloaded);
  await reloaded.refreshEmployee();
  failure=null;await reloaded.retryEmployeeAction();
  assert.deepEqual(calls[1].body,original,'Retry uses the original issue reason, version and key');
  assert.equal(storage.size,0);

  // FIFO and roles agree with the server: riders cannot self-assign or skip older work.
  orders=[job(1,'preparing'),job(2)];await ctx.refreshEmployee();
  const before=calls.length;
  await ctx.changeJob(orders[1],'dispatch');await ctx.changeJob(orders[0],'assign');
  assert.equal(calls.length,before);
  assert.match(elements.error.textContent,/refresh|oldest|assigned/i);

  // Client validation must keep bad issue reasons away from the API.
  await ctx.changeJob(orders[1],'issue','bad');await ctx.changeJob(orders[1],'issue','x'.repeat(501));
  assert.equal(calls.length,before);

  // A definitive stale-version rejection releases the pending action and refreshes work.
  orders=[job(2)];await ctx.refreshEmployee();failure=Object.assign(new Error('Order changed'),{status:409});
  await ctx.changeJob(orders[0],'dispatch');assert.equal(storage.size,0);
  failure=null;

  // Refiller work can be completed; a stale order/state cannot be acted on.
  container.dataset.role='refiller';orders=[job(3,'preparing')];await ctx.refreshEmployee();
  await ctx.changeJob(orders[0],'ready');assert.equal(calls.at(-1).body.action,'ready');
  const after=calls.length;await ctx.changeJob(job(99,'preparing'),'ready');assert.equal(calls.length,after);

  // Failed reads preserve visible work but make it read-only until a successful refresh.
  const originalRequest=ctx.API.request;
  ctx.API.request=async()=>{throw Error('offline');};await ctx.refreshEmployee();
  await ctx.changeJob(orders[0],'ready');assert.equal(calls.length,after);
  assert.match(elements.error.textContent,/Refresh/i);
  ctx.API.request=originalRequest;await ctx.refreshEmployee();

  // Focus and interval refreshes share one in-flight read.
  let releaseRead,reads=0;
  ctx.API.request=()=>{reads++;return new Promise(resolve=>{releaseRead=resolve;});};
  const readA=ctx.refreshEmployee(),readB=ctx.refreshEmployee();assert.equal(reads,1);
  releaseRead({orders,updated_at:new Date().toISOString()});await Promise.all([readA,readB]);ctx.API.request=originalRequest;

  // Malformed/unavailable storage cannot silently lose an uncertain request or send a new write.
  storage.set('aquaflow_job:rider1','{broken');
  await ctx.changeJob(orders[0],'ready');assert.equal(calls.length,after);assert.match(elements.error.textContent,/saved|storage|recover/i);
  storage.clear();
  // Delivery evidence must survive an uncertain response and a disappearing order card.
  container.dataset.role = 'rider'; orders = [{...job(20, 'out_for_delivery'), expected_quantity:2, collection_limit:100}];
  await ctx.refreshEmployee();
  let opened = 0;
  ctx.openDeliveryEvidence = () => { opened++; };
  await ctx.changeJob(orders[0], 'deliver');
  assert.equal(opened, 1); assert.equal(storage.size, 0, 'Opening the evidence form cannot submit a delivery');
  const photos = new Map();
  ctx.FormData = FormData;
  ctx.deliveryPhoto = async (key, operation, file) => operation === 'put' ? photos.set(key, file) : operation === 'delete' ? photos.delete(key) : photos.get(key);
  const image = new Blob(['original delivery photo bytes'], {type:'image/png'}); image.name = 'delivery.png';
  const uploads = [];
  ctx.API.request = async (route, options) => {
    if (!options) return {orders, updated_at:new Date().toISOString()};
    const fields = Object.fromEntries(options.body);
    uploads.push({fields, bytes:await fields.photo.text()});
    if (failure) throw failure;
    return {order:{id:20, status:'delivered'}};
  };
  failure = new Error('Response lost');
  await ctx.changeJob(orders[0], 'deliver', null, false, {photo:image, recipient_name:'Buyer', recipient_code:'12345678', received_quantity:2, reported_cash:100, collection_note:null});
  assert.equal(storage.size, 1); assert.equal(photos.size, 1);
  const savedDelivery = JSON.parse([...storage.values()][0]);
  assert.equal(savedDelivery.body.photo, undefined, 'Image bytes do not belong in JSON session storage');
  orders = []; await ctx.refreshEmployee(); failure = null;
  await ctx.retryEmployeeAction();
  assert.equal(uploads.length, 2);
  assert.equal(uploads[0].fields.submission_key, uploads[1].fields.submission_key);
  assert.equal(uploads[0].fields.recipient_code, uploads[1].fields.recipient_code);
  assert.equal(uploads[0].bytes, uploads[1].bytes, 'Delivery retry must upload the same image');
  assert.equal(storage.size, 0); assert.equal(photos.size, 0);

  // Multipart requests preserve their boundary and CSRF token; JSON requests retain their existing encoding.
  let sent;
  const apiContext = vm.createContext({FormData, AbortController, setTimeout, clearTimeout,
    document:{cookie:'', querySelector:()=>({getAttribute:()=> 'csrf-test-token'})},
    fetch:async (url, options)=>{sent=options;return {ok:true,status:200,json:async()=>({ok:true})};}});
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/data/api.js'),'utf8'), apiContext);
  apiContext.payload = new FormData(); apiContext.payload.append('photo', image, 'delivery.png');
  await vm.runInContext("API.request('employee/orders/20', {method:'POST', body:payload})", apiContext);
  assert.equal(sent.body, apiContext.payload);
  assert.equal(sent.headers['Content-Type'], undefined);
  assert.equal(sent.headers['X-CSRF-TOKEN'], 'csrf-test-token');
  await vm.runInContext("API.request('fulfillment/20', {method:'POST', body:{action:'handover'}})", apiContext);
  assert.equal(sent.headers['Content-Type'], 'application/json');
  assert.equal(sent.body, '{"action":"handover"}');
  let onboarding = 0;
  ctx.getSession = () => ({must_change_password:true});
  ctx.openProfileModal = async () => { onboarding++; };
  ctx.API.request = async () => { assert.fail('Employee onboarding must precede business API reads'); };
  await ctx.refreshEmployee();
  assert.equal(onboarding, 1);
  console.log('PASS: employee confirmation lock, role/FIFO guards, issue validation, uncertain-action/photo recovery, multipart CSRF and stale-order protection.');

  // --- Staff workspace mode -------------------------------------------------
  // A manager or owner working the staff tools sends the role they act under,
  // so the server can scope the queue and the permissions to it.
  {
    const modeContainer = element();
    modeContainer.dataset = {role: 'rider', mode: 'rider', user: 'owner1', userId: '3'};
    const requested = [];
    const modeCtx = vm.createContext({console,Date,
      document:{hidden:false,querySelector:s=>s==='.emp-container'?modeContainer:null,getElementById:id=>elements[id]||null,createElement:element,addEventListener(){},body:element()},
      window:{addEventListener(){}},setInterval(){},
      sessionStorage:{getItem:()=>null,setItem(){},removeItem(){}},
      getSession:()=>({role:'super_admin'}),esc:String,appConfirm:async()=>true,appNotice(){},
      API:{request:async(route,options)=>{requested.push({route,options});return {orders:[],updated_at:new Date().toISOString()};}}});
    vm.runInContext(fs.readFileSync(path.join(root,'public/js/employee.js'),'utf8'),modeCtx);
    await modeCtx.refreshEmployee();
    assert.equal(requested[0].route,'employee/orders?mode=rider','The acting role is sent with the queue read');
  }

  // --- Activity history tab -------------------------------------------------
  const historyElements = Object.fromEntries(
    ['activityList','activitySummary','activityCount','activityUpdated','pulseDot','activityError'].map(id=>[id,element()]));
  const historyRoutes = [];
  const historyCtx = vm.createContext({console,Date,
    document:{hidden:false,getElementById:id=>historyElements[id]||null,createElement:element,querySelector:()=>null,addEventListener(){},body:element()},
    window:{addEventListener(){}},setInterval(){},
    API:{request:async route=>{historyRoutes.push(route);return {entries:[
      {kind:'order',at:new Date().toISOString(),title:'AF-0001',subtitle:'Maria Santos',badge:'Marked ready',tone:'ready',
       lines:'2 × 5-Gallon Slim',amount:null,amount_label:null,note:null,address:null},
      {kind:'order',at:new Date().toISOString(),title:'AF-0002',subtitle:'Jose Reyes',badge:'Delivered',tone:'delivered',
       lines:'1 × Round 5G',amount:250,amount_label:'Cash collected',note:null,address:'12 Mabini Street'},
      {kind:'damage',at:new Date().toISOString(),title:'Clear Cover',subtitle:'3 pcs reported damaged',badge:'Reported damaged stock',tone:'damage',
       lines:'Torn seal',amount:null,amount_label:null,note:'Waiting for a manager',address:null}
    ],counts:{actions:3,sales:0,deliveries:1,damage:1,cash:250},updated_at:new Date().toISOString()};}}});
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/data/activity.js'),'utf8'),historyCtx);
  await historyCtx.refreshActivity();
  assert.deepEqual(historyRoutes,['activity'],'The activity screen reads the shared activity endpoint');
  const text = node => (node.textContent || '') + (node.children||[]).map(text).join(' ');
  assert.equal(historyElements.activityList.children.length,3,'Every recorded action is listed');
  const cards = historyElements.activityList.children.map(text);
  assert.match(cards[0],/AF-0001/); assert.match(cards[0],/Marked ready/);
  assert.match(cards[1],/AF-0002/); assert.match(cards[1],/Delivered/);
  assert.match(cards[1],/250\.00/,'Riders see the cash they reported');
  assert.match(cards[2],/Reported damaged stock/,'A damage report appears in the same list');
  assert.match(historyElements.activitySummary.textContent,/3 actions/);
  assert.match(historyElements.activitySummary.textContent,/1 damage report/);
  assert.equal(historyElements.activityCount.textContent,'3');
  console.log('PASS: the activity screen lists today’s own work, cash and damage reports in one timeline.');
};

})();

// =====================================================================
// HELPER: testWorkerValidation (frontend-worker-validation.cjs)
// =====================================================================
(() => {
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
globalThis.testWorkerValidation = async root=>{
  const classes=()=>({add(){},remove(){},toggle(){},contains(){return true;}});
  const elements={tender:{value:100},stockPreview:{innerHTML:'',classList:classes()},posStageError:{textContent:'',classList:classes()}};
  let sent=0,quote={valid:false,additional:{},shortages:['Insufficient seals']};
  const ctx=vm.createContext({console,crypto:require('node:crypto').webcrypto,window:{addEventListener(){}},
    document:{getElementById:id=>elements[id]||null,querySelectorAll:()=>[],addEventListener(){}},
    DB:{transactions:[],products:[],customers:[{id:1,name:'Guest',is_guest:true,debt:0}]},
    restorePOSState(){},savePOSState(){},saveDB(){},esc:String,money:String,icon:()=>'',appNotice(){},appConfirm:async()=>true,
    API:{replaceCustomer(){},replaceSnapshot(){},request:async()=>quote,createTransaction:async()=>{sent++;return {transaction:{no:'OR-1'},totals:{}};},inventory:async()=>({inventory:[]})},Submissions:{pending:()=>null}});
  for(const file of ['pos/cart.js','pos/checkout.js'])vm.runInContext(fs.readFileSync(path.join(root,'public/js',file),'utf8'),ctx);
  ctx.POS.custId=1;ctx.POS.cart=[{id:'slim',q:1,price:35,cat:'refill'}];ctx.renderPOS=()=>{};
  await ctx.completeSale();assert.equal(sent,0,'Checkout rechecks stock before recording a sale');
  assert.match(elements.posStageError.textContent,/stock|seals/i);
  await ctx.refreshStockPreview();assert.match(elements.stockPreview.innerHTML,/Insufficient seals/);
  ctx.API.request=async()=>{throw Error('offline');};await ctx.refreshStockPreview();
  assert.match(elements.stockPreview.innerHTML,/retry|Retry/,'Cashier gets an actionable stock failure');
  ctx.API.request=async()=>({valid:true,additional:{slim:1}});await ctx.completeSale();assert.equal(sent,1,'An available order can still be completed');
  await ctx.confirmOrderType('Delivery');
  assert.equal(ctx.POS.type,'Walk-in','Order type changes must require a registered customer');
  ctx.completeSale.busy=true;await ctx.chgQty(0,-1);ctx.setType('Delivery');
  assert.equal(ctx.POS.cart.length,1,'Cart cannot change during a financial write');assert.equal(ctx.POS.type,'Walk-in');
  ctx.completeSale.busy=false;

  // Keys must work on LAN HTTP where randomUUID is unavailable.
  ctx.crypto={getRandomValues:require('node:crypto').webcrypto.getRandomValues.bind(require('node:crypto').webcrypto)};
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/data/submissions.js'),'utf8').replace('const Submissions =','const TestedSubmissions ='),ctx);
  const keys=new Set(Array.from({length:100},()=>ctx.newSubmissionKey()));
  assert.equal(keys.size,100);for(const key of keys)assert.match(key,/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/);

  // Profile validation shares account rules and prevents duplicate submissions.
  for(const id of ['profileNameInput','profileCurrentPasswordInput','profileNewPasswordInput','profileConfirmPasswordInput','profileError','profileSuccess','profileSaveBtn'])elements[id]={value:'',classList:classes()};
  let profileWrites=0;
  ctx.validNewPassword=value=>/^[\x21-\x7E]{8,64}$/.test(value)&&/[a-z]/.test(value)&&/[A-Z]/.test(value)&&/[0-9]/.test(value);
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/data/profile.js'),'utf8'),ctx);
  const event={preventDefault(){},target:{}};ctx.API.request=async()=>{profileWrites++;return {user:{name:'Worker'}};};
  elements.profileNameInput.value='x'.repeat(21);await ctx.saveProfile(event);assert.equal(profileWrites,0);
  elements.profileNameInput.value='Worker';elements.profileCurrentPasswordInput.value='OldPassword1';elements.profileNewPasswordInput.value='aaaaaaaa';elements.profileConfirmPasswordInput.value='aaaaaaaa';
  await ctx.saveProfile(event);assert.equal(profileWrites,0);
  for(const id of ['profileCurrentPasswordInput','profileNewPasswordInput','profileConfirmPasswordInput'])elements[id].value='';
  let finishProfile;ctx.setTimeout=()=>{};ctx.API.request=()=>{profileWrites++;return new Promise(resolve=>{finishProfile=resolve;});};
  const profileA=ctx.saveProfile(event),profileB=ctx.saveProfile(event);assert.equal(profileWrites,1);
  finishProfile({user:{name:'Worker'}});await Promise.all([profileA,profileB]);assert.equal(elements.profileSaveBtn.disabled,false);

  // Handover locks before confirmation and reports committed writes even if refreshing fails.
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/pos/panels.js'),'utf8'),ctx);
  let confirmCount=0,finishConfirm;ctx.appConfirm=()=>{confirmCount++;return new Promise(resolve=>{finishConfirm=resolve;});};
  const button={disabled:false};const a=ctx.deliverQueueOrder(1,button),b=ctx.deliverQueueOrder(1,button);
  assert.equal(confirmCount,1);finishConfirm(false);await Promise.all([a,b]);assert.equal(button.disabled,false);
  let notice='';ctx.appConfirm=async()=>true;ctx.API.post=async()=>{};ctx.refreshQueuePage=async()=>false;ctx.appNotice=message=>{notice=message;};
  await ctx.deliverQueueOrder(1,button);assert.match(notice,/Handover recorded/);

  const storage=new Map(),persist=vm.createContext({console,window:{},DB:{transactions:[]},POS:{cart:[],custId:1,type:'Delivery',deliveryPay:'cashier'},getSession:()=>({u:'worker'}),
    sessionStorage:{setItem:(k,v)=>storage.set(k,v),getItem:k=>storage.get(k)||null},localStorage:{getItem:()=>null,setItem(){}}});
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/data/store.js'),'utf8'),persist);
  persist.POS.deliveryPay='rider';persist.restorePOSState();assert.equal(persist.POS.deliveryPay,'cashier','Reload preserves whether a delivery was being paid at the cashier');
  console.log('PASS: checkout stock revalidation, actionable stock failures, keyboard parity, financial-write cart lock and LAN submission keys.');
};

})();

// =====================================================================
// HELPER: testFulfillment (frontend-fulfillment.cjs)
// =====================================================================
(() => {
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

globalThis.testFulfillment = async root => {
  const button = {disabled:false};
  const form = {dataset:{}, reportValidity:()=>true, querySelector:()=>button, elements:{amount:{value:'40'},note:{value:'Rider handed over forty only'}}};
  let writes = 0, cancelled = false, failed = false;
  const context = vm.createContext({console,
    document:{hidden:false, activeElement:null, addEventListener(){}, getElementById:()=>({textContent:''})},
    window:{addEventListener(){}}, setInterval(){}, appConfirm:async()=>!cancelled, appNotice(){}, saveDB(){},
    API:{request:async()=>{throw new Error('Refresh unavailable');}, replaceCustomer(){}, replaceSnapshot(){}},
    Submissions:{send:async()=>{writes++;if(failed)throw Object.assign(new Error('Rejected'),{status:422});return {transaction:{no:'OR-QA'},customer:{id:1}};},clear(){}}
  });
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/fulfillment.js'),'utf8'), context);
  const job = {id:1,rider_id:7,receipt:'OR-ORDER',proof:{pending_remittance:100}};
  cancelled = true; await context.acceptDeliveryRemittance(job, form);
  assert.equal(writes,0); assert.equal(button.disabled,false);
  cancelled = false; failed = true; await context.acceptDeliveryRemittance(job, form);
  assert.equal(writes,1); assert.equal(button.disabled,false,'Rejected submissions remain correctable');
  failed = false; await context.acceptDeliveryRemittance(job, form);
  assert.equal(writes,2);
  await context.acceptDeliveryRemittance(job, form);
  assert.equal(writes,2,'A successful partial remittance cannot be sent again from the stale form when refresh fails');
  assert.equal(button.disabled,true);
  console.log('PASS: partial-remittance stale-form protection and cancel/validation recovery.');
};

})();

// =====================================================================
// HELPER: testFulfillmentActions (frontend-fulfillment-actions.cjs)
// =====================================================================
(() => {
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

globalThis.testFulfillmentActions = async root => {
  const storage = new Map();
  let calls = [], fail = true, confirmation = async () => true, notices = [];
  const ctx = vm.createContext({console, document:{hidden:false,addEventListener(){},getElementById(){return null;}},
    window:{addEventListener(){}},setInterval(){},getSession:()=>({u:'cashier',role:'cashier'}),
    sessionStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},
    appConfirm:(...args)=>confirmation(...args),appNotice:message=>notices.push(message),saveDB(){},
    API:{request:async(route,options)=>{calls.push({route,body:JSON.parse(JSON.stringify(options.body))});if(fail)throw Error('Response lost');return {order:{id:1}};},replaceCustomer(){},replaceSnapshot(){}},
    crypto:require('node:crypto').webcrypto});
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/data/submissions.js'),'utf8'),ctx);
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/fulfillment.js'),'utf8'),ctx);
  ctx.refreshFulfillment=async()=>{};
  const job={id:1,receipt:'OR-1',version:2,rider_id:7};
  await ctx.sendFulfillmentAction(job,'handover',{handover_quantity:2});
  assert.equal(storage.size,1);
  const original=calls[0];await ctx.sendFulfillmentAction({...job,id:2},'handover',{handover_quantity:3});
  assert.equal(calls.length,1,'Uncertain handover must be recovered before another order action');
  fail=false;await ctx.sendFulfillmentAction();
  assert.deepEqual(calls[1],original,'Retry keeps handover quantity, version and submission key');assert.equal(storage.size,0);
  let resolveConfirmation,confirmations=0;
  confirmation=()=>{confirmations++;return new Promise(resolve=>{resolveConfirmation=resolve;});};
  const first=ctx.sendFulfillmentAction(job,'reset_code'),second=ctx.sendFulfillmentAction(job,'reset_code');
  assert.equal(confirmations,1);resolveConfirmation(false);await Promise.all([first,second]);assert.equal(calls.length,2);

  // Remittances must use financial retry protection, never a plain request or a new key on retry.
  confirmation=async()=>true;
  const button={disabled:false};
  const form={dataset:{},querySelector:()=>button,reportValidity:()=>true,elements:{amount:{value:'60'},note:{value:'Rider is short forty pesos'}}};
  let submissions=[];ctx.API.post=async(route,body)=>{submissions.push({route,body:JSON.parse(JSON.stringify(body))});if(fail)throw Error('Response lost');return {transaction:{no:'OR-2'}};};
  fail=true;await ctx.acceptDeliveryRemittance(job,form);assert.equal(storage.size,1);
  fail=false;await ctx.acceptDeliveryRemittance(job,form);
  assert.deepEqual(submissions[0],submissions[1]);assert.equal(submissions[0].route,'deliveries/1/remit');assert.equal(storage.size,0);
  assert.match(notices.at(-1),/Remittance accepted/);
  form.reportValidity=()=>false;await ctx.acceptDeliveryRemittance(job,form);assert.equal(submissions.length,2);
  console.log('PASS: cashier handover and buyer-code confirmation locks, original-request recovery and idempotent remittance submissions.');
};

})();

// =====================================================================
// HELPER: testDialog (frontend-dialog.cjs)
// =====================================================================
(() => {
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

globalThis.testDialog = async root => {
  const dialogs = [];
  function element(tag) {
    return {tag, style:{},children:[],events:{},value:'',append(...children){this.children.push(...children);},
      addEventListener(name,handler){this.events[name]=handler;},showModal(){this.open=true;},focus(){this.focused=true;},
      close(){this.open=false;},remove(){this.removed=true;}};
  }
  const ctx=vm.createContext({document:{createElement:element,body:{append:dialog=>dialogs.push(dialog)}}});
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/data/dialog.js'),'utf8'),ctx);
  let result=ctx.appConfirm('<Untrusted customer>', 'Record payment');
  let dialog=dialogs.at(-1),actions=dialog.children.at(-1);
  assert.equal(dialog.open,true);assert.equal(dialog.children[0].textContent,'<Untrusted customer>');
  assert.equal(actions.children[0].focused,true);assert.equal(actions.children[1].textContent,'Record payment');
  actions.children[1].onclick();assert.equal(await result,true);assert.equal(dialog.removed,true);
  result=ctx.appConfirm('Cancel?');dialog=dialogs.at(-1);dialog.children.at(-1).children[0].onclick();assert.equal(await result,false);
  result=ctx.appConfirm('Escape?');dialog=dialogs.at(-1);let prevented=false;dialog.events.cancel({preventDefault(){prevented=true;}});
  assert.equal(await result,false);assert.equal(prevented,true);
  result=ctx.appPrompt('Reason', 'Enter a reason', 'Save');dialog=dialogs.at(-1);
  const input=dialog.children[1];assert.equal(input.tag,'textarea');assert.equal(input.placeholder,'Enter a reason');assert.equal(input.focused,true);
  input.value='  Buyer unavailable  ';dialog.children.at(-1).children[1].onclick();assert.equal(await result,'Buyer unavailable');
  result=ctx.appPrompt('Reason');dialog=dialogs.at(-1);dialog.children.at(-1).children[0].onclick();assert.equal(await result,null);
  result=ctx.appPrompt('Reason');dialog=dialogs.at(-1);dialog.events.cancel({preventDefault(){}});assert.equal(await result,null);
  console.log('PASS: shared dialogs preserve safe text, button labels, focus, acceptance, cancellation, Escape and trimmed prompts.');
};

})();

// =====================================================================
// HELPER: testHistory (frontend-history.cjs)
// =====================================================================
(() => {
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
globalThis.testHistory = async function checkHistory(root) {
  const elements = {};
  const element = () => ({innerHTML:'', dataset:{}, setAttribute(){}, removeAttribute(){}});
  for (const id of ['hBody','hSum','historyPages']) elements[id] = element();
  const errors = [], downloads = [];
  const ctx = vm.createContext({console, Date, document:{getElementById:id=>elements[id] || null},
    DB:{transactions:[{date:'2099-01-01',no:'CACHED',type:'Walk-in',pay:'Cash'}]},
    API:{transactions:async()=>{throw new Error('Connection lost');}},
    esc:value=>String(value ?? '').replaceAll('<','&lt;'), money:value=>String(value),
    filterTableRows:rows=>rows, markScrollableTables(){}, downloadCSV:csv=>downloads.push(csv), appNotice:error=>errors.push(error)
  });
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/pos/panels.js'),'utf8'),ctx);
  let filtersHidden=true;
  elements.cashierFiltersPanel={classList:{toggle:()=>filtersHidden=!filtersHidden}};
  elements.toggleFiltersBtn={setAttribute:(name,value)=>{elements.toggleFiltersBtn[name]=value;}};
  ctx.toggleCashierFilters();
  assert.equal(elements.toggleFiltersBtn['aria-expanded'],'true');
  ctx.toggleCashierFilters();
  assert.equal(elements.toggleFiltersBtn['aria-expanded'],'false');
  await assert.rejects(ctx.historyRows(), /Connection lost/, 'Server failure must not turn a partial cache into a complete report');
  await ctx.exportHistoryCsv();
  assert.equal(downloads.length,0, 'Failed history requests must not export cached transactions');
  assert.match(errors[0],/Connection lost/);
  let rejectOld;
  ctx.historyRows=()=>new Promise((resolve,reject)=>{rejectOld=reject;});
  const older=ctx.renderHistoryPage();
  ctx.historyRows=async()=>[{no:'OR-LINES',type:'Walk-in',gal:'2 × Slim 5-Gal Refill, 1 × Round 5-Gal Refill'}];
  await ctx.renderHistoryPage();
  assert.match(elements.hBody.innerHTML,/table-item-lines">2 × Slim 5-Gal Refill\n1 × Round 5-Gal Refill/,'Cashier item names stack within one cell');
  const current=elements.hBody.innerHTML;
  rejectOld(new Error('Old request failed'));
  await older;
  assert.equal(elements.hBody.innerHTML,current, 'Old failure must not overwrite newer results');
  let tableWrites=0, tableMarkup=elements.hBody.innerHTML;
  Object.defineProperty(elements.hBody,'innerHTML',{get:()=>tableMarkup,set:value=>{tableWrites++;tableMarkup=value;}});
  ctx.renderHistoryRows();assert.equal(tableWrites,0,'Unchanged history keeps receipt buttons attached');
  elements.hSum.innerHTML='Old totals'; elements.historyPages.innerHTML='Old pages';
  ctx.historyRows=async()=>{throw new Error('<offline>');};
  await ctx.renderHistoryPage();
  assert.match(elements.hBody.innerHTML,/Could not load transactions/);
  assert.match(elements.hBody.innerHTML,/Retry/);
  assert.ok(!elements.hBody.innerHTML.includes('<offline>'));
  assert.equal(elements.hSum.innerHTML,''); assert.equal(elements.historyPages.innerHTML,'');
  console.log('PASS: history failures, safe CSV export and stale request handling.');
};

})();

// =====================================================================
// HELPER: testSubmissions (frontend-submissions.cjs)
// =====================================================================
(() => {
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
globalThis.testSubmissions = async root => {
  const storage = new Map(); let user = 'cashier'; let calls = []; let fail = true;
  const ctx = vm.createContext({crypto:require('node:crypto').webcrypto, getSession:()=>({u:user}),
    sessionStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},
    document:{addEventListener(){},getElementById(){return null;}},
    API:{post:async(path,body)=>{calls.push({path,body});if(fail)throw new Error('lost response');return {transaction:{no:'OR-1',total:35}};}}});
  vm.runInContext(fs.readFileSync(root+'/public/js/data/submissions.js','utf8'),ctx);
  const submit = (payload) => vm.runInContext(`Submissions.send('transactions', ${JSON.stringify(payload)})`,ctx);
  await assert.rejects(submit({items:[1]}));
  const key=calls[0].body.submission_key;
  await assert.rejects(submit({items:[2]}),/Recover/);
  assert.equal(calls.length,1);
  fail=false;
  await submit({items:[1]});
  assert.equal(calls[1].body.submission_key,key);
  await submit({items:[1]}); assert.equal(calls.length,2);
  // A reload retains the completed request until the receipt is acknowledged.
  vm.runInContext(fs.readFileSync(root+'/public/js/data/submissions.js','utf8').replace('const Submissions =','SubmissionsReload ='),ctx);
  assert.equal(vm.runInContext('SubmissionsReload.pending().result.transaction.no',ctx),'OR-1');
  user='owner'; assert.equal(vm.runInContext('Submissions.pending()',ctx),null);
  user='cashier'; vm.runInContext('Submissions.clear()',ctx);
  await submit({items:[1]}); assert.notEqual(calls[2].body.submission_key,key);
  vm.runInContext('Submissions.clear()',ctx);
  let finish; let concurrentCalls=0; ctx.API.post=()=>{concurrentCalls++;return new Promise(resolve=>{finish=resolve;});};
  const first=submit({items:[1]}); const second=submit({items:[1]});
  assert.equal(concurrentCalls,1);
  finish({transaction:{no:'OR-2'}}); await Promise.all([first,second]);
  vm.runInContext('Submissions.clear()',ctx);
  ctx.API.post=async()=>{const e=new Error('invalid');e.status=422;throw e;};
  await assert.rejects(submit({items:[1]})); assert.equal(vm.runInContext('Submissions.pending()',ctx),null);
  ctx.API.post=async()=>null;
  await assert.rejects(submit({items:[1]}),/incomplete/);
  assert.ok(vm.runInContext('Submissions.pending()',ctx));
  vm.runInContext('Submissions.clear()',ctx);
  let attempted=false;
  ctx.API.post=async()=>{attempted=true;};
  ctx.sessionStorage.setItem=()=>{throw new Error('storage unavailable');};
  await assert.rejects(submit({items:[1]}),/storage unavailable/);
  assert.equal(attempted,false);
  console.log('PASS: submission retries, changed payload protection, reload recovery, user isolation and validation reset.');
};

})();

// =====================================================================
// HELPER: testStock (frontend-stock.cjs)
// =====================================================================
(() => {
const fs=require('node:fs'), vm=require('node:vm'), assert=require('node:assert/strict');
globalThis.testStock = async root=>{
 const elements={};
 for(const id of ['movementType','movementQty','movementNotes','movementSupplier','movementLot','movementCost','movementReason','movementError','movementCurrentStock','movementSearch','movementOrder','movementFilterType','movementFrom','movementTo','movementBody','movementPages']) elements[id]={value:'',textContent:'',innerHTML:'',classList:{add(){},remove(){}}};
 elements.movementType.value='restock';elements.movementQty.value='10';elements.movementNotes.value='Received';elements.movementSupplier.value='1';
 elements.movementFilterType.value='adjustment';elements.movementFrom.value='2026-01-01';elements.movementTo.value='2026-01-31';
 let posts=0, query; let finish;
 const ctx=vm.createContext({document:{getElementById:id=>elements[id]},esc:s=>String(s??'').replaceAll('<','&lt;'),DB:{},saveDB(){},renderInvTable(){},renderInsights(){},API:{
  post:()=>{posts++;return new Promise(resolve=>{finish=resolve;});},replaceInventory(){},advisories:async()=>{throw new Error('Advice unavailable');},
  getWithQuery:async(path,params)=>{query=params;return {data:[{created_at:'2026-01-01',type:'adjustment',qty:-2,reason:'count_correction',notes:'<check>',user:'Owner'}],meta:{current_page:1,last_page:1}};}
 }});
 vm.runInContext(fs.readFileSync(root+'/public/js/admin/stock.js','utf8'),ctx);
 vm.runInContext('stockDetailId=1',ctx);
 const button={disabled:false}, event={preventDefault(){},currentTarget:{querySelector:()=>button}};
 const first=ctx.saveStockMovement(event);await ctx.saveStockMovement(event);assert.equal(posts,1);
 finish({item:{on:110,unit:'pcs'}});await first;
 assert.equal(elements.movementQty.value,'');assert.equal(elements.movementNotes.value,'');
 assert.match(elements.movementError.textContent,/Stock movement saved/);assert.match(elements.movementError.textContent,/Do not enter/);
 assert.equal(query.type,'adjustment');assert.equal(query.from,'2026-01-01');assert.equal(query.to,'2026-01-31');
 assert.match(elements.movementBody.innerHTML,/count correction/);assert.match(elements.movementBody.innerHTML,/&lt;check>/);
 ctx.API.getWithQuery=async()=>{throw new Error('Disconnected');};await ctx.renderStockHistory();
 assert.match(elements.movementBody.innerHTML,/Retry/);assert.equal(elements.movementPages.innerHTML,'');
 console.log('PASS: stock history filters, reasons, escaped notes, duplicate-click guard and committed-write refresh errors.');
};

})();

// =====================================================================
// HELPER: testDates (frontend-dates.cjs)
// =====================================================================
(() => {
const fs=require('node:fs'), vm=require('node:vm'), assert=require('node:assert/strict');
globalThis.testDates = async root=>{
 const elements={};
 for(const prefix of ['f','h']) {
  elements[prefix+'From']={value:'2026-02-03T10:15'};
  elements[prefix+'To']={value:'2026-02-03T11:45'};
 }
 for(const id of ['salesBody','salesSum','salesPagination','salesResults']) elements[id]={innerHTML:'previous'};
 let query; const downloads=[];
 const ctx=vm.createContext({document:{getElementById:id=>elements[id]||null},API:{transactions:async params=>{query=params;return {transactions:[],has_more:false};}},esc:String,filterTableRows:rows=>rows,downloadCSV:csv=>downloads.push(csv),appNotice(){}});
 vm.runInContext(fs.readFileSync(root+'/public/js/admin/sales.js','utf8'),ctx);
 vm.runInContext(fs.readFileSync(root+'/public/js/pos/panels.js','utf8'),ctx);
 for(const method of ['fetchSalesRows','historyRows']) {
  await ctx[method]();assert.equal(query.from,'2026-02-03T10:15');assert.equal(query.to,'2026-02-03T11:45');assert.equal(query.days,undefined);
 }
 ctx.API.transactions=async()=>{throw new Error('Invalid date range');};
 await ctx.renderSalesTable();assert.match(elements.salesBody.innerHTML,/Invalid date range/);assert.equal(elements.salesSum.innerHTML,'');
 await ctx.exportSalesCSV();assert.equal(downloads.length,0);
 console.log('PASS: exact datetime filters reach both histories and failed admin reports cannot export stale data.');
};

})();

// =====================================================================
// MAIN SHARED SUITE
// =====================================================================
(() => {
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const root = process.argv[2] || path.resolve(__dirname, '..');
testInventoryEditor(root);
const downloads = [];
const elements = {};
const document = {
  body: {dataset: {posHotkeys: '1'}},
  addEventListener() {},
  getElementById(id) { return elements[id] || null; },
  createElement() { return {click() { downloads.push({name:this.download,url:this.href}); }}; },
};
const ctx = vm.createContext({console, document, crypto:require('node:crypto').webcrypto, Event, Blob, URLSearchParams, window:{},
  URL:{createObjectURL(blob) { downloads.push(blob); return 'blob:test'; }},
  localStorage:{getItem(){return null;},setItem(){}}, DB:{transactions:[]},
});
for (const file of ['auth/auth.js','data/api.js','data/store.js','data/profile.js','admin/navigation.js','admin/inventory.js','admin/settings.js','admin/sales.js','admin/arima.js','admin/insights.js','admin/customers.js','admin/stock.js','pos/checkout.js','pos/panels.js']) {
  vm.runInContext(fs.readFileSync(path.join(root,'public/js',file),'utf8'),ctx,{filename:file});
}
assert.equal(ctx.computeInitials('Ada Lovelace'), 'AL');
assert.equal(ctx.computeInitials('Cashier'), 'CA');
const API = vm.runInContext('API',ctx);
const savedReceipt=ctx.receiptContents({cust:'<Ada>',type:'Walk-in',pay:'Cash',by:'Sam',total:35,vatable:31.25,vat:3.75,cash_tendered:50,cash_change:15,items:[{quantity:1,name:'Saved refill',unit_price:35,line_total:35}]});
assert.ok(savedReceipt.includes('Saved refill'));
assert.ok(savedReceipt.includes('&lt;Ada&gt;'));
assert.ok(savedReceipt.includes('Cash tendered'));
assert.ok(savedReceipt.includes('Change'));
const paymentReceipt=ctx.receiptContents({type:'Debt Payment',pay:'Cash',total:100,balance_after:200});
assert.ok(paymentReceipt.includes('Payment received'));
assert.ok(!paymentReceipt.includes('Vatable sales'));

elements.generatedPassword={value:'',type:'password',dispatchEvent(){}};
for(let i=0;i<100;i++) {
  ctx.generateAccountPassword('generatedPassword');
  assert.match(elements.generatedPassword.value,/^[A-Za-z0-9]{16}$/);
  assert.ok(ctx.validNewPassword(elements.generatedPassword.value));
}
assert.equal(ctx.validNewPassword('short1A'),false);
assert.equal(ctx.validNewPassword('Abcd1234'),true);
assert.equal(ctx.validNewPassword('ABCDEFGHIJK12345'),false);
const passwordToggle={textContent:'Show',setAttribute(name,value){this[name]=value;}};
ctx.togglePassword('generatedPassword',passwordToggle);
assert.equal(elements.generatedPassword.type,'text');
assert.equal(passwordToggle['aria-pressed'],'true');
ctx.togglePassword('generatedPassword',passwordToggle);
assert.equal(elements.generatedPassword.type,'password');

const same = (actual,expected) => assert.equal(JSON.stringify(actual),JSON.stringify(expected));
for (const [replace,remove,key] of [['replaceCustomer',null,'customers'],['replaceInventory','removeInventory','inventory'],['replaceUser','removeUser','users'],['replaceProduct','removeProduct','products']]) {
  delete ctx.DB[key];
  API[replace]({id:1,value:'old'}); API[replace]({id:1,value:'new'}); API[replace]({id:'1',value:'string id'});
  same(ctx.DB[key],[{id:1,value:'new'},{id:'1',value:'string id'}]);
  if(remove){API[remove](1);same(ctx.DB[key],[{id:'1',value:'string id'}]); delete ctx.DB[key];API[remove](1);}
}
API.replaceSupplier({name:'Offline',contact:'old'});
API.replaceSupplier({name:'Offline',contact:'new'});
API.replaceSupplier({id:null,name:'Null id'});
API.replaceSupplier({id:2,name:'Online'});
API.removeSupplier('Offline');
same(ctx.DB.suppliers,[{id:null,name:'Null id'},{id:2,name:'Online'}]);
const queries=[];
API.get = route => {queries.push(route);return route;};
API.customers({q:'A & B',filter:'cash'}); API.transactions({days:7,type:'Delivery'});
API.customers(); API.transactions({});
same(queries,['customers?q=A+%26+B&filter=cash','transactions?days=7&type=Delivery','customers','transactions']);
assert.equal(ctx.esc('<>&"\''),'&lt;&gt;&amp;&quot;&#39;');
for (const value of [null, undefined, '', 0, false, '<>&"\'']) {
  assert.equal(ctx.escUser(value), ctx.esc(value));
}
elements.usersBody = {innerHTML: ''};
ctx.DB.users = [{u:'<owner>', name:'A & B', role:'admin', access:'"Full"', active:true}];
ctx.renderUsers();
assert.ok(elements.usersBody.innerHTML.includes('&lt;owner&gt;'));
assert.ok(elements.usersBody.innerHTML.includes('A &amp; B'));
assert.ok(elements.usersBody.innerHTML.includes('&quot;Full&quot;'));

let chartConfig;
ctx.Chart=function(el, config) { chartConfig=config; this.destroy=()=>{}; };
elements.chDemand={};
ctx.DB.forecasts={'Refill Jugs':{history:[],forecast:[1,2,3,4,5,6,7],model:{}}};
ctx.renderDemandChart();
assert.equal(chartConfig.data.datasets[1].data.length,7);
assert.equal(chartConfig.options.plugins.tooltip.callbacks.label({dataset:{label:'History'},parsed:{y:25}}),'History: 25 jugs');
assert.equal(chartConfig.options.plugins.tooltip.callbacks.label({dataset:{label:'Forecast'},parsed:{y:30}}),'Forecast: 30 jugs');
ctx.DB.forecasts['Refill Jugs'].history=Array.from({length:40},(_,i)=>i);
ctx.renderDemandChart();
assert.equal(chartConfig.data.labels.length,37);
elements.fHorizon={value:'Weekly'};
let savedView;
ctx.localStorage.setItem=(key,value)=>{savedView=value;};
ctx.localStorage.getItem=()=>savedView;
ctx.saveForecastView();elements.fHorizon.value='Daily';ctx.restoreForecastView();
assert.equal(elements.fHorizon.value,'Weekly');
for(const id of ['kRev','kRevSub','kGal','kGalSub','kLia','kLiaSub']) elements[id]={textContent:''};
ctx.DB.history=[];ctx.DB.forecast7=[10,20];ctx.DB.inventory=[];ctx.DB.customers=[];
ctx.DB.meta={generated_at:'2026-10-06T09:00:00'};
ctx.DB.transactions=[{date:'2026-10-06',total:70,type:'Walk-in',pay:'Cash'},{date:'2026-10-06',total:30,type:'Debt Payment',pay:'Cash'},{date:'2026-10-05',total:100,type:'Walk-in',pay:'Cash'}];
ctx.renderInsights();
assert.ok(elements.kRev.textContent.includes('70'));
assert.equal(elements.kGal.textContent,'30 jugs');
// Overview must not invent urgency when the station has no records.
elements.advisoryRail={classList:{_s:new Set(),toggle(c,on){if(on)this._s.add(c);else this._s.delete(c);},contains(c){return this._s.has(c);}}};
elements.dashChartRow={classList:{_s:new Set(),toggle(c,on){if(on)this._s.add(c);else this._s.delete(c);},contains(c){return this._s.has(c);}}};
elements.kStockAction={textContent:'',href:'',setAttribute(){}};
elements.advisory={innerHTML:''};elements.insRunway={innerHTML:''};
ctx.DB.advisories=[];
ctx.renderInsights();
// The empty states are worded for an owner, not for engineers: the old copy
// read "No stock alerts" in a card whose button said "Set thresholds", which
// contradicted itself. What matters here is that they stay reassuring, and that
// the panels which would only restate a zero are removed rather than shown empty.
assert.ok(!elements.advisoryRail.classList.contains('hidden')===false,'rail hidden when nothing to buy');
assert.ok(elements.dashChartRow.classList.contains('is-wide'),'chart claims the width the rail gave up');
assert.ok(elements.insRunway.innerHTML.includes('No inventory items'));
ctx.DB.advisories = Array.from({length:5}, (_, i) => ({item:'Reorder item '+i,reorder_at:10,on_hand:2,order_quantity:8,unit:'pcs'}));
ctx.renderAdvisories();
assert.equal((elements.advisory.innerHTML.match(/<article/g)||[]).length,5,'Every reorder item gets its own card in the scrollable list');
assert.ok(elements.advisory.innerHTML.includes('Reorder item 4'));
assert.ok(!elements.advisory.innerHTML.includes('Not shown'));
ctx.DB.advisories=[];
delete elements.advisoryRail;delete elements.dashChartRow;
delete elements.kStockAction;delete elements.advisory;delete elements.insRunway;
delete elements.chDemand;delete elements.fHorizon;
elements.testSearch = {value:' caps '};
elements.testOrder = {value:'newest'};
const catalog = [{id:1,item:'Caps old'},{id:3,item:'Seals'},{id:2,item:'CAPS new'}];
same(ctx.filterTableRows(catalog,'test').map(row=>row.id),[2,1]);
elements.testOrder.value='oldest';
same(ctx.filterTableRows(catalog,'test').map(row=>row.id),[1,2]);
same(catalog.map(row=>row.id),[1,3,2]);
elements.testSearch.value='';
same(ctx.filterTableRows([{id:10,date:'2026-01-01',t:'10:00'},{id:2,date:'2026-01-01',t:'09:00'}],'test').map(row=>row.id),[2,10]);
elements.testSearch.value='missing';
same(ctx.filterTableRows(catalog,'test'),[]);
let focused=false;
const classes=new Set(['hidden']);
elements.addPanel={classList:{contains:c=>classes.has(c),toggle(c,on){if(on)classes.add(c);else classes.delete(c);}},querySelector(){return {focus(){focused=true;}};}};
const addButton={getAttribute(){return 'addPanel';},setAttribute(k,v){this[k]=v;}};
ctx.toggleAddForm(addButton);
assert.equal(addButton['aria-expanded'],'true');assert.equal(focused,true);assert.equal(classes.has('hidden'),false);
ctx.toggleAddForm(addButton);
assert.equal(addButton['aria-expanded'],'false');assert.equal(classes.has('hidden'),true);
const textBtn={textContent:'Add item for sale',dataset:{defaultLabel:'Add item for sale'},getAttribute(){return 'addPanel';},setAttribute(k,v){this[k]=v;},classList:{add(){},remove(){}}};
ctx.toggleAddForm(textBtn);
assert.equal(textBtn.textContent,'Cancel');
ctx.toggleAddForm(textBtn);
assert.equal(textBtn.textContent,'Add item for sale');
elements.invBody={innerHTML:''};
elements.invBodySearch={value:'caps'};elements.invBodyOrder={value:'newest'};
ctx.DB.inventory=catalog.map(row=>({...row,cat:'Consumable',supplier:'Supplier',on:5,unit:'pcs',ss:1,rop:2,lead:2}));
ctx.statusOf=()=>['OK','pill-ok'];
ctx.renderInvTable();
assert.ok(elements.invBody.innerHTML.indexOf('CAPS new')<elements.invBody.innerHTML.indexOf('Caps old'));
assert.ok(!elements.invBody.innerHTML.includes('stkAdj('));
assert.ok(elements.invBody.innerHTML.includes('History'));
elements.invBodySearch.value='missing';ctx.renderInvTable();
assert.ok(elements.invBody.innerHTML.includes('No matching records.'));
const rows=[{no:'OR-1',date:'2026-01-01',t:'12:00',cust:'Ada',type:'Walk-in',gal:'1S',total:35,pay:'Cash',by:'Sam',vatable:31.25,vat:3.75}];
ctx.fetchSalesRows=async()=>rows;ctx.historyRows=async()=>rows;
(async()=>{
  await testEmployees(root);
  await testWorkerValidation(root);
  await testFulfillment(root);
  await testFulfillmentActions(root);
  await testDialog(root);
  await testHistory(root);
  await testSubmissions(root);
  await testStock(root);
  await testDates(root);
  elements.salesBody={innerHTML:''};elements.salesSum={innerHTML:''};elements.salesPagination={innerHTML:''};
  ctx.fetchSalesRows=async()=>Array.from({length:21},(_,i)=>({...rows[0],id:i+1,no:'OR-'+(i+1),cust:'<Ada & Co>',gal:'2 × Slim 5-Gal Refill, 1 × Round 5-Gal Refill',type:i===0?'Debt Payment':'Walk-in',total:i===0?100:35}));
  await ctx.renderSalesTable();
  assert.match(elements.salesBody.innerHTML,/table-item-lines">2 × Slim 5-Gal Refill\n1 × Round 5-Gal Refill/,'Admin item names stack within one cell');
  assert.equal((elements.salesBody.innerHTML.match(/<tr>/g)||[]).length,20);
  assert.ok(elements.salesBody.innerHTML.includes('&lt;Ada &amp; Co&gt;'));
  assert.ok(elements.salesBody.innerHTML.includes('2026-01-01'));
  assert.ok(elements.salesSum.innerHTML.includes(vm.runInContext('money(700)',ctx)));
  assert.ok(elements.salesSum.innerHTML.includes(vm.runInContext('money(800)',ctx)));
  ctx.changeSalesPage(1);
  assert.equal((elements.salesBody.innerHTML.match(/<tr>/g)||[]).length,1);
  elements.fCashier={value:'Sam',innerHTML:''};
  ctx.fetchSalesRows=async()=>[...rows,{...rows[0],no:'OR-2',by:'Alex',total:70}];
  await ctx.renderSalesTable();
  assert.equal((elements.salesBody.innerHTML.match(/<tr>/g)||[]).length,1);
  assert.ok(elements.salesBody.innerHTML.includes('OR-1'));
  assert.ok(!elements.salesBody.innerHTML.includes('OR-2'));
  assert.ok(elements.fCashier.innerHTML.includes('Alex'));
  assert.ok(elements.salesSum.innerHTML.includes(vm.runInContext('money(35)',ctx)));
  assert.equal(ctx.filterSalesCashier([{by:'Alex'},{by:'Sam'}]).length,1);
  elements.fCashier.value='';
  assert.equal(ctx.filterSalesCashier([{by:'Alex'},{by:'Sam'}]).length,2);
  ctx.fetchSalesRows=async()=>rows;
  const intervals = [];
  ctx.setInterval = (callback, delay) => { intervals.push({callback, delay}); };
  ctx.Date = class { toLocaleString() { return 'clock time'; } };
  ctx.guardAdmin = ctx.guardCashier = () => ({name:'Sam'});
  ctx.loadFromServer = async () => true;
  for (const id of ['who', 'pageTitle', 'adminClock', 'clock']) elements[id] = {textContent:''};
  await ctx.bootAdminPortal('Stock & Supplies');
  await ctx.bootCashierPortal();
  assert.equal(elements.who.textContent, 'Sam');
  assert.equal(elements.pageTitle.textContent, 'Stock & Supplies');
  assert.equal(elements.adminClock.textContent, 'clock time');
  assert.equal(elements.clock.textContent, 'clock time');
  same(intervals.map(interval => interval.delay), [1000, 1000]);
  elements.adminClock.textContent = '';
  intervals[0].callback();
  assert.equal(elements.adminClock.textContent, 'clock time');
  await ctx.exportSalesCSV(); await ctx.exportHistoryCsv();
  assert.equal(downloads[0].type,'text/csv');
  assert.equal(await downloads[0].text(),'OR,Date,Time,Customer,Type,Items,Total,Pay,Cashier,Status\nOR-1,2026-01-01,12:00,Ada,Walk-in,1S,35,Cash,Sam,Posted');
  assert.equal(downloads[1].name,'sales-audit.csv');
  assert.equal(await downloads[2].text(),'OR,Date,Time,Customer,Type,Items,Vatable,VAT,Total,Pay,Cashier,Status\nOR-1,2026-01-01,12:00,Ada,Walk-in,1S,31.25,3.75,35,Cash,Sam,Posted');
  assert.equal(downloads[3].name,'cashier-sales.csv');
  console.log('PASS: snapshot insert/replace/remove, strict IDs, supplier fallback IDs, query encoding, HTML escaping, CSV bytes and filenames.');
})().catch(error=>{console.error(error);process.exitCode=1;});

})();

// Shared UI affordances preserve actions and keep tap/keyboard tips open.
(() => {
  const assert=require('node:assert/strict'), fs=require('node:fs'), vm=require('node:vm');
  const handler=()=>{};
  const button={textContent:'Edit',dataset:{},onclick:handler,classList:{add(){}},setAttribute(name,value){this[name]=value;}};
  const shell=vm.createContext({document:{addEventListener(){}},icon:id=>'<svg data-icon="'+id+'"></svg>'});
  vm.runInContext(fs.readFileSync('public/js/data/shell.js','utf8'),shell);
  shell.enhanceCrudActions({querySelectorAll:()=>[button]});
  assert.equal(button.onclick,handler,'Icon styling keeps the original action');
  assert.equal(button['aria-label'],'Edit');
  assert.equal(button.dataset.tip,'Edit');
  assert.match(button.innerHTML,/i-edit/);
  shell.enhanceCrudActions({querySelectorAll:()=>[button]});
  assert.equal(button.dataset.actionIcon,'i-edit','Enhancement is idempotent');

  let scheduled=0;
  const pop={style:{},offsetWidth:120,offsetHeight:40,isConnected:false,setAttribute(){},addEventListener(){}};
  const tip=vm.createContext({document:{createElement:()=>pop,body:{appendChild(el){el.isConnected=true;}},addEventListener(){}},window:{innerWidth:390,innerHeight:844},onWindow(){},setTimeout(){scheduled++;return 1;},clearTimeout(){}});
  const source=fs.readFileSync('public/js/data/store.js','utf8');
  vm.runInContext(source.slice(source.indexOf('let tipLayer ='),source.indexOf('// Renders the same markup')),tip);
  const trigger=text=>({attributes:{},closest:()=>({getAttribute:()=>text}),getAttribute:()=>text,setAttribute(name,value){this.attributes[name]=value;},removeAttribute(name){delete this.attributes[name];},getBoundingClientRect:()=>({left:10,top:10,bottom:40,width:40})});
  const first=trigger('Explanation'),second=trigger('Next explanation');
  tip.openTip(first);tip.toggleTip(first);
  assert.equal(pop.hidden,false,'First tap after hover/focus keeps the hint visible');
  tip.scheduleCloseTip();assert.equal(scheduled,0,'Pinned touch hint survives pointer exit');
  tip.toggleTip(first);assert.equal(pop.hidden,true,'Second tap closes the hint');
  tip.openTip(first);tip.openTip(second);
  assert.equal(first.attributes['aria-expanded'],'false');
  assert.equal(second.attributes['aria-describedby'],'afTooltip');
  tip.closeTip();assert.equal(second.attributes['aria-describedby'],undefined);
  console.log('PASS: shared icon actions keep handlers; tooltip tap, pin, dismiss and accessibility state.');
})();