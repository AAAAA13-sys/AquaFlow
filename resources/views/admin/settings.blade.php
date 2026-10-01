@extends('layouts.admin')

@section('title', 'Settings | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Settings')

@section('admin-content')
  <section id="s-settings">
    <h2 class="auth-title">Settings</h2>
    <div class="card auth-form settings-card">
      <div>
        <label class="form-label">Station name</label>
        <input id="setStation" value="{{ $stationName ?? 'AquaFlow Station' }}" class="form-input">
      </div>
      <div>
        <label class="form-label">Days until new supplies arrive (restock time)</label>
        <input id="setLead" type="number" value="{{ $settings['restock_lead_days'] ?? 3 }}" class="form-input">
      </div>
      <div>
        <label class="form-label">Target app rating (SUS)</label>
        <input id="setSus" value="{{ $settings['sus_target'] ?? '81.67 (Grade A)' }}" class="form-input">
      </div>
      <button onclick="saveSettings()" class="btn btn-primary order-type-selector">Save Settings</button>
    </div>
  </section>
@endsection

@push('scripts')
  <script>
    (async () => {
      window.SESSION = await bootAdminPortal('Settings');
      if (SESSION) {
        loadSettings();
      }
    })();
  </script>
@endpush
