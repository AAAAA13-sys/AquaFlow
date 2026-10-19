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
