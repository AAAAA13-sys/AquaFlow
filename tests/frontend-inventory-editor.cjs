const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

module.exports = root => {
  const body = {innerHTML: ''};
  const item = {id: 1, supplier_id: 10, supplier: 'Shared name', cat: 'Consumable'};
  const ctx = vm.createContext({
    DB: {inventory: [item], suppliers: [{id: 10, name: 'Shared name'}, {id: 20, name: 'Shared name'}]},
    document: {getElementById: id => id === 'invEditBody' ? body : {classList: {remove() {}}}},
    esc: value => String(value ?? '')
  });
  vm.runInContext(fs.readFileSync(root + '/public/js/admin/inventory.js', 'utf8'), ctx);
  ctx.openInventoryEditor(1);
  assert.equal((body.innerHTML.match(/ selected/g) || []).length, 2, 'Only category and the linked supplier should be selected');
  assert.ok(body.innerHTML.includes('value="10" selected'));
  assert.ok(!body.innerHTML.includes('value="20" selected'));
  item.supplier = 'Old name before supplier rename';
  ctx.openInventoryEditor(1);
  assert.ok(body.innerHTML.includes('value="10" selected'), 'Stale display name must not unlink the supplier');
  item.supplier_id = null;
  item.supplier = 'Shared name';
  ctx.openInventoryEditor(1);
  assert.ok(!body.innerHTML.includes('value="10" selected'));
  assert.ok(!body.innerHTML.includes('value="20" selected'));
  console.log('PASS: inventory editor preserves supplier IDs with duplicate or stale names and unassigned items.');
};
