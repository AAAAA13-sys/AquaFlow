@extends('layouts.base')
@section('title', 'AquaFlow - Admin Login')
@section('body-class', 'auth-page owner-auth-theme')
@section('content')
  @php($firstRun = \App\Models\User::where('is_bootstrap', true)->exists() && ! \App\Models\User::where('role', 'admin')->where('is_bootstrap', false)->exists())
  <main class="auth-card" id="adminLogin" data-first-run="{{ $firstRun ? '1' : '0' }}">
    <header class="auth-header"><div class="auth-logo">AF</div><div><h1 class="auth-title">Admin Login</h1><p class="auth-subtitle">{{ $stationName ?? 'AquaFlow' }} Admin portal</p></div></header>
    <p class="auth-description" id="loginDescription">{{ $firstRun ? 'First-time setup: enter the setup email, password and code supplied with your installation. You must create and verify your own admin account before using the store.' : 'Enter your admin email and password, send an OTP, then enter the code from your inbox.' }}</p>
    <div id="err" class="auth-error hidden" role="alert"></div>
    <p id="loginStatus" role="status" aria-live="polite"></p>
    <form id="adminForm" class="auth-form" onsubmit="event.preventDefault(); submitAdminLogin();">
      <label class="form-label" for="adminEmail">Admin email</label>
      <input id="adminEmail" type="email" class="form-input" autocomplete="username" required maxlength="255">
      <label class="form-label" for="p">Password</label>
      <div class="credential-control"><input id="p" type="password" class="form-input" autocomplete="current-password" required maxlength="255"><button type="button" class="btn btn-ghost btn-sm" onclick="togglePassword('p', this)" aria-controls="p" aria-pressed="false">Show</button></div>
      <label class="form-label" for="otp">{{ $firstRun ? 'Setup code' : 'Email verification code' }}</label>
      <input id="otp" class="form-input" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" required>
      @unless($firstRun)<button type="button" class="btn btn-secondary" onclick="sendAdminOtp()">Send OTP</button>@endunless
      <button type="submit" class="btn btn-primary btn-block">{{ $firstRun ? 'Continue to admin setup' : 'Sign in' }}</button>
    </form>
    <form id="setupForm" class="auth-form hidden" onsubmit="event.preventDefault(); verifySetup();">
      <h2>Create your admin account</h2><p>Use your own email. Verify it to replace the temporary setup account.</p>
      <label for="setupName">Your name</label><input id="setupName" class="form-input" autocomplete="name" maxlength="100" required>
      <label for="setupEmail">Your admin email</label><input id="setupEmail" type="email" class="form-input" autocomplete="email" maxlength="255" required>
      <label for="setupPassword">New password</label><div class="credential-control"><input id="setupPassword" type="password" class="form-input" autocomplete="new-password" minlength="8" maxlength="64" required><button type="button" class="btn btn-ghost btn-sm" onclick="togglePassword('setupPassword', this)" aria-controls="setupPassword" aria-pressed="false">Show</button></div>
      <p>At least 8 characters with uppercase, lowercase and a number.</p>
      <label for="setupConfirm">Confirm password</label><input id="setupConfirm" type="password" class="form-input" autocomplete="new-password" minlength="8" maxlength="64" required>
      <button type="button" class="btn btn-secondary" onclick="sendSetupOtp()">Send OTP to my email</button>
      <label for="setupOtp">Email verification code</label><input id="setupOtp" class="form-input" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
      <button type="submit" class="btn btn-primary">Verify email and finish setup</button>
    </form>
    <section id="recoverySetup" class="hidden"><h2>Save your recovery codes</h2><p>Store these privately. Each works once with your password if email is unavailable.</p><pre id="recoveryCodes"></pre><a href="/admin" class="btn btn-primary">I saved my codes — continue</a></section>
    <p class="auth-footer-text">Cashier, rider or refiller? <a href="{{ url('/') }}" class="auth-link">Employee Login</a></p>
  </main>
  @include('partials.scripts-base')
  <script src="{{ asset('js/auth/admin-login.js') }}?v={{ asset_version('js/auth/admin-login.js') }}"></script>
@endsection
