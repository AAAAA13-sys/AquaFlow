@extends('layouts.base')

@section('title', 'AquaFlow - Employee Login')
@section('body-class', 'auth-page cashier-auth-theme')

@section('content')
  <main class="auth-card">
    <header class="auth-header">
      <div class="auth-logo">AF</div>
      <div>
        <h1 class="auth-title">Employee Login</h1>
        <p class="auth-subtitle">{{ $stationName ?? 'AquaFlow' }} Staff workspace</p>
      </div>
    </header>
    <p id="loginDescription" class="auth-description">For station cashiers.</p>
    <div id="err" class="auth-error hidden" role="alert"></div>
    <p id="loginStatus" role="status" aria-live="polite"></p>
    <form id="adminForm" class="auth-form" onsubmit="event.preventDefault(); doLogin();">
      @include('partials.login-credentials', ['usernameLabel' => 'Username'])

      <div style="display:flex;justify-content:flex-end;margin:6px 0 14px;"><a href="#" class="auth-link" onclick="event.preventDefault(); showForgotPasswordForm();" style="font-size:12px;">Forgot password?</a></div>

      <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>
    @include('partials.password-recovery')
    <p class="auth-footer-text">Store administrator? <a href="{{ url('/owner/login') }}" class="auth-link">Admin Login</a></p>
  </main>

  @include('partials.scripts-base')

  <script src="{{ asset('js/auth/password-recovery.js') }}?v={{ asset_version('js/auth/password-recovery.js') }}"></script>
  <script>
    function finishLogin(result) {
      setSession(result.user);
      window.location.href = result.user.role === 'admin' ? '/admin' : '/cashier';
    }
    async function doLogin() {
      const username = document.getElementById('u').value.trim();
      const password = document.getElementById('p').value;
      const button = document.querySelector('button[type="submit"]');

      if (!username || !password) {
        showLoginError('Enter username and password.');
        return;
      }

      if (button) button.disabled = true;
      try {
        const result = await apiLogin(username, password);
        finishLogin(result);
        if (button) button.disabled = false;
      } catch (error) {
        showLoginError(error.message || 'Invalid username or password.');
        if (button) button.disabled = false;
      }
    }
  </script>
@endsection
