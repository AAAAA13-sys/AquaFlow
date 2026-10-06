@extends('layouts.base')

@section('title', 'AquaFlow - Owner Login')
@section('body-class', 'auth-page owner-auth-theme')

@section('content')
  <main class="auth-card">
    <header class="auth-header">
      <div class="auth-logo auth-logo-dark">OWN</div>
      <div>
        <h1 class="auth-title">Owner Login</h1>
        <p class="auth-subtitle">{{ $stationName ?? 'AquaFlow' }} Owner portal</p>
      </div>
    </header>
    <p class="auth-description">Owner sign in here to open the Owner's portal.</p>
    <form class="auth-form" onsubmit="event.preventDefault(); doOwnerLogin();">
      @include('partials.login-credentials', ['usernameLabel' => 'Owner username'])

      <label class="form-label" for="pin">Owner PIN (4–12 digits)</label>
      <input id="pin" type="password" inputmode="numeric" required minlength="4" maxlength="12" pattern="[0-9]{4,12}" class="form-input" placeholder="Owner PIN">

      <div id="err" class="auth-error hidden">Invalid owner login.</div>

      <button type="submit" class="btn btn-primary btn-block">Open Owner Portal</button>
    </form>
    <p class="auth-footer-text">Are you the Staff? <a href="{{ url('/') }}" class="auth-link">Go to Cashier Login</a></p>
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
