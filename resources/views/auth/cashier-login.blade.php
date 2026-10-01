@extends('layouts.base')

@section('title', 'AquaFlow - Cashier Login')
@section('body-class', 'auth-page cashier-auth-theme')

@section('content')
  <main class="auth-card">
    <header class="auth-header">
      <div class="auth-logo">AF</div>
      <div>
        <h1 class="auth-title">Cashier Terminal</h1>
        <p class="auth-subtitle">{{ $stationName ?? 'AquaFlow' }} POS - staff only</p>
      </div>
    </header>
    <p class="auth-description">Cashiers sign in here to open the POS terminal. Owners use a separate owner login.</p>
    <form class="auth-form" onsubmit="event.preventDefault(); doLogin();">
      <label class="form-label" for="u">Cashier username</label>
      <input id="u" class="form-input" placeholder="Cashier username" autocomplete="username">

      <label class="form-label" for="p">Password</label>
      <input id="p" type="password" class="form-input" placeholder="Password" autocomplete="current-password">

      <div id="err" class="auth-error hidden">Invalid username or password.</div>

      <button type="submit" class="btn btn-primary btn-block">Open Cashier Terminal</button>
    </form>
    <p class="auth-footer-text">Are you the owner? <a href="{{ url('/owner/login') }}" class="auth-link">Go to Owner Login</a> (separate page, needs Owner PIN).</p>
  </main>

  @include('partials.scripts-base')

  <script>
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
        await apiLogin(username, password);
        window.location.href = '{{ url('/cashier') }}';
      } catch (error) {
        errorElement.textContent = error.message || 'Invalid username or password.';
        errorElement.classList.remove('hidden');
        if (button) button.disabled = false;
      }
    }
  </script>
@endsection
