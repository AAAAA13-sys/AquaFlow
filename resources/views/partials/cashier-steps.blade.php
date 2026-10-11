{{-- Stage indicator.

     Deliberately NOT interactive. These were buttons that jumped straight to a
     stage, which let a cashier skip past an incomplete one (no customer, empty
     cart) and contradicted the content validation on Back/Next. They are now a
     read-only progress display; posStep() still drives the active state. --}}
<div class="pos-step-wizard no-print" id="posSteps" role="list" aria-label="Order progress">
  <div class="pos-step active" data-step="1" role="listitem" aria-current="step">1. Customer</div>
  <div class="pos-step" data-step="2" role="listitem">2. Order Type &amp; Items</div>
  <div class="pos-step" data-step="3" role="listitem">3. Payment &amp; Receipt</div>
</div>
