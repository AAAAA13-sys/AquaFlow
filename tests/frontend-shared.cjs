const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const root = process.argv[2] || path.resolve(__dirname, '..');
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
for (const file of ['auth/auth.js','data/api.js','data/store.js','admin/navigation.js','admin/inventory.js','admin/settings.js','admin/sales.js','admin/arima.js','admin/insights.js','admin/customers.js','admin/stock.js','pos/checkout.js','pos/panels.js']) {
  vm.runInContext(fs.readFileSync(path.join(root,'public/js',file),'utf8'),ctx,{filename:file});
}
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
for (const [replace,remove,key] of [['replaceCustomer',null,'customers'],['replaceInventory','removeInventory','inventory'],['replaceUser','removeUser','users']]) {
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
ctx.DB.forecasts={'Refill Gallons':{history:[],forecast:[1,2,3,4,5,6,7],model:{}}};
ctx.renderDemandChart();
assert.equal(chartConfig.data.datasets[1].data.length,7);
ctx.DB.forecasts['Refill Gallons'].history=Array.from({length:40},(_,i)=>i);
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
assert.equal(elements.kGal.textContent,'30 gal');
// Overview must not invent urgency when the station has no records.
elements.insActions={innerHTML:''};elements.advisory={innerHTML:''};elements.insRunway={innerHTML:''};
ctx.DB.advisories=[];
ctx.renderInsights();
assert.ok(elements.insActions.innerHTML.includes('No stock alerts'));
assert.ok(elements.insActions.innerHTML.includes('No outstanding balances'));
assert.ok(elements.advisory.innerHTML.includes('No restock advisories'));
assert.ok(elements.insRunway.innerHTML.includes('No inventory items'));
delete elements.insActions;delete elements.advisory;delete elements.insRunway;
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
  await require('./frontend-history.cjs')(root);
  await require('./frontend-attendance.cjs')(root);
  await require('./frontend-submissions.cjs')(root);
  await require('./frontend-stock.cjs')(root);
  await require('./frontend-dates.cjs')(root);
  elements.salesBody={innerHTML:''};elements.salesSum={innerHTML:''};elements.salesPagination={innerHTML:''};
  ctx.fetchSalesRows=async()=>Array.from({length:21},(_,i)=>({...rows[0],id:i+1,no:'OR-'+(i+1),cust:'<Ada & Co>',type:i===0?'Debt Payment':'Walk-in',total:i===0?100:35}));
  await ctx.renderSalesTable();
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
  assert.equal(await downloads[0].text(),'OR,Date,Time,Customer,Type,Gallons,Total,Pay,Cashier\nOR-1,2026-01-01,12:00,Ada,Walk-in,1S,35,Cash,Sam');
  assert.equal(downloads[1].name,'sales-audit.csv');
  assert.equal(await downloads[2].text(),'OR,Date,Time,Customer,Type,Gallons,Vatable,VAT,Total,Pay,Cashier\nOR-1,2026-01-01,12:00,Ada,Walk-in,1S,31.25,3.75,35,Cash,Sam');
  assert.equal(downloads[3].name,'cashier-sales.csv');
  console.log('PASS: snapshot insert/replace/remove, strict IDs, supplier fallback IDs, query encoding, HTML escaping, CSV bytes and filenames.');
})().catch(error=>{console.error(error);process.exitCode=1;});
