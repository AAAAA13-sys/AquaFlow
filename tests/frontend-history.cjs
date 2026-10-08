const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
module.exports = async function checkHistory(root) {
  const elements = {};
  const element = () => ({innerHTML:'', dataset:{}, setAttribute(){}, removeAttribute(){}});
  for (const id of ['hBody','hSum','historyPages']) elements[id] = element();
  const errors = [], downloads = [];
  const ctx = vm.createContext({console, Date, document:{getElementById:id=>elements[id] || null},
    DB:{transactions:[{date:'2099-01-01',no:'CACHED',type:'Walk-in',pay:'Cash'}]},
    API:{transactions:async()=>{throw new Error('Connection lost');}},
    esc:value=>String(value ?? '').replaceAll('<','&lt;'), money:value=>String(value),
    filterTableRows:rows=>rows, markScrollableTables(){}, downloadCSV:csv=>downloads.push(csv), alert:error=>errors.push(error)
  });
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/pos/panels.js'),'utf8'),ctx);
  await assert.rejects(ctx.historyRows(), /Connection lost/, 'Server failure must not turn a partial cache into a complete report');
  await ctx.exportHistoryCsv();
  assert.equal(downloads.length,0, 'Failed history requests must not export cached transactions');
  assert.match(errors[0],/Connection lost/);
  let rejectOld;
  ctx.historyRows=()=>new Promise((resolve,reject)=>{rejectOld=reject;});
  const older=ctx.renderHistoryPage();
  ctx.historyRows=async()=>[];
  await ctx.renderHistoryPage();
  const current=elements.hBody.innerHTML;
  rejectOld(new Error('Old request failed'));
  await older;
  assert.equal(elements.hBody.innerHTML,current, 'Old failure must not overwrite newer results');
  elements.hSum.innerHTML='Old totals'; elements.historyPages.innerHTML='Old pages';
  ctx.historyRows=async()=>{throw new Error('<offline>');};
  await ctx.renderHistoryPage();
  assert.match(elements.hBody.innerHTML,/Could not load transactions/);
  assert.match(elements.hBody.innerHTML,/Retry/);
  assert.ok(!elements.hBody.innerHTML.includes('<offline>'));
  assert.equal(elements.hSum.innerHTML,''); assert.equal(elements.historyPages.innerHTML,'');
  console.log('PASS: history failures, safe CSV export and stale request handling.');
};
