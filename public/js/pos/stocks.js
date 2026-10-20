let cashierInventoryData = [];
let cashierStocksLoading = false;
let cashierStocksError = false;

async function loadCashierStocks() {
  if (cashierStocksLoading) return;
  cashierStocksLoading = true;
  const button = document.getElementById('refreshStocksBtn');
  if (button) button.disabled = true;
  const badge = document.getElementById('cStockCountBadge');
  if (badge) badge.textContent = 'Updating…';

  try {
    const data = await API.inventory();
    cashierStocksError = false;
    cashierInventoryData = data.inventory || [];
    renderCashierStocksTable();
  } catch (error) {
    cashierStocksError = true;
    cashierInventoryData = [];
    renderCashierStocksTable();
  } finally {
    cashierStocksLoading = false;
    if (button) button.disabled = false;
  }
}

function renderCashierStocksTable() {
  const tbody = document.getElementById('cStockBody');
  const badge = document.getElementById('cStockCountBadge');
  if (!tbody) return;
  if (cashierStocksError) {
    if (badge) badge.textContent = 'Unavailable';
    tbody.innerHTML = '<tr><td colspan="4" class="empty-cell">Stock could not be refreshed. <button type="button" class="btn btn-secondary btn-sm" onclick="loadCashierStocks()">Retry</button></td></tr>';
    return;
  }

  const cat = (document.getElementById('cStockCatFilter')?.value || '').trim().toLowerCase();
  const search = (document.getElementById('cStockSearchInput')?.value || '').trim().toLowerCase();
  const sort = document.getElementById('cStockSortSelect')?.value || 'lowest';

  let filtered = cashierInventoryData.filter(item => {
    if (cat && (item.cat || '').toLowerCase() !== cat) return false;
    if (search && !(item.item || '').toLowerCase().includes(search)) return false;
    return true;
  });

  filtered.sort((a, b) => {
    if (sort === 'lowest') return (a.on ?? 0) - (b.on ?? 0);
    if (sort === 'highest') return (b.on ?? 0) - (a.on ?? 0);
    if (sort === 'name') return (a.item || '').localeCompare(b.item || '');
    return 0;
  });

  if (badge) {
    badge.textContent = `${filtered.length} of ${cashierInventoryData.length} items`;
  }

  if (filtered.length === 0) {
    tbody.innerHTML = '<tr><td colspan="4" class="text-center empty-cell" style="padding:2rem;color:var(--text-muted, #64748b);">No stock items match your search or filter.</td></tr>';
    return;
  }

  tbody.innerHTML = filtered.map(item => {
    const isLow = item.needs_reorder || item.status === 'Critical' || item.status === 'Reorder';
    const statusPill = Number(item.on ?? 0) <= 0
      ? '<span class="pill pill-bad">Out of stock</span>'
      : isLow
      ? '<span class="pill pill-bad">Low stock</span>'
      : '<span class="pill pill-ok">In stock</span>';

    const unitText = esc(item.unit || 'pcs');
    const onHand = Number(item.on ?? 0).toLocaleString();
    return `
      <tr>
        <td><b>${esc(item.item)}</b></td>
        <td>${esc(item.cat || '—')}</td>
        <td class="num"><b>${onHand}</b><span class="num-unit">${unitText}</span></td>
        <td class="text-center">${statusPill}</td>
      </tr>
    `;
  }).join('');
}

// ---------- Report Defect ----------

let defectSubmitBusy = false;

function openDefectReportDialog() {
  const dialog = document.getElementById('defectReportDialog');
  if (!dialog) return;
  const select = document.getElementById('defectItem');
  if (select) {
    select.innerHTML = cashierInventoryData.map(item =>
      '<option value="' + Number(item.id) + '">' + esc(item.item) + ' (' + Number(item.on ?? 0).toLocaleString() + ' ' + esc(item.unit || 'pcs') + ' in stock)</option>'
    ).join('');
  }
  document.getElementById('defectQty').value = '';
  document.getElementById('defectReason').value = '';
  const err = document.getElementById('defectReportError');
  if (err) { err.textContent = ''; err.classList.add('hidden'); }
  if (!dialog.open) dialog.showModal();
  const qty = document.getElementById('defectQty');
  if (qty) qty.focus();
}

function closeDefectReportDialog() {
  const dialog = document.getElementById('defectReportDialog');
  if (dialog) dialog.close();
}

async function submitDefectReport() {
  if (defectSubmitBusy) return;
  const itemId = Number(document.getElementById('defectItem')?.value || 0);
  const qty = Number(document.getElementById('defectQty')?.value || 0);
  const reason = (document.getElementById('defectReason')?.value || '').trim();
  const err = document.getElementById('defectReportError');
  const showError = msg => { if (err) { err.textContent = msg; err.classList.remove('hidden'); } };

  if (!itemId) return showError('Choose an item to report.');
  if (!Number.isFinite(qty) || qty < 1) return showError('Enter a quantity of at least 1.');
  if (reason.length < 3) return showError('Describe the defect in a few words.');

  const item = cashierInventoryData.find(r => Number(r.id) === itemId);
  if (item && qty > Number(item.on ?? 0)) return showError('Quantity exceeds available stock');

  defectSubmitBusy = true;
  const btn = document.getElementById('defectSubmitBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }
  try {
    await API.createDefectReport({ inventory_item_id: itemId, quantity: qty, reason: reason });
    closeDefectReportDialog();
    appNotice('Defect reported. A manager will review it.');
  } catch (error) {
    showError(error.message || 'Could not submit the report. Try again.');
  } finally {
    defectSubmitBusy = false;
    if (btn) { btn.disabled = false; btn.textContent = 'Submit report'; }
  }
}
