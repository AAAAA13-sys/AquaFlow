const fs=require('node:fs'), vm=require('node:vm'), assert=require('node:assert/strict');
const grid={innerHTML:''}; let role='admin';
const ctx=vm.createContext({document:{getElementById:id=>id==='tillGrid'?grid:null},getSession:()=>({role}),DB:{products:[{id:1,name:'Refill',cat:'refill',price:35,stock:10}]},esc:String,filterTableRows:rows=>rows});
vm.runInContext(fs.readFileSync('public/js/admin/till.js','utf8'),ctx);
ctx.renderTill(); assert.doesNotMatch(grid.innerHTML,/<input|<button/); assert.match(grid.innerHTML,/35.00/);
role='super_admin';ctx.renderTill();assert.match(grid.innerHTML,/saveTillPrice/);assert.match(grid.innerHTML,/deleteTillItem/);
(async()=>{
 const children=[]; const status={textContent:''}; let riders=[];
 const create=tag=>({tag,style:{},elements:{quantity:{}},append(...nodes){this.children=(this.children||[]).concat(nodes);}});
 const c=vm.createContext({document:{hidden:false,activeElement:null,addEventListener(){},createElement:create,getElementById:id=>id==='fulfillmentRows'?{replaceChildren(){children.length=0;},append(n){children.push(n);}}:status},window:{addEventListener(){}},setInterval(){},sessionStorage:{getItem:()=>null},getSession:()=>({u:'cashier'}),API:{request:async()=>({updated_at:new Date().toISOString(),riders,orders:[{id:1,receipt:'OR-1',customer:'Guest',status:'ready',description:'1 refill',issues:[],order_type:'Delivery',rider_id:null,handed_over_at:null}]})}});
 vm.runInContext(fs.readFileSync('public/js/fulfillment.js','utf8'),c);await c.refreshFulfillment();
 assert.ok(children[0].children.some(n=>n.tag==='form'),'Ready unassigned delivery must allow cashier handover before rider dispatch');
 riders=[{id:7,name:'Rider One'}]; await c.refreshFulfillment();
 const assignment=children[0].children.find(n=>n.tag==='form' && n.children?.some(child=>child.tag==='button'));
 assert.ok(assignment,'Management receives an assignment form');
 const select=assignment.children[0].children[0]; assert.equal(select.children[1].textContent,'Rider One');
 let action; c.sendFulfillmentAction=async(job,kind,extra)=>{action={kind,...extra};}; assignment.reportValidity=()=>true;select.value='7';assignment.onsubmit({preventDefault(){}});assert.deepEqual(action,{kind:'assign',rider_id:7});
 console.log('PASS: owner-only catalog controls and unassigned delivery handover.');
})().catch(e=>{console.error(e);process.exitCode=1;});
