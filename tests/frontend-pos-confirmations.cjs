const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
(async()=>{
 let sent=0,opened=0,closed=0;
 const elements={tender:{value:100},debtPay:{value:20},registerModal:{open:false,showModal(){opened++;},close(){closed++;}},regName:{focus(){}},registrationError:{textContent:'old'}};
 const ctx=vm.createContext({document:{getElementById:id=>elements[id]||null},POS:{cart:[{id:'slim',q:1}],custId:1,type:'Walk-in'},DB:{},hasCustomer:()=>true,posCust:()=>({id:1,name:'Test',debt:50}),isDelivery:()=>false,posTotals:()=>({total:35}),money:String,appConfirm:async()=>false,API:{createTransaction(){sent++;},settleDebt(){sent++;}}});
 vm.runInContext(fs.readFileSync('public/js/pos/checkout.js','utf8'),ctx);
 await ctx.completeSale();await ctx.settleDebt();assert.equal(sent,0,'Cancelled confirmation cannot write financial records');
 ctx.openRegisterModal();ctx.closeRegisterModal();assert.equal(opened,1);assert.equal(closed,1);assert.equal(elements.registrationError.textContent,'');
 let loggedOut=0;ctx.logout=()=>{loggedOut++;};
 await ctx.confirmCashierLogout();await ctx.requestCloseRegisterModal();
 assert.equal(loggedOut,0);assert.equal(closed,1,'Rejecting cancellation keeps registration open');
 ctx.appConfirm=async()=>true;
 await ctx.confirmCashierLogout();await ctx.requestCloseRegisterModal();
 assert.equal(loggedOut,1);assert.equal(closed,2);
 console.log('PASS: native registration dialog and cancelled sale/debt confirmations.');
})().catch(error=>{console.error(error);process.exitCode=1;});
