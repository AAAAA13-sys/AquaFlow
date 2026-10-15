const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
module.exports=async root=>{
  const element=()=>{const classes=new Set(['hidden']);return {value:'',textContent:'',innerHTML:'',disabled:false,focus(){},classList:{add:c=>classes.add(c),remove:c=>classes.delete(c),contains:c=>classes.has(c)}};};
  const elements=Object.fromEntries(['cashOpenForm','cashCloseForm','cashResultCard','cashResult','cashSummary','cashStatus','cashOpenBtn','cashCloseBtn','cashFloat','cashCounted','cashNote'].map(id=>[id,element()]));
  let writes=0,shift=null,pending=null,confirm=async()=>true;
  const ctx=vm.createContext({document:{getElementById:id=>elements[id]||null},money:String,esc:String,appNotice(){},appConfirm:(...args)=>confirm(...args),Submissions:{pending:()=>pending},
    API:{cashShift:async()=>({shift,history:[]}),openCashShift:async()=>{writes++;},closeCashShift:async()=>{writes++;return {shift};}}});
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/admin/cash.js'),'utf8'),ctx);
  await ctx.renderCashShift();elements.cashFloat.value='not a number';await ctx.openCashDrawer();assert.equal(writes,0,'Nonfinite float cannot reach API');
  elements.cashFloat.value='1000001';await ctx.openCashDrawer();assert.equal(writes,0);
  shift={is_open:false,date:'2026-10-15',variance:0,status:'balanced',opening_float:0,total_sales:0,counted_cash:0,expected_cash:0};await ctx.renderCashShift();
  assert.equal(elements.cashOpenForm.classList.contains('hidden'),true,'Closed drawer cannot be reopened today');
  shift={...shift,is_open:true,status:'open'};await ctx.renderCashShift();
  pending={};elements.cashCounted.value='0';await ctx.closeCashDrawer();assert.equal(writes,0,'Recover uncertain payments before closing cash');pending=null;
  confirm=async()=>false;await ctx.closeCashDrawer();assert.equal(writes,0,'Cancelled close leaves the drawer open');
  let finish;confirm=()=>new Promise(resolve=>{finish=resolve;});const first=ctx.closeCashDrawer();const second=ctx.closeCashDrawer();finish(false);await Promise.all([first,second]);assert.equal(writes,0);
  ctx.API.cashShift=async()=>{throw Error('offline');};await ctx.renderCashShift();
  assert.equal(elements.cashOpenForm.classList.contains('hidden'),true);assert.equal(elements.cashCloseForm.classList.contains('hidden'),true);
  assert.match(elements.cashStatus.textContent,/refresh|Refresh|offline/);
  let oldRead;ctx.API.cashShift=()=>new Promise(resolve=>{oldRead=resolve;});const old=ctx.renderCashShift();
  ctx.API.cashShift=async()=>({shift:{...shift,is_open:false,status:'balanced'},history:[]});await ctx.renderCashShift();
  oldRead({shift,history:[]});await old;
  assert.equal(elements.cashCloseForm.classList.contains('hidden'),true,'An older open-drawer response cannot replace a newer closed drawer');
  console.log('PASS: drawer amount validation, closed-day controls, pending-payment guard, close confirmation and unavailable-state handling.');
};
if(require.main===module)module.exports(path.resolve(__dirname,'..')).catch(error=>{console.error(error);process.exitCode=1;});
