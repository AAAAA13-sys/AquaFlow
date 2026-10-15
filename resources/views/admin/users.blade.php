@extends('layouts.admin')

@section('title', 'Team & Access | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Team & Access')

@section('admin-content')
  <section id="s-users" class="admin-page">
    <div class="admin-page-titlebar">
    @include('partials.admin-page-header', ['eyebrow' => 'TEAM MANAGEMENT', 'heading' => 'Team & Access', 'description' => 'Manage your team and the access each person needs to run the station.'])
      <button type="button" class="btn btn-primary form-card" aria-expanded="false" aria-controls="userFormPanel" onclick="toggleAddForm(this)">Add person</button>
    </div>


<div id="teamSummary" class="admin-summary" aria-label="users summary"></div>
        <div id="userFormPanel" class="card form-card hidden">
      <h3 class="panel-heading">Add user</h3>
      <form id="userForm" onsubmit="return createUser(event)" class="admin-entry-form user-account-form">
        <div class="admin-entry-field"><label class="form-label" for="usrName">Full name</label><input id="usrName" class="form-input field-min-160" placeholder="Full name *" required maxlength="20" aria-label="Full name"></div>
        <div class="admin-entry-field"><label class="form-label" for="usrUsername">Username</label><input id="usrUsername" class="form-input field-min-140" placeholder="Username *" required maxlength="50" pattern="[A-Za-z0-9_-]+" title="Letters, numbers, dash and underscore only" aria-label="Username"></div>
        <div class="admin-entry-field"><label class="form-label" for="usrRole">Access role</label><select id="usrRole" onchange="updateUserRoleFields()" class="form-input"  aria-label="Access role">
          <option value="cashier">Cashier (POS only)</option>
          <option value="admin">Owner / Admin (Full + Analytics)</option>
        </select></div>
        <div class="admin-entry-field"><label for="usrEmail">Recovery email</label><input id="usrEmail" type="email" required autocomplete="email" class="form-input" maxlength="255"></div>
        <div id="usrPasswordField" class="admin-entry-field hidden">
          <label class="form-label" for="usrPassword">Admin password</label>
          <div class="credential-control">
            <input id="usrPassword" type="password" class="form-input" autocomplete="new-password" minlength="8" maxlength="64" disabled aria-describedby="usrPasswordHint">
            <button type="button" class="password-toggle-btn" onclick="togglePassword('usrPassword', this)" aria-controls="usrPassword" aria-pressed="false" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></button>
          </div>
          <p id="usrPasswordHint" class="credential-hint">8–64 characters with uppercase, lowercase and a number.</p>
        </div>
        <p id="usrTemporaryHint" class="credential-hint user-form-wide">Cashiers start with temporary password <b>12345678</b> and must change it at first sign-in. Use an email address they can access for recovery.</p>
        <p id="userCreateError" class="auth-error hidden" role="alert"></p>
        <button id="userCreateButton" type="submit" class="btn btn-primary btn-sm">Create account</button>
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
    <p class="auth-footer-text text-center">Cashiers use the cashier portal. Owners manage the station and analytics.</p>
  </section>

  @include('partials.editor-drawer', ['drawerId' => 'userEditWrap', 'bodyId' => 'userEditBody', 'title' => 'Edit user', 'closeAction' => 'closeUserEditor()'])
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Team & Access'])
updateUserRoleFields();
renderUsers();
startPageRefresh(async () => { await loadFromServer(); renderUsers(); renderSidebarBadges(); renderStamp(); });
@endcomponent
