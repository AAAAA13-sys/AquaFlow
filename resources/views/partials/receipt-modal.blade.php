<div id="receipt" class="overlay-modal hidden" role="dialog" aria-modal="true" aria-label="Transaction receipt">
  <div class="receipt-card">
    <div class="receipt-header">
      <h2 class="receipt-brand">{{ strtoupper($stationName ?? 'AQUAFLOW STATION') }}</h2>
      <p class="receipt-sub">Water Refilling Station · Transaction receipt</p>
      <p id="rMeta" class="receipt-meta"></p>
    </div>
    <div id="rBody" class="receipt-body"></div>
    <div class="receipt-footer">
      Thank you for choosing our station.<br>
      Keep this receipt for your transaction records.
    </div>
    <div class="button-grid-2 no-print order-type-selector">
      <button onclick="printReceipt()" class="btn btn-ghost">Print</button>
      <button onclick="{{ $closeAction }}" class="btn btn-primary">{{ $closeLabel }}</button>
    </div>
  </div>
</div>
