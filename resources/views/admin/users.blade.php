@extends('layouts.admin')

@section('title', 'Users & Access | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Users & Access')

@section('admin-content')
  <section id="s-users">
    <h2 class="auth-title">Users &amp; Access</h2>
    <div class="card data-table-wrapper">
      <table class="clean">
        <thead>
          <tr>
            <th>Username</th>
            <th>Name</th>
            <th>Role</th>
            <th>Access</th>
            <th>Active</th>
          </tr>
        </thead>
        <tbody id="usersBody"></tbody>
      </table>
    </div>
    <p class="auth-footer-text text-center">Cashiers can only open the POS terminal. Owners get full access and the analytics.</p>
  </section>
@endsection

@push('scripts')
  <script>
    (async () => {
      window.SESSION = await bootAdminPortal('Users & Access');
      if (SESSION) {
        renderUsers();
      }
    })();
  </script>
@endpush
