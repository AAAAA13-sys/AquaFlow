@extends('layouts.base')
@section('title', 'AquaFlow - Sign in')
@section('body-class', 'auth-page owner-auth-theme')
@section('content')
  @php($firstRun = \App\Models\User::where('is_bootstrap', true)->exists() && ! \App\Models\User::where('role', \App\Models\User::ROLE_SUPER_ADMIN)->where('is_bootstrap', false)->exists())
  <main class="auth-card" id="adminLogin" data-first-run="{{ $firstRun ? '1' : '0' }}">
    <header class="auth-header"><div class="auth-logo">AF</div><div><h1 class="auth-title">Sign in</h1><p class="auth-subtitle">{{ $stationName ?? 'AquaFlow' }} Station workspace</p></div></header>
    <p class="auth-description" id="loginDescription">{{ $firstRun ? 'First-time setup: enter the setup code printed by Setup on the store host. You then create your own Super Admin account before the store can be used.' : 'Sign in to your station workspace.' }}</p>
    <div id="err" class="auth-error hidden" role="alert"></div>
    @if($firstRun)
    <ol class="setup-progress" aria-label="Store setup steps"><li>Unlock installation</li><li>Enter station and owner details</li></ol>
    <p id="setupStep" class="setup-step-note" role="status">Step 1 of 2: Unlock installation</p>
    @endif
    <p id="loginStatus" role="status" aria-live="polite"></p>

    @if($firstRun)
    <form id="adminForm" class="auth-form" onsubmit="event.preventDefault(); submitAdminLogin();">
      <label class="form-label" for="otp">Setup code</label>
      <input id="otp" class="form-input" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" minlength="12" maxlength="12" required>
      <p class="auth-footer-text">Printed once by Setup on the store host. Lost it? Run Setup again for a new code.</p>
      <button type="submit" class="btn btn-primary btn-block">Continue to owner setup</button>
    </form>
    @else
    {{-- One step. The sign-in code was removed, so this is the whole form: the
         portal you land on is decided by the role, not by which page you used. --}}
    <form id="adminForm" class="auth-form" onsubmit="event.preventDefault(); submitLogin();">
      <label class="form-label" for="adminEmail">Username</label>
      <input id="adminEmail" class="form-input" autocomplete="username" required maxlength="50" pattern="[A-Za-z0-9_-]+" autofocus>
      <label class="form-label" for="p">Password</label>
      <div class="credential-control"><input id="p" type="password" class="form-input" autocomplete="current-password" required maxlength="255"><button type="button" class="password-toggle-btn" onclick="togglePassword('p', this)" aria-controls="p" aria-pressed="false" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
      <div style="display:flex;justify-content:flex-end;margin:6px 0 14px;"><a href="#" class="auth-link" onclick="event.preventDefault(); showForgotPasswordForm();" style="font-size:12px;">Forgot password?</a></div>
      <button type="submit" class="btn btn-primary btn-block">Login</button>
    </form>
    @endif
    @include('partials.password-recovery')
    <form id="setupForm" class="auth-form hidden" onsubmit="event.preventDefault(); verifySetup();">
      <h2>Create your Super Admin account</h2><p>Choose the username and password you will sign in with.</p>
      <label for="setupStation">Station name</label><input id="setupStation" class="form-input" autocomplete="organization" maxlength="100" required>
      <label for="setupName">Your name</label><input id="setupName" class="form-input" autocomplete="name" maxlength="20" required>
      <label for="setupUsername">Your username</label><input id="setupUsername" class="form-input" autocomplete="username" maxlength="50" pattern="[A-Za-z0-9_-]+" required>
      <label for="setupPassword">New password</label><div class="credential-control"><input id="setupPassword" type="password" class="form-input" autocomplete="new-password" minlength="8" maxlength="64" required><button type="button" class="password-toggle-btn" onclick="togglePassword('setupPassword', this)" aria-controls="setupPassword" aria-pressed="false" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
      <p>At least 8 characters with uppercase, lowercase and a number.</p>
      <label for="setupConfirm">Confirm password</label><div class="credential-control"><input id="setupConfirm" type="password" class="form-input" autocomplete="new-password" minlength="8" maxlength="64" required><button type="button" class="password-toggle-btn" onclick="togglePassword('setupConfirm', this)" aria-controls="setupConfirm" aria-pressed="false" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
      <label for="setupEmail">Recovery email</label><input id="setupEmail" type="email" required class="form-input" autocomplete="email" maxlength="255">
      <button type="submit" class="btn btn-primary btn-block">Finish setup</button>
    </form>
    <p class="auth-footer-text">Super Admin, Admin, Cashier, Refiller and Delivery Rider use this sign-in.</p>
  </main>
  @include('partials.scripts-base')
  <script src="{{ asset('js/auth/password-recovery.js') }}?v={{ asset_version('js/auth/password-recovery.js') }}"></script>
  <script src="{{ asset('js/auth/admin-login.js') }}?v={{ asset_version('js/auth/admin-login.js') }}"></script>
@endsection
