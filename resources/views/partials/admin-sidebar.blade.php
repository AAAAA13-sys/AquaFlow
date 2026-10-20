@php
  // Plain-language label first, technical term kept underneath as a subtitle.
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
      'dashboard'  => ['label' => 'Overview',        'hint' => null,             'badge' => null, 'featured' => false],
      'orders' => ['label' => 'Orders & Deliveries', 'hint' => 'Handover & remittances', 'badge' => null, 'featured' => false],
      'arima'      => ['label' => 'Demand Forecast', 'hint' => 'ARIMA model',    'badge' => null, 'featured' => false],
      'sales'      => ['label' => 'Sales History',   'hint' => 'POS logs',       'badge' => null, 'featured' => false],
      'customers'  => ['label' => 'Customer Balances','hint' => 'Customer accounts',   'badge' => 'badgeBalances', 'featured' => false],
      'inventory'  => ['label' => 'Stock & Prices',  'hint' => 'Supplies & what the cashier sells', 'badge' => 'badgeStock', 'featured' => false],
      'suppliers'  => ['label' => 'Suppliers',       'hint' => null,             'badge' => null, 'featured' => false],
      'defects'    => ['label' => 'Defect Reports', 'hint' => 'Damaged stock', 'badge' => 'badgeDefects', 'featured' => false],
      'users'      => ['label' => 'Team & Access',   'hint' => 'People & permissions', 'badge' => null, 'featured' => false],
      'approvals'  => ['label' => 'Approval Requests','hint' => 'Changes awaiting a decision', 'badge' => 'badgeApprovals', 'featured' => false],
      'audit-logs' => ['label' => 'Audit Log',       'hint' => 'Who changed what', 'badge' => null, 'featured' => false],
      'settings'   => ['label' => 'Settings',        'hint' => null,             'badge' => null, 'featured' => false],
  ];
  if (! auth()->user()?->isSuperAdmin()) { unset($tabs['settings']); }
  $slugs = array_keys($tabs);
@endphp
@php($active = request()->route('tab') ?? (request()->is('admin/settings') ? 'settings' : 'dashboard'))

<aside class="admin-sidebar no-print" id="adminSidebar">
  @include('partials.sidebar-header', ['subtitle' => auth()->user()?->isSuperAdmin() ? 'Owner / Super Admin' : 'Station Manager'])

  <nav class="sidebar-nav" id="sideNav" aria-label="{{ auth()->user()?->isSuperAdmin() ? 'Owner' : 'Manager' }} page catalog">
    @foreach ($slugs as $slug)
    @php($label = $tabs[$slug])
    <a href="{{ url('/admin/' . $slug) }}" data-s="{{ $slug }}"
       class="sidebar-link {{ $active === $slug ? 'active' : '' }} {{ $label['featured'] ? 'is-featured' : '' }}"
       @if ($active === $slug) aria-current="page" @endif
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
      <button onclick="logout()" class="sidebar-logout-btn">Logout</button>
    </div>
  </div>
</aside>
