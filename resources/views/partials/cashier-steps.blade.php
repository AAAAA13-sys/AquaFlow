{{-- Stage switcher. The terminal shows exactly one stage at a time at every
     screen size, so this bar is always available. --}}
<div class="pos-step-wizard no-print" id="posSteps">
  <button type="button" class="pos-step active" data-step="1" onclick="posStep(1)">1. Customer</button>
  <button type="button" class="pos-step" data-step="2" onclick="posStep(2)">2. Order Type &amp; Items</button>
  <button type="button" class="pos-step" data-step="3" onclick="posStep(3)">3. Payment &amp; Receipt</button>
</div>
