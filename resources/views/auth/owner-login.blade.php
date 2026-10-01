@extends('layouts.base')

@section('title', 'AquaFlow - Owner Login')
@section('body-class', 'auth-page owner-auth-theme')

@section('content')
  <main class="auth-card">
    <header class="auth-header">
      <div class="auth-logo auth-logo-dark">OWN</div>
      <div>
        <h1 class="auth-title">Owner Login</h1>
        <p class="auth-subtitle">{{ $stationName ?? 'AquaFlow' }} analytics portal - owners only</p>
      </div>
    </header>
    <p class="auth-description">This is a different login from the cashier terminal. Owners enter username, password, plus a private Owner PIN.</p>
    <form class="auth-form" onsubmit="event.preventDefault(); doOwnerLogin();">
      <label class="form-label" for="u">Owner username</label>
      <input id="u" class="form-input" placeholder="Owner username" autocomplete="username">

      <label class="form-label" for="p">Password</label>
      <input id="p" type="password" class="form-input" placeholder="Password" autocomplete="current-password">

      <label class="form-label" for="pin">Owner PIN (4 digits)</label>
      <input id="pin" type="password" inputmode="numeric" maxlength="4" class="form-input" placeholder="Owner PIN">

      <div id="err" class="auth-error hidden">Invalid owner login.</div>

      <button type="submit" class="btn btn-primary btn-block">Open Owner Portal</button>
    </form>
    <p class="auth-footer-text">Cashier? <a href="{{ url('/') }}" class="auth-link">Go to Cashier Login</a> (no PIN needed there).</p>
  </main>

  @include('partials.scripts-base')

  <script>
    async function doOwnerLogin() {
      const username = document.getElementById('u').value.trim();
      const password = document.getElementById('p').value;
      const pin = document.getElementById('pin').value.trim();
      const errorElement = document.getElementById('err');
      const button = document.querySelector('button[type="submit"]');

      if (!username || !password || !pin) {
        errorElement.textContent = 'Enter username, password, and Owner PIN.';
        errorElement.classList.remove('hidden');
        return;
      }

      if (button) button.disabled = true;
      try {
        await apiLoginOwner(username, password, pin);
        window.location.href = '{{ url('/admin') }}';
      } catch (error) {
        errorElement.textContent = error.message || 'Invalid credentials.';
        errorElement.classList.remove('hidden');
        if (button) button.disabled = false;
      }
    }
  </script>
@endsection
