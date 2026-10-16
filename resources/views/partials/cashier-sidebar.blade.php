@php($items = [
    ['url' => '/cashier', 'icon' => 'i-terminal', 'label' => 'POS Terminal', 'match' => 'cashier.index'],
    ['url' => '/cashier/queue', 'icon' => 'i-orders', 'label' => 'Orders in Progress', 'match' => 'cashier.queue'],
    ['url' => '/cashier/history', 'icon' => 'i-receipt', 'label' => 'Sales & Transactions', 'match' => 'cashier.history'],
    ['url' => '/cashier/stocks', 'icon' => 'i-package', 'label' => 'Current Stocks', 'match' => 'cashier.stocks'],
])

<aside class="admin-sidebar no-print" id="adminSidebar">
  @include('partials.sidebar-header', ['subtitle' => 'Cashier Terminal'])

  <nav class="sidebar-nav" id="sideNav" aria-label="Cashier page catalog">
    <p class="sidebar-section-title">Daily operations</p>
    @foreach ($items as $item)
      <a href="{{ url($item['url']) }}"
         class="sidebar-link {{ request()->routeIs($item['match']) ? 'active' : '' }}" @if(request()->routeIs($item['match'])) aria-current="page" @endif>
        <svg class="icon" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#{{ $item['icon'] }}"></use></svg>
        <span>{{ $item['label'] }}</span>
      </a>
    @endforeach
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user-card sidebar-user-card-with-gear">
      <div class="sidebar-user-meta">
        <span class="sidebar-user-label">Active Session</span>
        <b class="sidebar-user-name" id="who">{{ auth()->user()?->name ?? 'Cashier' }}</b>
      </div>
      <button type="button" class="sidebar-gear-btn" onclick="openProfileModal()" aria-label="Profile Settings" title="Profile Settings">
        <svg class="sidebar-gear-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="3"></circle>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
        </svg>
      </button>
    </div>

    {{-- Sits beside Logout, mirroring the owner portal's "POS Terminal |
         Logout" row, so leaving the terminal is a control in the same place a
         cashier expects. Owner/admin sessions only: a real cashier never sees
         a link into a portal they cannot use. --}}
    <div class="sidebar-action-row">
      @if (auth()->user()?->isAdmin())
        <a href="{{ url('/admin/dashboard') }}" class="sidebar-back-link">
          {{-- A text glyph rather than a sprite symbol: the sprite has no
               i-arrow-left, and a missing <use> renders as an empty box. --}}
          <span class="sidebar-back-arrow" aria-hidden="true">&larr;</span>
          <span>Owner Portal</span>
        </a>
      @endif
      <button onclick="confirmCashierLogout()" class="sidebar-logout-btn">Logout</button>
    </div>
  </div>
</aside>
