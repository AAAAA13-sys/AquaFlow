@php
  // Plain-language label first, technical term kept underneath as a subtitle.
  // A station owner should never need to know what ROP or ARIMA means to use the
  // app, but the thesis still needs the precise term visible to a reviewer.
  //
  // `badge` names a <span> that renderSidebarBadges() fills with the count of
  // outstanding items, so an owner can see which pages need attention without
  // opening the overview first.
  $tabs = [
      'dashboard'  => ['label' => 'Overview',        'hint' => null,             'badge' => null],
      'sales'      => ['label' => 'Sales History',   'hint' => 'POS logs',       'badge' => null],
      'arima'      => ['label' => 'Demand Forecast', 'hint' => 'ARIMA model',    'badge' => null],
      'inventory'  => ['label' => 'Stock & Supplies','hint' => 'Consumables & reorder points', 'badge' => 'badgeStock'],
      'customers'  => ['label' => 'Customer Balances','hint' => 'Liabilities',   'badge' => 'badgeBalances'],
      'suppliers'  => ['label' => 'Suppliers',       'hint' => null,             'badge' => null],
      'employees'  => ['label' => 'Employees', 'hint' => 'Store team', 'badge' => null],
      'users'      => ['label' => 'Staff & Access',  'hint' => 'Users',          'badge' => null],
      'settings'   => ['label' => 'Settings',        'hint' => null,             'badge' => null],
  ]
@endphp
@php($active = request()->route('tab') ?? 'dashboard')

<aside class="admin-sidebar no-print" id="adminSidebar">
  @include('partials.sidebar-header', ['subtitle' => 'Station Owner Portal'])

  <nav class="sidebar-nav" id="sideNav">
    @foreach ($tabs as $slug => $label)
      <a href="{{ url('/admin/' . $slug) }}" data-s="{{ $slug }}"
         class="sidebar-link {{ $active === $slug ? 'active' : '' }}"
         @if ($label['badge']) data-has-badge="1" @endif>
        <span class="sidebar-link-text">
          <span class="sidebar-link-label">{{ $label['label'] }}</span>
          @if ($label['hint'])
            <span class="sidebar-link-hint">{{ $label['hint'] }}</span>
          @endif
        </span>
        @if ($label['badge'])
          <span class="sidebar-badge" id="{{ $label['badge'] }}" hidden></span>
        @endif
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
