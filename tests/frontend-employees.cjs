const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

module.exports = async root => {
  function element() {
    const classes = new Set();
    return {children:[], dataset:{}, style:{}, textContent:'', innerHTML:'', hidden:false,
      classList:{add:c=>classes.add(c), remove:c=>classes.delete(c), contains:c=>classes.has(c), toggle(c,on){if(on)classes.add(c);else classes.delete(c);}},
      append(...children){this.children.push(...children);}, replaceChildren(...children){this.children=children;},
      setAttribute(k,v){this[k]=v;}, addEventListener(){}, focus(){}, showModal(){this.open=true;}, close(){this.open=false;}};
  }
  const elements = Object.fromEntries(['jobs','error','connection','pulseDot','orderCount','queueSectionTitle','employeeRecovery','employeeRecoveryText','retryEmployeeAction','employeeResult'].map(id=>[id,element()]));
  const container=element();container.dataset={role:'rider',user:'rider1',userId:'7'};
  const storage=new Map();let orders=[], failure=null, calls=[], confirmations=0, confirm=async()=>true;
  const ctx=vm.createContext({console,crypto:require('node:crypto').webcrypto,Date,
    document:{hidden:false,querySelector:s=>s==='.emp-container'?container:null,getElementById:id=>elements[id]||null,createElement:element,addEventListener(){},body:element()},
    window:{addEventListener(){}},setInterval(){},getSession:()=>({u:container.dataset.user,role:container.dataset.role}),
    sessionStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},
    esc:String,appConfirm:(...args)=>{confirmations++;return confirm(...args);},appNotice(){},logout(){},
    API:{request:async(route,options)=>{if(options){calls.push({route,body:JSON.parse(JSON.stringify(options.body))});if(failure)throw failure;return {order:{...orders.find(j=>j.id===Number(route.split('/').pop())),status:'delivered'}};}return {orders,updated_at:new Date().toISOString()};}}
  });
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/data/submissions.js'),'utf8'),ctx);
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/employee-delivery.js'),'utf8'),ctx);
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/employee.js'),'utf8'),ctx);
  await ctx.refreshEmployee();
  const job=(id,status='ready')=>({id,status,version:0,rider_id:7,order_type:'Delivery',receipt:'OR-'+id,customer:'Customer',lines:[{quantity:2,product:'Refill'}],issues:[],handed_over_at:'2026-10-15T01:00:00Z',created_at:'2026-10-15T01:00:00Z'});
  orders=[job(1)];await ctx.refreshEmployee();

  // Lock before confirmation: repeated taps must not open two dialogs or send twice.
  let resolveConfirm;confirm=()=>new Promise(resolve=>{resolveConfirm=resolve;});
  const first=ctx.changeJob(orders[0],'dispatch');const duplicate=ctx.changeJob(orders[0],'dispatch');
  assert.equal(confirmations,1,'Only one action confirmation may be open');
  resolveConfirm(false);await Promise.all([first,duplicate]);assert.equal(calls.length,0);
  confirm=async()=>true;

  // Failed issue reports must retain the original key/body and remain retryable after reload/list removal.
  failure=new Error('Response lost');
  await ctx.changeJob(orders[0],'issue','No one receiving');
  assert.equal(calls.length,1);const original=calls[0].body;
  orders=[];await ctx.refreshEmployee();
  assert.equal(elements.employeeRecovery.hidden,false,'Recovery survives disappearance of a completed order');
  const reloaded=vm.createContext({...ctx});
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/employee.js'),'utf8'),reloaded);
  await reloaded.refreshEmployee();
  failure=null;await reloaded.retryEmployeeAction();
  assert.deepEqual(calls[1].body,original,'Retry uses the original issue reason, version and key');
  assert.equal(storage.size,0);

  // FIFO and roles agree with the server: riders cannot self-assign or skip older work.
  orders=[job(1,'preparing'),job(2)];await ctx.refreshEmployee();
  const before=calls.length;
  await ctx.changeJob(orders[1],'dispatch');await ctx.changeJob(orders[0],'assign');
  assert.equal(calls.length,before);
  assert.match(elements.error.textContent,/refresh|oldest|assigned/i);

  // Client validation must keep bad issue reasons away from the API.
  await ctx.changeJob(orders[1],'issue','bad');await ctx.changeJob(orders[1],'issue','x'.repeat(501));
  assert.equal(calls.length,before);

  // A definitive stale-version rejection releases the pending action and refreshes work.
  orders=[job(2)];await ctx.refreshEmployee();failure=Object.assign(new Error('Order changed'),{status:409});
  await ctx.changeJob(orders[0],'dispatch');assert.equal(storage.size,0);
  failure=null;

  // Refiller work can be completed; a stale order/state cannot be acted on.
  container.dataset.role='refiller';orders=[job(3,'preparing')];await ctx.refreshEmployee();
  await ctx.changeJob(orders[0],'ready');assert.equal(calls.at(-1).body.action,'ready');
  const after=calls.length;await ctx.changeJob(job(99,'preparing'),'ready');assert.equal(calls.length,after);

  // Failed reads preserve visible work but make it read-only until a successful refresh.
  const originalRequest=ctx.API.request;
  ctx.API.request=async()=>{throw Error('offline');};await ctx.refreshEmployee();
  await ctx.changeJob(orders[0],'ready');assert.equal(calls.length,after);
  assert.match(elements.error.textContent,/Refresh/i);
  ctx.API.request=originalRequest;await ctx.refreshEmployee();

  // Focus and interval refreshes share one in-flight read.
  let releaseRead,reads=0;
  ctx.API.request=()=>{reads++;return new Promise(resolve=>{releaseRead=resolve;});};
  const readA=ctx.refreshEmployee(),readB=ctx.refreshEmployee();assert.equal(reads,1);
  releaseRead({orders,updated_at:new Date().toISOString()});await Promise.all([readA,readB]);ctx.API.request=originalRequest;

  // Malformed/unavailable storage cannot silently lose an uncertain request or send a new write.
  storage.set('aquaflow_job:rider1','{broken');
  await ctx.changeJob(orders[0],'ready');assert.equal(calls.length,after);assert.match(elements.error.textContent,/saved|storage|recover/i);
  storage.clear();
  // Delivery evidence must survive an uncertain response and a disappearing order card.
  container.dataset.role = 'rider'; orders = [{...job(20, 'out_for_delivery'), expected_quantity:2, collection_limit:100}];
  await ctx.refreshEmployee();
  let opened = 0;
  ctx.openDeliveryEvidence = () => { opened++; };
  await ctx.changeJob(orders[0], 'deliver');
  assert.equal(opened, 1); assert.equal(storage.size, 0, 'Opening the evidence form cannot submit a delivery');
  const photos = new Map();
  ctx.FormData = FormData;
  ctx.deliveryPhoto = async (key, operation, file) => operation === 'put' ? photos.set(key, file) : operation === 'delete' ? photos.delete(key) : photos.get(key);
  const image = new Blob(['original delivery photo bytes'], {type:'image/png'}); image.name = 'delivery.png';
  const uploads = [];
  ctx.API.request = async (route, options) => {
    if (!options) return {orders, updated_at:new Date().toISOString()};
    const fields = Object.fromEntries(options.body);
    uploads.push({fields, bytes:await fields.photo.text()});
    if (failure) throw failure;
    return {order:{id:20, status:'delivered'}};
  };
  failure = new Error('Response lost');
  await ctx.changeJob(orders[0], 'deliver', null, false, {photo:image, recipient_name:'Buyer', recipient_code:'12345678', received_quantity:2, reported_cash:100, collection_note:null});
  assert.equal(storage.size, 1); assert.equal(photos.size, 1);
  const savedDelivery = JSON.parse([...storage.values()][0]);
  assert.equal(savedDelivery.body.photo, undefined, 'Image bytes do not belong in JSON session storage');
  orders = []; await ctx.refreshEmployee(); failure = null;
  await ctx.retryEmployeeAction();
  assert.equal(uploads.length, 2);
  assert.equal(uploads[0].fields.submission_key, uploads[1].fields.submission_key);
  assert.equal(uploads[0].fields.recipient_code, uploads[1].fields.recipient_code);
  assert.equal(uploads[0].bytes, uploads[1].bytes, 'Delivery retry must upload the same image');
  assert.equal(storage.size, 0); assert.equal(photos.size, 0);

  // Multipart requests preserve their boundary and CSRF token; JSON requests retain their existing encoding.
  let sent;
  const apiContext = vm.createContext({FormData, AbortController, setTimeout, clearTimeout,
    document:{cookie:'', querySelector:()=>({getAttribute:()=> 'csrf-test-token'})},
    fetch:async (url, options)=>{sent=options;return {ok:true,status:200,json:async()=>({ok:true})};}});
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/data/api.js'),'utf8'), apiContext);
  apiContext.payload = new FormData(); apiContext.payload.append('photo', image, 'delivery.png');
  await vm.runInContext("API.request('employee/orders/20', {method:'POST', body:payload})", apiContext);
  assert.equal(sent.body, apiContext.payload);
  assert.equal(sent.headers['Content-Type'], undefined);
  assert.equal(sent.headers['X-CSRF-TOKEN'], 'csrf-test-token');
  await vm.runInContext("API.request('fulfillment/20', {method:'POST', body:{action:'handover'}})", apiContext);
  assert.equal(sent.headers['Content-Type'], 'application/json');
  assert.equal(sent.body, '{"action":"handover"}');
  console.log('PASS: employee confirmation lock, role/FIFO guards, issue validation, uncertain-action/photo recovery, multipart CSRF and stale-order protection.');
};
if(require.main===module)module.exports(path.resolve(__dirname,'..')).catch(error=>{console.error(error);process.exitCode=1;});
