@extends('layouts.admin')

@section('title', 'Suppliers | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Suppliers')

@section('admin-content')
  <section id="s-sup">
    <h2 class="auth-title">Suppliers</h2>
    <p class="auth-description">Restock times on this page feed the reorder alerts in Consumables &amp; ROP.</p>
    <div class="suppliers-grid" id="supGrid"></div>
  </section>
@endsection

@push('scripts')
  <script>
    (async () => {
      window.SESSION = await bootAdminPortal('Suppliers');
      if (SESSION) {
        renderSup();
      }
    })();
  </script>
@endpush
