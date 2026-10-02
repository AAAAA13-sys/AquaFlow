@extends('layouts.admin')

@section('title', 'Consumables & ROP | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Consumables & ROP')

@section('admin-content')
  <section id="s-inv">
    <h2 class="auth-title">Stock &amp; Supplies</h2>
    <p class="auth-description">
      Every item you keep in stock, and how long it will last at your current sales pace.
      AquaFlow works out the point where you should reorder automatically
      <span class="term-hint">(dynamic reorder point / ROP, set from past sales)</span>.
    </p>

    <div class="card data-table-wrapper">
      <table class="clean">
        <thead>
          <tr>
            <th>Item</th>
            <th>Type</th>
            <th class="num">In Stock <span class="term-hint">on-hand</span></th>
            <th class="num">Minimum <span class="term-hint">safety stock</span></th>
            <th class="num">Reorder When Below <span class="term-hint">ROP</span></th>
            <th>Lasts For</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="invBody"></tbody>
      </table>
    </div>
  </section>
@endsection

@push('scripts')
  <script>
    (async () => {
      window.SESSION = await bootAdminPortal('Stock & Supplies');
      if (SESSION) {
        renderInvTable();
      }
    })();
  </script>
@endpush
