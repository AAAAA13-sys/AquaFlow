const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

module.exports = async root => {
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
if(require.main===module)module.exports(path.resolve(__dirname,'..')).catch(error=>{console.error(error);process.exitCode=1;});
