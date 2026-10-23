@php
  // Plain-language labels stay visible; explanations live in accessible tips.
  // A station owner should never need to know what ROP or ARIMA means to use the
  // app, but the thesis still needs the precise term visible to a reviewer.
  //
  // `badge` names a <span> that renderSidebarBadges() fills with the count of
  // outstanding items, so an owner can see which pages need attention without
  // opening the overview first.
  // `featured` promotes one link. The ARIMA forecast is the analytical
  // centrepiece of the system, so it reads ahead of the operational screens it
  // feeds rather than trailing behind them as an equal-weight list item.
  $tabs = [
      'cash'       => ['label' => 'Cash Drawers',      'hint' => 'Starting cash & closing counts', 'badge' => null,             'icon' => 'i-vault'],
      'dashboard'  => ['label' => 'Overview',          'hint' => null,                             'badge' => null,             'icon' => 'i-dashboard'],
      'orders'     => ['label' => 'Orders & Deliveries','hint' => 'Handover & remittances',        'badge' => null,             'icon' => 'i-truck'],
      'arima'      => ['label' => 'Demand Forecast',   'hint' => 'ARIMA model',                    'badge' => null,             'icon' => 'i-chart'],
      'sales'      => ['label' => 'Sales History',     'hint' => 'POS logs',                       'badge' => null,             'icon' => 'i-receipt'],
      'customers'  => ['label' => 'Customer Balances', 'hint' => 'Customer accounts',             'badge' => 'badgeBalances',  'icon' => 'i-users'],
      'inventory'  => ['label' => 'Stock & Prices',    'hint' => 'Supplies & what cashier sells', 'badge' => 'badgeStock',     'icon' => 'i-package'],
      'suppliers'  => ['label' => 'Suppliers',         'hint' => null,                             'badge' => null,             'icon' => 'i-truck'],
      'defects'    => ['label' => 'Defect Reports',   'hint' => 'Damaged stock',                  'badge' => 'badgeDefects',   'icon' => 'i-alert'],
      'users'      => ['label' => 'Team & Access',     'hint' => 'People & permissions',           'badge' => null,             'icon' => 'i-walkin'],
      'approvals'  => ['label' => 'Approval Requests', 'hint' => 'Changes awaiting a decision',   'badge' => 'badgeApprovals', 'icon' => 'i-check-circle'],
      'audit-logs' => ['label' => 'Audit Log',         'hint' => 'Who changed what',               'badge' => null,             'icon' => 'i-orders'],
      'settings'   => ['label' => 'Settings',          'hint' => null,                             'badge' => null,             'icon' => 'i-terminal'],
      'cashier-pos' => ['label' => 'Cashier POS',      'hint' => 'Point of sale terminal',         'badge' => null,             'icon' => 'i-terminal', 'url' => '/cashier'],
      'staff-workspace' => ['label' => 'Rider & Refiller', 'hint' => 'Delivery & refill interface', 'badge' => null,           'icon' => 'i-walkin', 'url' => '/employee'],
  ];
  if (! auth()->user()?->isSuperAdmin()) { unset($tabs['settings']); }
  $groups = [
      'Analytics' => ['arima'],
      'Daily operations' => ['orders', 'cash', 'sales'],
      'Stock & purchasing' => ['inventory', 'suppliers', 'defects', 'customers'],
      'Management' => array_values(array_intersect(['users', 'approvals', 'audit-logs', 'settings'], array_keys($tabs))),
      'Staff Interfaces' => array_values(array_intersect(['cashier-pos', 'staff-workspace'], array_keys($tabs))),
  ];
@endphp
@php($active = request()->route('tab') ?? (request()->is('admin/settings') ? 'settings' : 'dashboard'))

<aside class="admin-sidebar no-print" id="adminSidebar">
  <div class="sidebar-header">
    <div class="sidebar-header-brand">
      <div class="sidebar-brand-badge">AF</div>
      <div>
        <h2 id="brandName" class="sidebar-brand-title">{{ $stationName ?? 'AquaFlow' }}</h2>
        <p class="sidebar-brand-subtitle">{{ auth()->user()?->isSuperAdmin() ? 'Owner / Super Admin' : 'Station Manager' }}</p>
      </div>
    </div>
    <button class="sidebar-close-btn" onclick="toggleSidebar(false)" aria-label="Close Sidebar">&times;</button>
  </div>

  <nav class="sidebar-nav" id="sideNav" aria-label="{{ auth()->user()?->isSuperAdmin() ? 'Owner' : 'Manager' }} page catalog">
    <a href="{{ url('/admin/dashboard') }}" data-s="dashboard"
       class="sidebar-link {{ $active === 'dashboard' ? 'active' : '' }}"
       @if ($active === 'dashboard') aria-current="page" @endif>
      <svg class="sidebar-icon" width="18" height="18" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#i-dashboard"></use></svg>
      <span class="sidebar-link-text">
        <span class="sidebar-link-label">Overview</span>
      </span>
    </a>
    @foreach ($groups as $group => $slugs)
    <details class="sidebar-group" name="admin-navigation" @if(in_array($active, $slugs, true)) open @endif>
      <summary class="sidebar-group-title">{{ $group }}</summary>
      <div class="sidebar-group-links">
      @foreach ($slugs as $slug)
        @php($label = $tabs[$slug])
        <?php $linkUrl = !empty($label['url']) ? url($label['url']) : url('/admin/' . $slug); ?>
        <a href="{{ $linkUrl }}" data-s="{{ $slug }}"
           class="sidebar-link {{ $active === $slug ? 'active' : '' }}"
           @if ($active === $slug) aria-current="page" @endif
           @if ($label['badge']) data-has-badge="1" @endif>
          @if (!empty($label['icon']))
            <svg class="sidebar-icon" width="18" height="18" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#{{ $label['icon'] }}"></use></svg>
          @endif
          <span class="sidebar-link-text">
            <span class="sidebar-link-label">{{ $label['label'] }}</span>
          </span>
          @if ($label['badge'])
            <span class="sidebar-badge" id="{{ $label['badge'] }}" hidden></span>
          @endif
        </a>
      @endforeach
      </div>
    </details>
    @endforeach
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user-card">
      <span class="sidebar-user-label">Active Session</span>
      <b class="sidebar-user-name" id="who">{{ auth()->user()?->name ?? 'Owner' }}</b>
    </div>
    <div class="sidebar-action-row">
      <button onclick="logout()" class="sidebar-logout-btn">Logout</button>
    </div>
  </div>
</aside>
