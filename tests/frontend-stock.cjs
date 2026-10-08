const fs=require('node:fs'), vm=require('node:vm'), assert=require('node:assert/strict');
module.exports=async root=>{
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
