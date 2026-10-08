const fs=require('node:fs'), vm=require('node:vm'), assert=require('node:assert/strict');
module.exports=async root=>{
 const elements={};
 for(const prefix of ['f','h']) {
  elements[prefix+'From']={value:'2026-02-03T10:15'};
  elements[prefix+'To']={value:'2026-02-03T11:45'};
 }
 for(const id of ['salesBody','salesSum','salesPagination','salesResults']) elements[id]={innerHTML:'previous'};
 let query; const downloads=[];
 const ctx=vm.createContext({document:{getElementById:id=>elements[id]||null},API:{transactions:async params=>{query=params;return {transactions:[],has_more:false};}},esc:String,filterTableRows:rows=>rows,downloadCSV:csv=>downloads.push(csv),alert(){}});
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
