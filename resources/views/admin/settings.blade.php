@extends('layouts.admin')
@section('title', 'Settings | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Settings')
@section('admin-content')
<section id="s-settings" class="admin-page">
  @include('partials.admin-page-header', ['eyebrow' => 'STATION PREFERENCES', 'heading' => 'Settings', 'description' => 'Keep your station details and supply planning preferences up to date.'])
  <div class="settings-layout">
    <form class="card settings-fields" onsubmit="event.preventDefault(); saveSettings()">
      <div>
        <label class="form-label" for="setStation">Station name</label>
        <input id="setStation" value="{{ $stationName ?? 'AquaFlow Station' }}" class="form-input" required maxlength="120" aria-describedby="stationHelp">
        <p id="stationHelp" class="settings-help">Shown in the station portal and reports.</p>
      </div>
      <div>
        <label class="form-label" for="setLead">Restock planning reference</label>
        <input id="setLead" type="number" min="1" max="60" step="1" required value="{{ $settings['restock_lead_days'] ?? 3 }}" class="form-input" aria-describedby="leadHelp">
        <p id="leadHelp" class="settings-help">Your usual number of days between ordering and receiving supplies. Reorder calculations use the delivery time saved on each stock item.</p>
      </div>
      <div class="settings-actions">
        <button id="saveSettingsButton" type="submit" class="btn btn-primary">Save changes</button>
        <span id="settingsStatus" role="status" aria-live="polite"></span>
      </div>
    </form>
    <aside class="card settings-note">
      <h3>Planning your restock</h3>
      <p>Use a realistic delivery time so reorder alerts leave enough time to receive supplies.</p>
      <p>Review item and supplier delivery times in Stock &amp; Supplies and Suppliers as your purchasing arrangements change.</p>
    </aside>
  </div>
</section>
@endsection
@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Settings'])
loadSettings();
@endcomponent
