<label class="form-label" for="u">{{ $usernameLabel }}</label>
<input id="u" class="form-input" placeholder="{{ $usernameLabel }}" autocomplete="username" required maxlength="50" pattern="[A-Za-z0-9_-]+">

<label class="form-label" for="p">Password</label>
<div class="credential-control">
  <input id="p" type="password" class="form-input" placeholder="Password" autocomplete="current-password" required maxlength="255">
  <button id="pToggle" type="button" class="btn btn-ghost btn-sm" aria-controls="p" aria-pressed="false" aria-label="Show or hide password" onclick="togglePassword('p', this)">Show</button>
</div>
