const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

module.exports = async root => {
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
if(require.main===module)module.exports(path.resolve(__dirname,'..')).catch(error=>{console.error(error);process.exitCode=1;});
