const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
(async()=>{
let timer,resolve, calls=0;const handlers={};
const ctx=vm.createContext({console,localStorage:{getItem(){return null;},setItem(){}},DB:{transactions:[]},document:{hidden:false,addEventListener(){}},window:{addEventListener:(name,fn)=>handlers[name]=fn},setInterval:fn=>{timer=fn;return 1;}});
vm.runInContext(fs.readFileSync('public/js/data/store.js','utf8'),ctx);
ctx.startPageRefresh(()=>{calls++;return new Promise(r=>resolve=r);});
const first=timer();await handlers.focus();assert.equal(calls,1);resolve();await first;
ctx.document.hidden=true;await timer();assert.equal(calls,1);
ctx.document.hidden=false;const online=handlers.online();assert.equal(calls,2);resolve();await online;
console.log('PASS: page synchronization pauses when hidden, prevents overlapping refreshes, and resumes on focus/reconnection.');
})().catch(error=>{console.error(error);process.exitCode=1;});
