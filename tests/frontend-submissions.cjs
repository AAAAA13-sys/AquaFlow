const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
module.exports = async root => {
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
