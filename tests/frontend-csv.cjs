const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');

(async () => {
  const root = path.resolve(__dirname, '..');
  const downloads = [];
  const ctx = vm.createContext({ window: {}, document: {getElementById: () => null, addEventListener() {}},
    filterTableRows: rows => rows, appNotice: message => {throw new Error(message);},
    DB: {transactions: [], inventory: [], advisories: []} });
  for (const file of ['data/store.js', 'admin/sales.js', 'pos/panels.js', 'admin/arima.js', 'admin/insights.js']) {
    vm.runInContext(fs.readFileSync(path.join(root, 'public/js', file), 'utf8'), ctx);
  }
  ctx.downloadCSV = (csv, filename) => downloads.push({csv, filename});
  const row = {no: 'OR-1', date: '2026-10-11', t: '08:00', cust: '=1+1', type: 'Walk-in', gal: '1S', total: 35, pay: 'Cash', by: '+SUM(1,2)'};
  ctx.fetchSalesRows = ctx.historyRows = ctx.reportSales = async () => [row];
  await ctx.exportSalesCSV();
  await ctx.exportHistoryCsv();
  for (const {csv} of downloads) {
    assert.ok(csv.includes(",'=1+1,"), 'Customer formula must be exported as text');
    assert.ok(csv.includes('"\'+SUM(1,2)"'), 'Cashier formula must be quoted and exported as text');
  }
  for (const value of ['=1+1', '+1', '-1', '@SUM(A1)', '\t=1', '\r=1', '\n=1', '  =1']) {
    assert.ok(ctx.csvCell(value).includes("'"), 'Unsafe prefix: ' + JSON.stringify(value));
  }
  assert.equal(ctx.csvCell(-12.5), '-12.5');
  assert.equal(ctx.csvCell(null), '');
  assert.equal(ctx.csvCell('Supplier, "A"\nBranch'), '"Supplier, ""A""\nBranch"');
  ctx.DB.advisories = [{item: '=1+1', order_quantity: 4, order_qty: 4, supplier: 'Supplier, "A"', reorder_at: 2}];
  ctx.draftPO();
  assert.ok(downloads.at(-1).csv.includes("'=1+1,4,\"Supplier, \"\"A\"\"\""));
  ctx.DB.inventory = [{item: '=1+1', on: 2, unit: 'pcs', ss: 1, rop: 2, supplier: 'Supplier, "A"'}];
  ctx.currentSeriesRecord = () => ({series: '=1+1', history: [-2], forecast: [3], model: {order: 'ARIMA(1,0,1)'}});
  await ctx.exportReport();
  assert.ok(downloads.at(-1).csv.includes("# Series,'=1+1"));
  assert.ok(downloads.at(-1).csv.includes('# Model,"ARIMA(1,0,1)"'));
  assert.ok(downloads.at(-1).csv.includes("'=1+1,2,pcs,1,2,"));
  assert.ok(downloads.at(-1).csv.includes(",'=1+1,35,Cash"));
  console.log('PASS: all four CSV exports escape columns and neutralize spreadsheet formulas; numeric values stay numeric.');
})().catch(error => {console.error(error); process.exitCode = 1;});
