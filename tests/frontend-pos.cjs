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
  assert.equal(lastParams.limit, 8, 'the terminal asks for a short page');
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
