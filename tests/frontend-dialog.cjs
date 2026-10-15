const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

module.exports = async root => {
  const dialogs = [];
  function element(tag) {
    return {tag, style:{},children:[],events:{},value:'',append(...children){this.children.push(...children);},
      addEventListener(name,handler){this.events[name]=handler;},showModal(){this.open=true;},focus(){this.focused=true;},
      close(){this.open=false;},remove(){this.removed=true;}};
  }
  const ctx=vm.createContext({document:{createElement:element,body:{append:dialog=>dialogs.push(dialog)}}});
  vm.runInContext(fs.readFileSync(path.join(root,'public/js/data/dialog.js'),'utf8'),ctx);
  let result=ctx.appConfirm('<Untrusted customer>', 'Record payment');
  let dialog=dialogs.at(-1),actions=dialog.children.at(-1);
  assert.equal(dialog.open,true);assert.equal(dialog.children[0].textContent,'<Untrusted customer>');
  assert.equal(actions.children[0].focused,true);assert.equal(actions.children[1].textContent,'Record payment');
  actions.children[1].onclick();assert.equal(await result,true);assert.equal(dialog.removed,true);
  result=ctx.appConfirm('Cancel?');dialog=dialogs.at(-1);dialog.children.at(-1).children[0].onclick();assert.equal(await result,false);
  result=ctx.appConfirm('Escape?');dialog=dialogs.at(-1);let prevented=false;dialog.events.cancel({preventDefault(){prevented=true;}});
  assert.equal(await result,false);assert.equal(prevented,true);
  result=ctx.appPrompt('Reason', 'Enter a reason', 'Save');dialog=dialogs.at(-1);
  const input=dialog.children[1];assert.equal(input.tag,'textarea');assert.equal(input.placeholder,'Enter a reason');assert.equal(input.focused,true);
  input.value='  Buyer unavailable  ';dialog.children.at(-1).children[1].onclick();assert.equal(await result,'Buyer unavailable');
  result=ctx.appPrompt('Reason');dialog=dialogs.at(-1);dialog.children.at(-1).children[0].onclick();assert.equal(await result,null);
  result=ctx.appPrompt('Reason');dialog=dialogs.at(-1);dialog.events.cancel({preventDefault(){}});assert.equal(await result,null);
  console.log('PASS: shared dialogs preserve safe text, button labels, focus, acceptance, cancellation, Escape and trimmed prompts.');
};
if(require.main===module)module.exports(path.resolve(__dirname,'..')).catch(error=>{console.error(error);process.exitCode=1;});
