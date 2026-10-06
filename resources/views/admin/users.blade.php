@extends('layouts.admin')

@section('title', 'Staff & Access | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Staff & Access')

@section('admin-content')
  <section id="s-users" class="admin-page">
    <div class="admin-page-titlebar">
    @include('partials.admin-page-header', ['eyebrow' => 'TEAM MANAGEMENT', 'heading' => 'Staff & Access', 'description' => 'Manage your team and the access each person needs to run the station.'])
      <button type="button" class="btn btn-primary form-card" aria-expanded="false" aria-controls="userFormPanel" onclick="toggleAddForm(this)">Add user</button>
    </div>


<div id="teamSummary" class="admin-summary" aria-label="users summary"></div>
        <div id="userFormPanel" class="card form-card hidden">
      <h3 class="panel-heading">Add user</h3>
      <form id="userForm" onsubmit="return createUser(event)" class="admin-entry-form">
        <div class="admin-entry-field"><label class="form-label" for="usrName">Full name</label><input id="usrName" class="form-input field-min-160" placeholder="Full name *" required maxlength="100" aria-label="Full name"></div>
        <div class="admin-entry-field"><label class="form-label" for="usrUsername">Username</label><input id="usrUsername" class="form-input field-min-140" placeholder="Username *" required maxlength="50" pattern="[A-Za-z0-9_-]+" title="Letters, numbers, dash and underscore only" aria-label="Username"></div>
        <div class="admin-entry-field"><label class="form-label" for="usrPassword">Password</label><div class="credential-control"><input id="usrPassword" type="password" class="form-input field-min-140" placeholder="Password *" required minlength="8" maxlength="64" autocomplete="new-password" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])[\x21-\x7E]{8,64}" aria-label="Password"><button id="usrPasswordToggle" type="button" class="btn btn-ghost btn-sm" aria-controls="usrPassword" aria-pressed="false" onclick="togglePassword('usrPassword', this)">Show</button></div><p class="credential-hint">8–64 characters, with uppercase, lowercase and a number. No spaces.</p><button type="button" class="btn btn-secondary btn-sm" onclick="generateAccountPassword('usrPassword')">Generate password</button></div>
        <div class="admin-entry-field"><label class="form-label" for="usrRole">Access role</label><select id="usrRole" class="form-input" onchange="document.getElementById('usrPin').style.display = this.value === 'admin' ? '' : 'none'" aria-label="Access role">
          <option value="cashier">Cashier (POS only)</option>
          <option value="admin">Owner (Full + Analytics)</option>
        </select></div>
        <div class="admin-entry-field"><label class="form-label" for="usrPin">Owner PIN</label><input id="usrPin" type="password" class="form-input" placeholder="Owner PIN *" inputmode="numeric" pattern="[0-9]{4,12}" minlength="4" maxlength="12" style="display:none;width:130px;" aria-label="Owner PIN"></div>
        <p id="userCreateError" class="auth-error hidden" role="alert"></p>
        <button type="submit" class="btn btn-primary btn-sm">Create account</button>
      </form>
    </div>

    @include('partials.table-filters', ['target' => 'usersBody', 'label' => 'users', 'refresh' => "renderUsers()"])

    <div class="card data-table-wrapper">
      <table class="clean">
        <thead>
          <tr>
            <th>Username</th>
            <th>Name</th>
            <th>Role</th>
            <th>Access</th>
            <th>Active</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="usersBody"></tbody>
      </table>
    </div>
    <p class="auth-footer-text text-center">Cashiers can only open the POS terminal. Owners get full access and the analytics.</p>
  </section>

  @include('partials.editor-drawer', ['drawerId' => 'userEditWrap', 'bodyId' => 'userEditBody', 'title' => 'Edit user', 'closeAction' => 'closeUserEditor()'])
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Staff & Access'])
renderUsers();
@endcomponent
