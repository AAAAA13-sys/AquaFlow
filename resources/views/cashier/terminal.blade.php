@extends('layouts.cashier')

@section('title', 'POS Terminal | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'POS Terminal')

@section('cashier-body')
  @include('partials.cashier-steps')

  <main class="pos-layout">
    <div class="pos-columns-grid">

      {{-- =================================================================
           STAGE 1: Customer selection & ledger
      ================================================================== --}}
      <div class="pos-col pos-active" data-step="1">
        <div class="card">
          <h3 class="panel-heading">Customer</h3>
          <input id="custSearch" oninput="renderCustList()" class="form-input" placeholder="Search name or address...">

          <div class="button-grid-2 order-type-selector">
            <button onclick="openRegisterModal()" class="btn btn-ghost btn-sm">+ New Customer</button>
            <button onclick="toggleDebtSettle()" class="btn btn-ghost btn-sm">Settle Debt</button>
          </div>

          <p class="section-label">Active Customer</p>
          <div id="custCard" class="active-customer-box"></div>

          <div id="custList" class="customer-list-container"></div>
        </div>
      </div>

      {{-- =================================================================
           STAGE 2: Order type & products
      ================================================================== --}}
      <div class="pos-col" data-step="2" id="posGrid">
        <div class="card">
          <h3 class="panel-heading">Order Type</h3>
          <div class="button-grid-2">
            <button id="bWalk" onclick="setType('Walk-in')" class="btn btn-primary">Walk-In</button>
            <button id="bDel" onclick="setType('Delivery')" class="btn btn-ghost">Delivery</button>
          </div>
          <p id="orderTypeNote" class="auto-deduct-caption"></p>
        </div>

        <div class="card order-type-selector">
          <h3 class="panel-heading">Select Items</h3>
          <div class="products-grid" id="productGrid">
            {{-- Rendered from the catalog --}}
          </div>
        </div>

        <div class="card bought-items-card">
          <div class="flex-between">
            <h3 class="panel-heading">Bought Items</h3>
            <span id="boughtCount" class="order-receipt-tag">0 items</span>
          </div>
          <div id="boughtItems" class="cart-items-container"></div>
          <div class="total-due-banner"><span>Running Total:</span><b id="boughtTotal">&#8369;0</b></div>
        </div>
      </div>

      {{-- =================================================================
           STAGE 3: Summary, inclusive VAT & settlement
      ================================================================== --}}
      <div class="pos-col" data-step="3">
        <div class="card">
          <h3 class="panel-heading">Order Summary</h3>

          <div id="sumCustomer" class="active-customer-box"></div>
          <div id="payDebtBadge" class="debt-badge hidden"></div>

          <div class="flex-between section-label">
            <span>Receipt No.</span>
            <span id="rNo" class="order-receipt-tag">OR-0000</span>
          </div>
        </div>

        <div class="card order-type-selector">
          <h3 class="panel-heading">Current Order</h3>
          <div class="cart-header-row">
            <span>Item</span>
            <span class="text-center">Qty</span>
            <span class="text-right">Amount</span>
          </div>
          <div id="cart" class="cart-items-container"></div>

          <div class="totals-breakdown">
            {{-- Inclusive VAT: gross is the shelf price, tax is extracted from it. --}}
            <div class="breakdown-row"><span>Vatable Sales</span><b id="tVatable">&#8369;0</b></div>
            <div class="breakdown-row"><span>12% VAT (inclusive)</span><b id="tVat">&#8369;0</b></div>
            <div class="breakdown-row"><span>Gallons</span><b id="tGal">0</b></div>
            <div class="total-due-banner"><span>Total:</span><b id="tTot">&#8369;0</b></div>
          </div>
        </div>

        <div class="card order-type-selector">
          <h3 class="panel-heading">Payment</h3>
          <div class="button-grid-3">
            <button id="pCash" onclick="setPay('Cash')" class="btn btn-primary">CASH</button>
            <button id="pGCash" onclick="setPay('GCash')" class="btn btn-ghost">GCASH / QR</button>
            <button id="pAccount" onclick="setPay('Account')" class="btn btn-ghost">ACCOUNT</button>
          </div>
          <p id="payLockNote" class="auto-deduct-caption"></p>

          <div id="tenderWrap" class="tender-amount-box">
            <div class="flex-between">
              <label class="form-label" for="tender">Amount Tendered</label>
              <span>Change: <b id="tChg">&#8369;0</b></span>
            </div>
            <input id="tender" type="number" value="0" min="0" oninput="renderPOS()" class="form-input">
            <div class="quick-cash-row">
              <button type="button" class="exact" onclick="applyQuickCash('exact')">Exact</button>
              <button type="button" onclick="applyQuickCash(50)">&#8369;50</button>
              <button type="button" onclick="applyQuickCash(100)">&#8369;100</button>
              <button type="button" onclick="applyQuickCash(500)">&#8369;500</button>
              <button type="button" onclick="applyQuickCash(1000)">&#8369;1,000</button>
            </div>
          </div>

          <div id="gcashBox" class="qr-payment-box hidden">
            <p class="panel-heading">GCash / QR Payment</p>
            <p class="auto-deduct-caption">Scan to pay the exact total. Confirm once the payment reflects.</p>
          </div>

          <div id="debtSettleBox" class="debt-settle-box hidden">
            <h4 class="panel-heading">Settle Existing Debt</h4>
            <div class="active-customer-box">
              <div class="flex-between">
                <span>Current Balance:</span>
                <b id="custDebtView" class="text-red-600">&#8369;0</b>
              </div>
              <div class="breakdown-row" style="margin-top:0.5rem;">
                <span>Payment Amount</span>
                <input id="debtPay" type="number" value="0" min="0" oninput="renderPOS()" class="text-right">
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

          <button id="btnComplete" onclick="completeSale(SESSION.name)" class="btn btn-primary btn-block">COMPLETE &amp; PRINT RECEIPT</button>
          <p id="deductNote" class="auto-deduct-caption"></p>
        </div>

        <div class="card orders-queue-panel">
          <div class="flex-between">
            <h3 class="panel-heading">Orders in Progress</h3>
            <a href="{{ url('/cashier/queue') }}" class="btn btn-ghost btn-sm">Open Queue</a>
          </div>
          <div id="queueStrip" class="custody-summary-box"></div>
        </div>
      </div>
    </div>

    {{-- Stage navigation. Lives inside the terminal so it reads as the panel's
         own footer rather than a detached strip across the bottom of the page.
         Validation messages appear right above the buttons that triggered them. --}}
    <div class="mobile-action-bar no-print">
      <p id="posStageError" class="stage-error hidden" role="alert"></p>
      <div class="mobile-action-bar-row">
        <button id="posBack" onclick="posPrev()" class="btn btn-ghost" disabled>Back</button>
        <button id="posNext" onclick="posNext()" class="btn btn-primary">Next</button>
      </div>
    </div>
  </main>

  {{-- Customer registration modal (name + address only) --}}
  <div id="registerModal" class="overlay-modal hidden">
    <div class="auth-card" style="max-width:400px;">
      <h3 class="panel-heading">Register New Customer</h3>
      <label class="form-label">Full Name</label>
      <input id="regName" class="form-input" placeholder="e.g. Juan Dela Cruz">
      <label class="form-label">Address</label>
      <input id="regAddr" class="form-input" placeholder="e.g. Barangay San Isidro">
      <div class="button-grid-2" style="margin-top:1rem;">
        <button onclick="closeRegisterModal()" class="btn btn-ghost">Cancel</button>
        <button onclick="saveNewCustomer()" class="btn btn-primary">Save</button>
      </div>
    </div>
  </div>

  {{-- Thermal receipt modal --}}
  <div id="receipt" class="overlay-modal hidden">
    <div class="receipt-card">
      <div class="receipt-header">
        <h2 class="receipt-brand">{{ strtoupper($stationName ?? 'AQUAFLOW STATION') }}</h2>
        <p class="receipt-sub">Water Refilling Station</p>
        <p id="rMeta" class="receipt-meta"></p>
      </div>
      <div id="rBody" class="receipt-body"></div>
      <div class="receipt-footer">
        THANK YOU FOR YOUR PATRONAGE!<br>
        VAT inclusive sales. This serves as your official receipt.
      </div>
      <div class="button-grid-2 no-print order-type-selector">
        <button onclick="printReceipt()" class="btn btn-ghost">Print</button>
        <button onclick="closeReceipt()" class="btn btn-primary">New Sale</button>
      </div>
    </div>
  </div>
@endsection

@push('scripts')
  <script>
    (async () => {
      window.SESSION = await bootCashierPortal();
      if (SESSION) {
        renderProducts();
        setType(POS.type);
        setPay(POS.pay);
        renderPOS();
        posStep(1);
      }
    })();
  </script>


@endpush
