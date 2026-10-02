// AquaFlow ADMIN portal - master dataset
// Copied from the original AquaFlow prototype and kept intact.
// Credentials live here only (never rendered on the login UI).

const DB = {
  // Credentials are NOT stored in the browser. Login is verified server-side
  // against bcrypt hashes in MySQL (see api/auth.php). This list is only the
  // offline fallback for the Users & Access table.
  users: [
    { u: 'cashier', role: 'cashier', name: 'Juan Dela Cruz (Cashier #01)' },
    { u: 'admin', role: 'admin', name: 'Yuri Soliven (Station Owner)' }
  ],
  products: [
    { id: 'slim', name: 'Slim 5-Gal Refill', price: 35, group: 'refill', kind: 'S', cat: 'refill' },
    { id: 'round', name: 'Round 5-Gal Refill', price: 35, group: 'refill', kind: 'R', cat: 'refill' },
    { id: 'caps', name: 'Non-Spill Caps Pack 50', price: 15, group: 'consumable', kind: 'X', cat: 'consumable' },
    { id: 'seals', name: 'Heat Shrink Seals Pack 100', price: 20, group: 'consumable', kind: 'X', cat: 'consumable' },
    { id: 'soap', name: 'Gallon Sanitizing Soap and Sponge', price: 10, group: 'cleaning', kind: 'X', cat: 'cleaning' },
    { id: 'newS', name: 'New Slim Bottle Deposit', price: 250, group: 'container', kind: 'S', cat: 'container' },
    { id: 'newR', name: 'New Round Bottle Deposit', price: 250, group: 'container', kind: 'R', cat: 'container' }
  ],
  inventory: [
    { id: 1, item: 'Non-Spill Caps', cat: 'Consumable', on: 450, unit: 'pcs', ss: 150, rop: 600, lead: 2, supplier: 'SealPack' },
    { id: 2, item: 'Heat Shrink Seals', cat: 'Consumable', on: 2100, unit: 'pcs', ss: 300, rop: 1200, lead: 2, supplier: 'SealPack' },
    { id: 3, item: '5-Micron Sediment Filter Cartridges', cat: 'Filtration', on: 2, unit: 'units', ss: 1, rop: 2, lead: 3, supplier: 'ChemClean' },
    { id: 4, item: 'Food-Grade Soap', cat: 'Cleaning', on: 18, unit: 'btls', ss: 5, rop: 10, lead: 1, supplier: 'ChemClean' },
    { id: 5, item: 'Sanitizing Sponges', cat: 'Cleaning', on: 24, unit: 'pcs', ss: 5, rop: 15, lead: 1, supplier: 'ChemClean' },
    { id: 6, item: '5-Gal Replacement Slim Jugs', cat: 'Asset', on: 40, unit: 'units', ss: 10, rop: 25, lead: 4, supplier: 'SealPack' },
    { id: 7, item: '5-Gal Replacement Round Jugs', cat: 'Asset', on: 35, unit: 'units', ss: 10, rop: 25, lead: 4, supplier: 'SealPack' }
  ],
  customers: [
    { id: 1, name: 'San Miguel Commercial Canteen', addr: 'Kapitolyo, Pasig', contact: '0917-000-1001', issuedS: 45, returnedS: 30, issuedR: 10, returnedR: 10, debt: 24800, tx: 62, last: '2026-09-21' },
    { id: 2, name: 'Barangay Kapitolyo Health Center', addr: 'Block 4 Lot 12', contact: '0917-000-1002', issuedS: 30, returnedS: 18, issuedR: 20, returnedR: 12, debt: 1450, tx: 34, last: '2026-09-21' },
    { id: 3, name: 'Santos Family', addr: 'Blk 3 Lot 7', contact: '0917-111-2233', issuedS: 20, returnedS: 17, issuedR: 10, returnedR: 10, debt: 150, tx: 18, last: '2026-09-20' },
    { id: 4, name: 'Reyes Store', addr: 'Market Rd', contact: '0918-555-0101', issuedS: 40, returnedS: 35, issuedR: 30, returnedR: 28, debt: 500, tx: 41, last: '2026-09-20' },
    { id: 5, name: 'Dela Cruz Residence', addr: 'Phase 2 B12', contact: '0920-333-4455', issuedS: 8, returnedS: 8, issuedR: 4, returnedR: 3, debt: 0, tx: 9, last: '2026-09-19' },
    { id: 6, name: 'Aqua Office', addr: 'Industrial Park', contact: '0919-777-8899', issuedS: 60, returnedS: 54, issuedR: 0, returnedR: 0, debt: 750, tx: 27, last: '2026-09-21' },
    { id: 7, name: 'Mendoza Canteen', addr: 'School Ave', contact: '0930-123-4567', issuedS: 25, returnedS: 25, issuedR: 15, returnedR: 12, debt: 0, tx: 22, last: '2026-09-18' },
    { id: 8, name: 'Torres Apartment (6 units)', addr: 'Block 9 Lot 3', contact: '0931-222-3344', issuedS: 18, returnedS: 14, issuedR: 6, returnedR: 6, debt: 320, tx: 15, last: '2026-09-19' },
    { id: 9, name: 'Walk-in Guest', addr: '-', contact: '-', issuedS: 0, returnedS: 0, issuedR: 0, returnedR: 0, debt: 0, tx: 0, last: '-' },
    { id: 10, name: 'Garcia Laundry Shop', addr: 'Riverside St', contact: '0932-444-5566', issuedS: 22, returnedS: 20, issuedR: 12, returnedR: 9, debt: 210, tx: 19, last: '2026-09-20' }
  ],
  // 30-day time series, 120-480 gal/day with weekend spikes
  history30: [180, 210, 195, 240, 310, 420, 460, 175, 205, 225, 260, 330, 410, 450, 190, 215, 230, 270, 320, 400, 470, 185, 200, 235, 280, 340, 415, 455, 195, 225],
  forecast7: [230, 245, 260, 255, 300, 380, 340],
  model: { order: 'ARIMA(1,1,1)', aic: 412.6, adf: 'Stationary (p < 0.05)', mae: 18.4, rmse: 24.7, mape: 7.9 },
  transactions: [
    { t: '08:12', no: 'OR-1011', cust: 'Santos Family', type: 'Delivery', gal: '3S+2R', total: 175, pay: 'Cash', by: 'Cashier #01' },
    { t: '09:05', no: 'OR-1012', cust: 'Walk-in Guest', type: 'Walk-in', gal: '2S', total: 70, pay: 'Cash', by: 'Cashier #01' },
    { t: '10:40', no: 'OR-1013', cust: 'Reyes Store', type: 'Delivery', gal: '5S+5R', total: 350, pay: 'Account', by: 'Cashier #01' },
    { t: '11:15', no: 'OR-1014', cust: 'Aqua Office', type: 'Delivery', gal: '6S', total: 210, pay: 'Account', by: 'Cashier #01' }
  ],
  queue: [
    { no: 'OR-1015', cust: 'Dela Cruz', stage: 2, mins: 6 },
    { no: 'OR-1016', cust: 'Walk-in', stage: 4, mins: 11 },
    { no: 'OR-1017', cust: 'Mendoza', stage: 1, mins: 3 }
  ],
  suppliers: [
    { name: 'AquaRaw Trading', item: 'Raw water', lead: 2, contact: '0917-000-1111', last: '2026-09-20' },
    { name: 'SealPack', item: 'Seals, caps, gallons', lead: 3, contact: '0918-000-2222', last: '2026-09-18' },
    { name: 'ChemClean', item: 'Soap, sponge, filters', lead: 5, contact: '0919-000-3333', last: '2026-09-15' }
  ]
};

const STAGES = ['Unload', 'Wash', 'Fill', 'Seal'];

function pending(c) {
  return { s: c.issuedS - c.returnedS, r: c.issuedR - c.returnedR };
}

// Status label + pill tone. The label itself is an explicit instruction
// ("REORDER NOW") rather than a passive "Warning"; the pill tone still carries
// the severity split so the two tiers remain visually distinguishable.
// `status_label` / `needs_reorder` come from the backend resource.
function statusOf(inv) {
  if (inv.status_label) return [inv.status_label, inv.status === 'Critical' ? 'pill-bad' : 'pill-warn'];
  if (inv.on <= inv.ss) return ['REORDER NOW', 'pill-bad'];
  if (inv.on <= inv.rop) return ['REORDER NOW', 'pill-warn'];
  return ['OK', 'pill-ok'];
}
