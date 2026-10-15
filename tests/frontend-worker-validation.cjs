const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
module.exports=async root=>{
  const classes=()=>({add(){},remove(){},toggle(){},contains(){return true;}});
  const elements={tender:{value:100},stockPreview:{innerHTML:'',classList:classes()},posStageError:{textContent:'',classList:classes()}};
  let sent=0,quote={valid:false,additional:{},shortages:['Insufficient seals']};
  const ctx=vm.createContext({console,crypto:require('node:crypto').webcrypto,window:{addEventListener(){}},
    document:{getElementById:id=>elements[id]||null,querySelectorAll:()=>[],addEventListener(){}},
    DB:{transactions:[],products:[],customers:[{id:1,name:'Guest',is_guest:true,debt:0}]},
    restorePOSState(){},savePOSState(){},saveDB(){},esc:String,money:String,icon:()=>'',appNotice(){},appConfirm:async()=>true,
    API:{replaceCustomer(){},replaceSnapshot(){},request:async()=>quote,createTransaction:async()=>{sent++;return {transaction:{no:'OR-1'},totals:{}};},inventory:async()=>({inventory:[]})},Submissions:{pending:()=>null}});
  for(const file of ['pos/cart.js','pos/checkout.js'])vm.runInContext(fs.readFileSync(path.join(root,'public/js',file),'utf8'),ctx);
  ctx.POS.custId=1;ctx.POS.cart=[{id:'slim',q:1,price:35,cat:'refill'}];ctx.renderPOS=()=>{};
  await ctx.completeSale();assert.equal(sent,0,'Checkout rechecks stock before recording a sale');
  assert.match(elements.posStageError.textContent,/stock|seals/i);
  await ctx.refreshStockPreview();assert.match(elements.stockPreview.innerHTML,/Insufficient seals/);
  ctx.API.request=async()=>{throw Error('offline');};await ctx.refreshStockPreview();
  assert.match(elements.stockPreview.innerHTML,/retry|Retry/,'Cashier gets an actionable stock failure');
  ctx.API.request=async()=>({valid:true,additional:{slim:1}});await ctx.completeSale();assert.equal(sent,1,'An available order can still be completed');
  await vm.runInContext('POS_HOTKEYS.F4.run()',ctx);
  assert.equal(ctx.POS.type,'Walk-in','F4 must not bypass registered-customer validation');
  ctx.completeSale.busy=true;await ctx.chgQty(0,-1);ctx.setType('Delivery');
  assert.equal(ctx.POS.cart.length,1,'Cart cannot change during a financial write');assert.equal(ctx.POS.type,'Walk-in');
  ctx.completeSale.busy=false;

  // Keys must work on LAN HTTP where randomUUID is unavailable.
  ctx.crypto={getRandomValues:require('node:crypto').webcrypto.getRandomValues.bind(require('node:crypto').webcrypto)};
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/data/submissions.js'),'utf8').replace('const Submissions =','const TestedSubmissions ='),ctx);
  const keys=new Set(Array.from({length:100},()=>ctx.newSubmissionKey()));
  assert.equal(keys.size,100);for(const key of keys)assert.match(key,/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/);

  // Profile validation shares account rules and prevents duplicate submissions.
  for(const id of ['profileNameInput','profileCurrentPasswordInput','profileNewPasswordInput','profileConfirmPasswordInput','profileError','profileSuccess','profileSaveBtn'])elements[id]={value:'',classList:classes()};
  let profileWrites=0;
  ctx.validNewPassword=value=>/^[\x21-\x7E]{8,64}$/.test(value)&&/[a-z]/.test(value)&&/[A-Z]/.test(value)&&/[0-9]/.test(value);
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/data/profile.js'),'utf8'),ctx);
  const event={preventDefault(){},target:{}};ctx.API.request=async()=>{profileWrites++;return {user:{name:'Worker'}};};
  elements.profileNameInput.value='x'.repeat(101);await ctx.saveProfile(event);assert.equal(profileWrites,0);
  elements.profileNameInput.value='Worker';elements.profileCurrentPasswordInput.value='OldPassword1';elements.profileNewPasswordInput.value='aaaaaaaa';elements.profileConfirmPasswordInput.value='aaaaaaaa';
  await ctx.saveProfile(event);assert.equal(profileWrites,0);
  for(const id of ['profileCurrentPasswordInput','profileNewPasswordInput','profileConfirmPasswordInput'])elements[id].value='';
  let finishProfile;ctx.setTimeout=()=>{};ctx.API.request=()=>{profileWrites++;return new Promise(resolve=>{finishProfile=resolve;});};
  const profileA=ctx.saveProfile(event),profileB=ctx.saveProfile(event);assert.equal(profileWrites,1);
  finishProfile({user:{name:'Worker'}});await Promise.all([profileA,profileB]);assert.equal(elements.profileSaveBtn.disabled,false);

  // Handover locks before confirmation and reports committed writes even if refreshing fails.
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/pos/panels.js'),'utf8'),ctx);
  let confirmCount=0,finishConfirm;ctx.appConfirm=()=>{confirmCount++;return new Promise(resolve=>{finishConfirm=resolve;});};
  const button={disabled:false};const a=ctx.deliverQueueOrder(1,button),b=ctx.deliverQueueOrder(1,button);
  assert.equal(confirmCount,1);finishConfirm(false);await Promise.all([a,b]);assert.equal(button.disabled,false);
  let notice='';ctx.appConfirm=async()=>true;ctx.API.post=async()=>{};ctx.refreshQueuePage=async()=>false;ctx.appNotice=message=>{notice=message;};
  await ctx.deliverQueueOrder(1,button);assert.match(notice,/Handover recorded/);

  const storage=new Map(),persist=vm.createContext({console,window:{},DB:{transactions:[]},POS:{cart:[],custId:1,type:'Delivery',deliveryPay:'cashier'},getSession:()=>({u:'worker'}),
    sessionStorage:{setItem:(k,v)=>storage.set(k,v),getItem:k=>storage.get(k)||null},localStorage:{getItem:()=>null,setItem(){}}});
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/data/store.js'),'utf8'),persist);
  persist.POS.deliveryPay='rider';persist.restorePOSState();assert.equal(persist.POS.deliveryPay,'cashier','Reload preserves whether a delivery was being paid at the cashier');
  console.log('PASS: checkout stock revalidation, actionable stock failures, keyboard parity, financial-write cart lock and LAN submission keys.');
};
if(require.main===module)module.exports(path.resolve(__dirname,'..')).catch(error=>{console.error(error);process.exitCode=1;});
