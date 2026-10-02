@extends('layouts.admin')

@section('title', 'Users & Access | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Users & Access')

@section('admin-content')
  <section id="s-users">
    <h2 class="auth-title">Users &amp; Access</h2>

    <div class="card" style="margin-bottom:1rem;">
      <h3 class="panel-heading">Add user</h3>
      <form id="userForm" onsubmit="return createUser(event)" class="filter-toolbar" style="flex-wrap:wrap;">
        <input id="usrName" class="form-input" placeholder="Full name *" required maxlength="100" style="min-width:160px;">
        <input id="usrUsername" class="form-input" placeholder="Username *" required maxlength="50" pattern="[A-Za-z0-9_-]+" title="Letters, numbers, dash and underscore only" style="min-width:140px;">
        <input id="usrPassword" type="password" class="form-input" placeholder="Password *" required minlength="4" style="min-width:140px;">
        <select id="usrRole" class="form-input" onchange="document.getElementById('usrPin').style.display = this.value === 'admin' ? '' : 'none'">
          <option value="cashier">Cashier (POS only)</option>
          <option value="admin">Owner (Full + Analytics)</option>
        </select>
        <input id="usrPin" type="password" class="form-input" placeholder="Owner PIN *" minlength="4" maxlength="12" style="display:none;width:130px;">
        <button type="submit" class="btn btn-primary btn-sm">Add</button>
      </form>
    </div>

    <div class="card data-table-wrapper">
      <table class="clean">
        <thead>
          <tr>
            <th>Username</th>
            <th>Name</th>
            <th>Role</th>
            <th>Access</th>
            <th>Active</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="usersBody"></tbody>
      </table>
    </div>
    <p class="auth-footer-text text-center">Cashiers can only open the POS terminal. Owners get full access and the analytics.</p>
  </section>

  <div id="userEditWrap" class="side-drawer hidden">
    <div class="flex-between order-type-selector">
      <h3 class="panel-heading">Edit user</h3>
      <button onclick="closeUserEditor()" class="btn btn-ghost btn-sm">Close</button>
    </div>
    <div id="userEditBody"></div>
  </div>
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
