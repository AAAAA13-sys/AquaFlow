@extends('layouts.cashier')

@section('title', 'POS Terminal | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'POS Terminal')
@section('shell-class', 'cashier-viewport')

@section('cashier-body')
  @include('partials.cashier-steps')

  <main class="pos-layout">
    {{-- ===================================================================
         STAGE 1: Customer selection
    ==================================================================== --}}
    <section class="pos-stage pos-active" data-step="1">
      <div class="card stage-card">
        <div class="stage-toolbar">
          <input id="custSearch" oninput="renderCustList()" class="form-input"
                 placeholder="Search name or address..." autocomplete="off">
          <button onclick="openRegisterModal()" class="btn btn-ghost btn-sm">+ New Customer</button>
          <button onclick="toggleDebtSettle()" class="btn btn-ghost btn-sm">Settle Debt</button>
        </div>

        <div id="custCard" class="active-customer-banner"></div>
      </div>

      <div class="card stage-card stage-grow">
        <h3 class="panel-heading">Customer List</h3>
        <div id="custList" class="customer-list-container scroll-region"></div>
      </div>
    </section>

    {{-- ===================================================================
         STAGE 2: Order type & items
    ==================================================================== --}}
    <section class="pos-stage" data-step="2">
      <div class="stage-segment" role="group" aria-label="Order type">
        <button id="bWalk" onclick="setType('Walk-in')" class="seg-btn">🚶 Walk-In</button>
        <button id="bDel" onclick="setType('Delivery')" class="seg-btn">🚚 Delivery</button>
      </div>

      <p id="orderTypeNote" class="auto-deduct-caption"></p>

      <div class="card stage-card">
        <div class="products-grid" id="productGrid">
          {{-- Rendered from the catalog --}}
        </div>
      </div>

      <div class="card stage-card cart-tray">
        <div class="flex-between">
          <h3 class="panel-heading">Bought Items</h3>
          <span id="boughtCount" class="order-receipt-tag">0 items</span>
        </div>
        <div id="boughtItems" class="cart-items-container scroll-region"></div>
        <div class="total-due-banner"><span>Running Total:</span><b id="boughtTotal">&#8369;0</b></div>
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
            <div class="breakdown-row"><span>Vatable Sales</span><b id="tVatable">&#8369;0</b></div>
            <div class="breakdown-row"><span>12% VAT (inclusive)</span><b id="tVat">&#8369;0</b></div>
            <div class="breakdown-row"><span>Gallons</span><b id="tGal">0</b></div>
            <div class="total-due-banner"><span>Gross Total:</span><b id="tTot">&#8369;0</b></div>
          </div>
        </div>

        <div class="card stage-card">
          <div class="flex-between">
            <h3 class="panel-heading">Payment</h3>
            <span id="payBadge" class="order-receipt-tag">CASH</span>
          </div>

          {{-- Walk-in: cash only --}}
          <div id="cashPanel">
            <label class="form-label" for="tender">Amount Tendered</label>
            <input id="tender" type="number" value="0" min="0" oninput="renderPOS()" class="form-input tender-input">

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

            <p id="deductNote" class="auto-deduct-caption"></p>
          </div>

          {{-- Delivery: charged straight to the customer's ledger --}}
          <div id="deliveryPanel" class="hidden">
            <div class="ledger-notice">
              &#128203; Automatically charged to customer account. Driver will collect or bill on delivery.
            </div>

            <div class="active-customer-box">
              <div class="flex-between">
                <span>Outstanding balance before this order</span>
                <b id="deliveryBalance" class="text-red-600">&#8369;0</b>
              </div>
              <div class="flex-between" style="margin-top:0.4rem;">
                <span>This delivery</span>
                <b id="deliveryThis">&#8369;0</b>
              </div>
              <div class="breakdown-row" style="margin-top:0.4rem;border-top:1px solid var(--border-line);padding-top:0.4rem;">
                <span>Balance after dispatch</span>
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

          <a href="{{ url('/cashier/queue') }}" class="btn btn-ghost btn-sm btn-block queue-shortcut">
            View Orders in Progress
          </a>
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
        renderPOS();
        posStep(1);
      }
    })();
  </script>


@endpush
