@php
  // Plain-language label first, technical term kept underneath as a subtitle.
  // A station owner should never need to know what ROP or ARIMA means to use the
  // app, but the thesis still needs the precise term visible to a reviewer.
  $tabs = [
      'dashboard'  => ['label' => 'Overview',        'hint' => null],
      'sales'      => ['label' => 'Sales History',   'hint' => 'POS logs'],
      'arima'      => ['label' => 'Demand Forecast', 'hint' => 'ARIMA model'],
      'inventory'  => ['label' => 'Stock & Supplies','hint' => 'Consumables & reorder points'],
      'customers'  => ['label' => 'Customer Balances','hint' => 'Liabilities'],
      'suppliers'  => ['label' => 'Suppliers',       'hint' => null],
      'users'      => ['label' => 'Staff & Access',  'hint' => 'Users'],
      'settings'   => ['label' => 'Settings',        'hint' => null],
  ]
@endphp
@php($active = request()->route('tab') ?? 'dashboard')

<aside class="admin-sidebar no-print" id="adminSidebar">
  <div class="sidebar-header">
    <div class="sidebar-header-brand">
      <div class="sidebar-brand-badge">AF</div>
      <div>
        <h2 id="brandName" class="sidebar-brand-title">{{ $stationName ?? 'AquaFlow' }}</h2>
        <p class="sidebar-brand-subtitle">Station Owner Portal</p>
      </div>
    </div>
    <button class="sidebar-close-btn" onclick="toggleSidebar(false)" aria-label="Close Sidebar">&times;</button>
  </div>

  <nav class="sidebar-nav" id="sideNav">
    @foreach ($tabs as $slug => $label)
      <a href="{{ url('/admin/' . $slug) }}" data-s="{{ $slug }}"
         class="sidebar-link {{ $active === $slug ? 'active' : '' }}">
        <span class="sidebar-link-text">
          <span class="sidebar-link-label">{{ $label['label'] }}</span>
          @if ($label['hint'])
            <span class="sidebar-link-hint">{{ $label['hint'] }}</span>
          @endif
        </span>
      </a>
    @endforeach
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user-card">
      <span class="sidebar-user-label">Active Session</span>
      <b class="sidebar-user-name" id="who">{{ auth()->user()?->name ?? 'Owner' }}</b>
    </div>
    <div class="sidebar-action-row">
      <a href="{{ url('/cashier') }}" class="sidebar-pos-link">POS Terminal</a>
      <button onclick="logout()" class="sidebar-logout-btn">Logout</button>
    </div>
  </div>
</aside>
