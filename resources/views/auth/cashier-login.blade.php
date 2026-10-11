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
    <p class="auth-description">For cashiers, delivery riders and refillers.</p>
    <form class="auth-form" onsubmit="event.preventDefault(); doLogin();">
      @include('partials.login-credentials', ['usernameLabel' => 'Username'])

      <div id="err" class="auth-error hidden">Invalid username or password.</div>

      <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>
    <p class="auth-footer-text">Store administrator? <a href="{{ url('/owner/login') }}" class="auth-link">Admin Login</a></p>
  </main>

  @include('partials.scripts-base')

  <script>
    function finishLogin(result) {
      setSession(result.user);
      window.location.href = result.user.role === 'admin' ? '/admin' : (['rider', 'refiller'].includes(result.user.role) ? '/employee' : '/cashier');
    }
    async function doLogin() {
      const username = document.getElementById('u').value.trim();
      const password = document.getElementById('p').value;
      const errorElement = document.getElementById('err');
      const button = document.querySelector('button[type="submit"]');

      if (!username || !password) {
        errorElement.textContent = 'Enter username and password.';
        errorElement.classList.remove('hidden');
        return;
      }

      if (button) button.disabled = true;
      try {
        const result = await apiLogin(username, password);
        finishLogin(result);
        if (button) button.disabled = false;
      } catch (error) {
        errorElement.textContent = error.message || 'Invalid username or password.';
        errorElement.classList.remove('hidden');
        if (button) button.disabled = false;
      }
    }
  </script>
@endsection
