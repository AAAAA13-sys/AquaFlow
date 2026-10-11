const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const elements = {};
const el = id => elements[id] ||= {value:'',disabled:false,classList:{add(){},remove(){}},reset(){}};
let resolveRequest, rejectRequest, creates=0, updates=0, payload;
const deferred = () => new Promise((resolve,reject)=>{resolveRequest=resolve;rejectRequest=reject;});
const ctx = vm.createContext({document:{getElementById:el},validNewPassword:()=>true,saveDB(){},DB:{users:[]},API:{
  createUser(data){creates++;payload=data;return deferred();},updateUser(id,data){updates++;payload=data;return deferred();},replaceUser(){}
}});
vm.runInContext(fs.readFileSync('public/js/admin/settings.js','utf8'),ctx);
ctx.renderUsers=()=>{};
(async()=>{
  el('usrName').value='Cashier';el('usrUsername').value='staff';el('usrPassword').value='Password123';el('usrRole').value='cashier';
  const first=ctx.createUser({preventDefault(){}});const duplicate=ctx.createUser({preventDefault(){}});
  assert.equal(creates,1,'Repeated create must not submit a second request');
  await duplicate;
  assert.equal(el('userCreateButton').disabled,true);
  assert.equal('pin' in payload,false);
  rejectRequest(Error('Cannot save'));await first;
  assert.equal(el('userCreateButton').disabled,false);assert.equal(el('usrUsername').value,'staff');
  const retry=ctx.createUser();assert.equal(creates,2);resolveRequest({user:{}});await retry;
  el('usrEditName').value='Cashier';el('usrEditRole').value='cashier';
  const save=ctx.saveUserEdit(1);const duplicateSave=ctx.saveUserEdit(1);
  assert.equal(updates,1,'Repeated save must not submit a second request');
  await duplicateSave;
  assert.equal(el('userSaveButton').disabled,true);assert.equal('pin' in payload,false);
  rejectRequest(Error('Cannot save'));await save;
  assert.equal(el('userSaveButton').disabled,false);
  const saveRetry=ctx.saveUserEdit(1);assert.equal(updates,2);resolveRequest({user:{}});await saveRetry;
  console.log('PASS: account create/save duplicate guards, retry after failure and no legacy PIN payload.');
})().catch(error=>{console.error(error);process.exitCode=1;});
