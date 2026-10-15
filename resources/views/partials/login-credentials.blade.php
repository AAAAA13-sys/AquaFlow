<label class="form-label" for="u">{{ $usernameLabel }}</label>
<input id="u" class="form-input" placeholder="{{ $usernameLabel }}" autocomplete="username" required maxlength="50" pattern="[A-Za-z0-9_-]+">

<label class="form-label" for="p">Password</label>
<div class="credential-control">
  <input id="p" type="password" class="form-input" placeholder="Password" autocomplete="current-password" required maxlength="255">
  <button id="pToggle" type="button" class="password-toggle-btn" aria-controls="p" aria-pressed="false" aria-label="Show password" title="Show password" onclick="togglePassword('p', this)">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
  </button>
</div>
