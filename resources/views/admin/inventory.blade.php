@extends('layouts.admin')

@section('title', 'Consumables & ROP | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Consumables & ROP')

@section('admin-content')
  <section id="s-inv">
    <h2 class="auth-title">Consumables &amp; ROP</h2>
    <p class="auth-description">Dynamic reorder point thresholds calculate the precise stock level to initiate supplier replenishment.</p>

    <div class="card data-table-wrapper">
      <table class="clean">
        <thead>
          <tr>
            <th>Item</th>
            <th>Category</th>
            <th class="num">On-hand</th>
            <th class="num">Safety</th>
            <th class="num">Reorder at</th>
            <th>Restock (days)</th>
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
      window.SESSION = await bootAdminPortal('Consumables & ROP');
      if (SESSION) {
        renderInvTable();
      }
    })();
  </script>
@endpush
