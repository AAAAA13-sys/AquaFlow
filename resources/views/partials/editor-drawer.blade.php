{{-- Side editor drawer. Focus moves here when it opens and back to the opener
     when it closes; Escape presses its Close button (SideDrawerFocus in
     public/js/data/dialog.js). --}}
<div id="{{ $drawerId }}" class="side-drawer hidden" role="region" aria-labelledby="{{ $drawerId }}Title" tabindex="-1">
  <div class="flex-between order-type-selector">
    <h3 class="panel-heading" id="{{ $drawerId }}Title">{{ $title }}</h3>
    <button type="button" onclick="{{ $closeAction }}" class="btn btn-ghost btn-sm" data-drawer-close>Close</button>
  </div>
  <div id="{{ $bodyId }}"></div>
</div>
