@unless($embedded ?? false)
<div class="filter-toolbar no-print table-filters">
@endunless
  <div class="filter-group">
    <label class="form-label" for="{{ $target }}Search">Search</label>
    <input id="{{ $target }}Search" type="search" class="form-input" placeholder="Search {{ $label }}" oninput="{{ $refresh }}">
  </div>
  @if(!($noSort ?? false))
  <div class="filter-group">
    <label class="form-label" for="{{ $target }}Order">Sort</label>
    <select id="{{ $target }}Order" onchange="{{ $refresh }}">
      <option value="newest">New to Old</option>
      <option value="oldest">Old to New</option>
    </select>
  </div>
  @endif
@unless($embedded ?? false)
</div>
@endunless
