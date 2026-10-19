const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const elements={queue:{innerHTML:''},queueSearch:{value:''}};
const ctx=vm.createContext({document:{getElementById:id=>elements[id]},DB:{queue:[]},esc:s=>String(s??'').replaceAll('<','&lt;')});
vm.runInContext(fs.readFileSync('public/js/pos/panels.js','utf8'),ctx);
assert.equal(ctx.queueWaitLabel(7635),'5 d 7 hr');
assert.equal(ctx.queueWaitLabel(61),'1 hr 1 min');
assert.equal(ctx.queueWaitLabel(0),'0 min');
ctx.DB.queue=[{id:1,no:'OR-1',cust:'<Customer>',items:'6S',orderType:'Walk-in',fulfillment_status:'ready',mins:7635}];
ctx.renderQueuePage();assert.match(elements.queue.innerHTML,/5 d 7 hr/);assert.match(elements.queue.innerHTML,/&lt;Customer>/);assert.match(elements.queue.innerHTML,/Confirm handover/);
ctx.DB.queue[0].orderType='Delivery';ctx.renderQueuePage();assert.doesNotMatch(elements.queue.innerHTML,/Confirm handover/);assert.doesNotMatch(elements.queue.innerHTML,/Confirm delivery/);
ctx.DB.queue[0].completed=true;ctx.renderQueuePage();assert.doesNotMatch(elements.queue.innerHTML,/Waiting 5/);assert.match(elements.queue.innerHTML,/Handover time unavailable/);
console.log('PASS: readable queue ages, escaped customer names and cashier handover eligibility.');

ctx.DB.queue[0].completed=false;ctx.DB.queue[0].orderType='Walk-in';ctx.DB.queue[0].fulfillment_status='preparing';ctx.renderQueuePage();assert.doesNotMatch(elements.queue.innerHTML,/Confirm handover/);assert.match(elements.queue.innerHTML,/Waiting for preparation/);
