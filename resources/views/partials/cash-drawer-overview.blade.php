<section class="card cash-drawer-panel" id="cashDrawerPanel" data-overview="1">
  <div class="cash-panel-header"><div><h3 class="panel-heading">Drawer register</h3><p>Today's drawers, outstanding sessions and the 14 most recent closed records.</p></div><button class="btn btn-secondary" type="button" onclick="renderCashShift()">Refresh</button></div>
  <div class="cash-register-filters">
    <div><label class="form-label" for="cashOverviewSearch">Search operator or date</label><input class="form-input" id="cashOverviewSearch" type="search" placeholder="Name or YYYY-MM-DD" oninput="renderCashOverviewRows()"></div>
    <div><label class="form-label" for="cashOverviewStatus">Status</label><select class="form-input" id="cashOverviewStatus" onchange="renderCashOverviewRows()"><option value="">All statuses</option value="open">Open</option><option value="balanced">Balanced</option><option value="short">Short</option><option value="over">Over</option></select></div>
    <div><label class="form-label" for="cashOverviewSort">Sort</label><select class="form-input" id="cashOverviewSort" onchange="renderCashOverviewRows()"><option value="newest">New to Old</option><option value="oldest">Old to New</option></select></div>
  </div>
  <p id="cashStatus" class="cash-sync-status" role="status">Loading drawer information…</p>
  <div id="cashOverview"></div>
  <p class="settings-help">Collections include accepted debt payments and rider remittances. They add cash to the drawer without adding sales revenue.</p>
</section>
@push('scripts')
<script src="{{ asset('js/admin/cash.js') }}?v={{ asset_version('js/admin/cash.js') }}"></script>
@endpush
