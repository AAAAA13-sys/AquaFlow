@extends('layouts.cashier')

@section('title', 'POS Terminal | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'POS Terminal')
@section('shell-class', 'cashier-viewport')

@section('cashier-body')
  @include('partials.cashier-steps')

  <div id="posDrawerBanner" style="display:none;margin:0.5rem 1.25rem 0.75rem;padding:0.75rem 1rem;border-radius:8px;font-size:0.875rem;"></div>

  <main class="pos-layout">

    {{-- ===================================================================
         STAGE 1: Customer selection
    ==================================================================== --}}
    <section class="pos-stage pos-active" data-step="1">
      <div class="card stage-card">
        {{-- Quick Walk-In is the dominant path; the two account actions are
             grouped and de-emphasised. Search keeps the middle. --}}
        <div class="stage-toolbar">
          <button type="button" onclick="pickWalkIn()" class="btn btn-primary btn-sm stage-fasttrack"
                  title="Start a walk-in cash sale">
            Quick Walk-In
          </button>
          <input id="custSearch" oninput="queueCustSearch()" class="form-input stage-search"
                 aria-label="Search customers by name or address" placeholder="Search name or address..." autocomplete="off">
          <div class="stage-secondary">
            <button type="button" onclick="voidCart()" class="btn btn-secondary btn-sm">Void order</button>
            <button type="button" onclick="openStocksModal()" class="btn btn-secondary btn-sm" title="View live stock levels">Stocks</button>
            <button onclick="openRegisterModal()" class="btn btn-secondary btn-sm">+ New Customer</button>
            <button onclick="toggleDebtSettle()" class="btn btn-secondary btn-sm">Settle Debt</button>
          </div>
        </div>

        <div id="custCard" class="active-customer-banner"></div>
      </div>

      <div class="card stage-card customer-search-panel">
        <div class="customer-search-heading">
          <div><h3 class="panel-heading">Find a customer</h3><p class="cell-sub">Search by name or address. Up to 50 matches.</p></div>
          <div class="customer-order-field"><label for="customerOrder">Customer order</label><select id="customerOrder" class="form-input" onchange="searchCustomers()"><option value="oldest">Oldest added first</option><option value="newest">Newest added first</option></select></div>
        </div>
        <div id="custList" class="customer-list-container scroll-region"></div>
      </div>
    </section>

    {{-- ===================================================================
         STAGE 2: Order type & items (2 columns on PC)
    ==================================================================== --}}
    <section class="pos-stage" data-step="2">
      <div class="stage-summary-grid">
        <div class="card stage-card">
          <div class="flex-between">
            <h3 class="panel-heading" style="margin:0;">Products</h3>
          </div>

          <div class="stage-segment" role="group" aria-label="Order type" style="margin:0.5rem 0 0.85rem;">
            <button type="button" id="bWalk" onclick="confirmOrderType('Walk-in')" class="seg-btn">
              <svg class="icon" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#i-walkin"></use></svg>
              <span>Walk-In</span>
            </button>
            <button type="button" id="bDel" onclick="confirmOrderType('Delivery')" class="seg-btn">
              <svg class="icon" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#i-truck"></use></svg>
              <span>Delivery</span>
            </button>
          </div>

          <div class="products-grid" id="productGrid">
            {{-- Rendered from the catalog --}}
          </div>
          <div id="stockPreview" class="hidden" aria-live="polite"></div>
        </div>

        <div class="card stage-card cart-tray" style="display:flex;flex-direction:column;min-height:0;">
          <div class="flex-between">
            <h3 class="panel-heading" style="margin:0;">Order items</h3>
            <span id="boughtCount" class="order-receipt-tag">0 items</span>
          </div>
          <div id="boughtItems" class="cart-items-container scroll-region" style="flex:1 1 auto;max-height:360px;overflow-y:auto;padding-bottom:10px;"></div>
          <div class="total-due-banner" style="margin-top:auto;"><span>Order total:</span><b id="boughtTotal">&#8369;0</b></div>
        </div>
      </div>
    </section>

    {{-- ===================================================================
         STAGE 3: Summary & settlement
    ==================================================================== --}}
    <section class="pos-stage" data-step="3">
      <div class="stage-summary-grid">
        <div class="card stage-card">
          <div class="flex-between">
            <h3 class="panel-heading">Order Summary</h3>
            <span id="rNo" class="order-receipt-tag">OR-0000</span>
          </div>

          <div id="sumCustomer" class="active-customer-banner"></div>

          <div class="cart-header-row">
            <span>Item</span>
            <span class="text-center">Qty</span>
            <span class="text-right">Amount</span>
          </div>
          <div id="cart" class="cart-items-container scroll-region"></div>

          <div class="totals-breakdown">
            {{-- Inclusive VAT: gross is the shelf price, tax is extracted from it. --}}
            <div class="breakdown-row"><span>Vatable Sales @include('partials.tip', [
              'text' => 'The portion of the order that tax applies to. Prices are VAT-inclusive, so this is derived from the total rather than added on top of it.',
              'label' => 'What does Vatable Sales mean?',
            ])</span><b id="tVatable">&#8369;0</b></div>
            <div class="breakdown-row"><span>12% VAT (inclusive)@include('partials.tip', [
              'text' => 'The tax already contained in the price. Nothing extra is added at checkout — the customer pays the Gross Total only.',
              'label' => 'What does VAT mean?',
            ])</span><b id="tVat">&#8369;0</b></div>
            <div class="breakdown-row"><span>Gallons</span><b id="tGal">0</b></div>
            <div class="total-due-banner"><span>Gross Total:@include('partials.tip', [
              'text' => 'The full amount the customer hands over, tax included. This is the figure printed on the receipt.',
              'label' => 'What is the Gross Total?',
            ])</span><b id="tTot">&#8369;0</b></div>
          </div>
        </div>

        <div class="card stage-card">
          <div class="flex-between">
            <h3 class="panel-heading">Payment</h3>
            <span id="payBadge" class="order-receipt-tag">CASH</span>
          </div>

          {{-- Delivery payment destination: Pay Cashier vs Pay Rider --}}
          <div id="deliveryPaymentChoice" class="delivery-pay-choice hidden" style="margin-bottom:12px;">
            <label class="form-label" style="font-size:12px;font-weight:600;margin-bottom:6px;display:block;">Payment Option for Delivery:</label>
            <div class="stage-segment" style="grid-template-columns:1fr 1fr;gap:6px;">
              <button type="button" id="payOptCashier" class="seg-btn" style="min-height:38px;font-size:12px;padding:6px 10px;" onclick="setDeliveryPayOption('cashier')">
                Pay Cashier (Cash)
              </button>
              <button type="button" id="payOptRider" class="seg-btn active" style="min-height:38px;font-size:12px;padding:6px 10px;" onclick="setDeliveryPayOption('rider')">
                Charge to Account
              </button>
            </div>
          </div>

          {{-- Walk-in cash or Delivery paid in cash at cashier --}}
          <div id="cashPanel">
            <label class="form-label" for="tender">Amount Tendered @include('partials.tip', [
                'text' => 'The cash the customer hands over. If it is less than the order total the sale cannot be completed yet; the Change figure below shows what to give back.',
                'label' => 'What is Amount Tendered?',
              ])</label>
            <input id="tender" type="number" step="0.01" value="0" min="0" max="1000000" oninput="clampTender()" class="form-input tender-input">

            <div class="quick-cash-row">
              <button type="button" class="exact" onclick="applyQuickCash('exact')">Exact</button>
              <button type="button" onclick="applyQuickCash(50)">&#8369;50</button>
              <button type="button" onclick="applyQuickCash(100)">&#8369;100</button>
              <button type="button" onclick="applyQuickCash(500)">&#8369;500</button>
              <button type="button" onclick="applyQuickCash(1000)">&#8369;1,000</button>
            </div>

            <div class="change-display">
              <span>Change</span>
              <b id="tChg">&#8369;0.00</b>
            </div>
          </div>

          {{-- Delivery: charged to the customer ledger --}}
          <div id="deliveryPanel" class="hidden">
            <div class="ledger-notice ledger-notice-info">
              Automatically charged to customer account. Delivery confirmation does not record a payment; record collections separately.
            </div>

            <div class="active-customer-box" style="margin-top:10px;">
              <div class="flex-between">
                <span>Current customer debt</span>
                <b id="deliveryBalance" class="text-red-600">&#8369;0</b>
              </div>
              <div class="flex-between" style="margin-top:0.4rem;">
                <span>This delivery</span>
                <b id="deliveryThis">&#8369;0</b>
              </div>
              <div class="breakdown-row" style="margin-top:0.4rem;border-top:1px solid var(--border-line);padding-top:0.4rem;">
                <span>Balance until collected</span>
                <b id="deliveryAfter">&#8369;0</b>
              </div>
            </div>
          </div>

          {{-- Debt settlement (reached from stage 1) --}}
          <div id="debtSettleBox" class="debt-settle-box hidden">
            <h4 class="panel-heading">Settle Existing Debt</h4>
            <div class="active-customer-box">
              <div class="flex-between">
                <span>Current Balance:</span>
                <b id="custDebtView" class="text-red-600">&#8369;0</b>
              </div>
              <div class="breakdown-row" style="margin-top:0.5rem;">
                <span>Payment Amount</span>
                <input id="debtPay" aria-label="Debt payment amount" type="number" step="0.01" value="0" min="0" oninput="clampDebtPay()" class="text-right">
              </div>
              <div class="breakdown-row">
                <span>Remaining Balance</span>
                <b id="debtAfter">&#8369;0</b>
              </div>
            </div>
            <button onclick="settleDebt()" class="btn btn-primary btn-block btn-sm" style="margin-top:0.5rem;">
              RECORD DEBT PAYMENT
            </button>
          </div>


        </div>
      </div>
    </section>

    {{-- Stage navigation: permanently docked at the bottom of the viewport. --}}
    <div class="mobile-action-bar no-print">
      <p id="posStageError" class="stage-error hidden" role="alert"></p>
      <div class="mobile-action-bar-row">
        <button id="posBack" onclick="posPrev()" class="btn btn-ghost" disabled>Back</button>
        <button id="posNext" onclick="posNext()" class="btn btn-primary">Next</button>
      </div>
    </div>
  </main>

  {{-- Customer registration --}}
  <dialog id="registerModal" class="customer-dialog" aria-labelledby="registerTitle" oncancel="event.preventDefault(); requestCloseRegisterModal()">
    <div class="auth-card" style="max-width:400px;">
      <h3 id="registerTitle" class="panel-heading">Register New Customer</h3><p id="registrationError" role="alert"></p>
      <label class="form-label" for="regName">Full Name</label>
      <input id="regName" maxlength="150" class="form-input" placeholder="e.g. Juan Dela Cruz" autocomplete="off"
             inputmode="text" pattern="^[^0-9]+$" title="Letters only — numbers are not allowed"
             oninput="this.value = this.value.replace(/[0-9]/g, '')">
      <label class="form-label" for="regContact">Mobile number</label>
      <input id="regContact" type="tel" class="form-input" required maxlength="13" autocomplete="tel" placeholder="09XXXXXXXXX or +639XXXXXXXXX">
      <label class="form-label" for="regAddr">Address</label>
      <input id="regAddr" maxlength="255" class="form-input" placeholder="e.g. Barangay San Isidro">
      <div class="button-grid-2" style="margin-top:1rem;">
        <button onclick="requestCloseRegisterModal()" class="btn btn-ghost">Cancel</button>
        <button onclick="saveNewCustomer()" class="btn btn-primary">Save</button>
      </div>
    </div>
  </dialog>

  {{-- Read-Only Current Stocks Modal --}}
  <dialog id="stocksModal" class="customer-dialog" aria-labelledby="stocksModalTitle" oncancel="event.preventDefault(); closeStocksModal()">
    <div class="auth-card" style="max-width:680px;width:95vw;max-height:85vh;display:flex;flex-direction:column;">
      <div class="flex-between" style="margin-bottom:12px;border-bottom:1px solid var(--border-line);padding-bottom:10px;">
        <div>
          <h3 id="stocksModalTitle" class="panel-heading" style="margin:0;">Current Stocks</h3>
          <p class="cell-sub" style="margin:0;">Current inventory levels &amp; reorder points</p>
        </div>
        <button type="button" onclick="closeStocksModal()" class="btn btn-ghost btn-sm" aria-label="Close modal">&times;</button>
      </div>

      <div style="margin-bottom:10px;">
        <input id="modalStockSearch" type="search" class="form-input" placeholder="Search item name..." oninput="filterModalStocks()">
      </div>

      <div class="data-table-wrapper scroll-region" style="flex:1 1 auto;overflow-y:auto;max-height:380px;">
        <table class="clean">
          <thead>
            <tr>
              <th>Item</th>
              <th>Category</th>
              <th class="num">In Stock</th>
              <th class="text-center">Status</th>
              <th class="num">Reorder Point</th>
            </tr>
          </thead>
          <tbody id="modalStockBody">
            <tr><td colspan="5" class="text-center empty-cell" style="padding:1.5rem;color:var(--text-muted,#64748b);">Loading stocks...</td></tr>
          </tbody>
        </table>
      </div>

      <div class="flex-between" style="margin-top:14px;border-top:1px solid var(--border-line);padding-top:10px;">
        <a href="{{ url('/cashier/stocks') }}" class="btn btn-ghost btn-sm">Open Full Stocks Page &rarr;</a>
        <button type="button" onclick="closeStocksModal()" class="btn btn-primary btn-sm">Close</button>
      </div>
    </div>
  </dialog>

  {{-- Thermal receipt modal --}}
  @include('partials.receipt-modal', ['closeAction' => 'closeReceipt()', 'closeLabel' => 'New Sale'])
@endsection

@push('scripts')
<script>
let terminalStockList = [];

async function openStocksModal() {
  const modal = document.getElementById('stocksModal');
  if (!modal) return;
  modal.showModal();
  const tbody = document.getElementById('modalStockBody');
  if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center empty-cell" style="padding:1.5rem;">Loading stocks...</td></tr>';
  try {
    const data = await API.inventory();
    terminalStockList = data.inventory || [];
    renderModalStocks();
  } catch (err) {
    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center empty-cell" style="padding:1.5rem;color:var(--danger,#dc2626);">Failed to load stocks.</td></tr>';
  }
}

function closeStocksModal() {
  const modal = document.getElementById('stocksModal');
  if (modal) modal.close();
}

function filterModalStocks() {
  renderModalStocks();
}

function renderModalStocks() {
  const tbody = document.getElementById('modalStockBody');
  if (!tbody) return;
  const q = (document.getElementById('modalStockSearch')?.value || '').trim().toLowerCase();
  const list = terminalStockList.filter(i => !q || (i.item || '').toLowerCase().includes(q));
  if (list.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="text-center empty-cell" style="padding:1.5rem;color:var(--text-muted,#64748b);">No stock items found.</td></tr>';
    return;
  }
  tbody.innerHTML = list.map(item => {
    const isLow = item.needs_reorder || item.status === 'Critical' || item.status === 'Reorder';
    const pill = isLow
      ? '<span class="pill pill-bad">Reorder</span>'
      : '<span class="pill pill-ok">OK</span>';
    const unitText = esc(item.unit || 'pcs');
    const onHand = Number(item.on ?? 0).toLocaleString();
    const ropVal = (item.rop !== null && item.rop !== undefined)
      ? '<b>' + Number(item.rop).toLocaleString() + '</b><span class="num-unit">' + unitText + '</span>'
      : '—';
    return `<tr>
      <td><b>${esc(item.item)}</b></td>
      <td>${esc(item.cat || '—')}</td>
      <td class="num"><b>${onHand}</b><span class="num-unit">${unitText}</span></td>
      <td class="text-center">${pill}</td>
      <td class="num">${ropVal}</td>
    </tr>`;
  }).join('');
}

async function checkDrawerGuard() {
  const banner = document.getElementById('posDrawerBanner');
  try {
    const data = await API.get('cash-shift');
    const shift = data?.shift;
    if (!banner) return;
    if (!shift) {
      banner.style.display = 'flex';
      banner.style.alignItems = 'center';
      banner.style.justifyContent = 'space-between';
      banner.style.background = 'var(--warning-bg)';
      banner.style.color = 'var(--warning-text)';
      banner.style.border = '1px solid var(--warning-border)';
      banner.innerHTML = '<span>⚠️ <b>Cash drawer is not open today.</b> Record starting cash float to activate register.</span><a href="{{ url('/cashier/cash') }}" class="btn btn-secondary btn-xs" style="margin-left:1rem;">Open Drawer</a>';
    } else if (!shift.is_open) {
      banner.style.display = 'flex';
      banner.style.alignItems = 'center';
      banner.style.justifyContent = 'space-between';
      banner.style.background = 'var(--danger-bg)';
      banner.style.color = 'var(--danger-text)';
      banner.style.border = '1px solid var(--danger-border)';
      banner.innerHTML = '<span>🔒 <b>Cash drawer closed for today.</b> Reconciled at ' + esc(shift.closed_at ? new Date(shift.closed_at).toLocaleTimeString() : 'end of shift') + '. Further sales are disabled.</span><a href="{{ url('/cashier/cash') }}" class="btn btn-secondary btn-xs" style="margin-left:1rem;">View Shift</a>';
    } else {
      banner.style.display = 'none';
    }
  } catch(e) {
    if (banner) banner.style.display = 'none';
  }
}
</script>
@endpush

@component('partials.page-startup', ['portal' => 'cashier'])
renderProducts();
setType(POS.type);
renderPOS();
posStep(1);
searchCustomers();
checkDrawerGuard();
@endcomponent
