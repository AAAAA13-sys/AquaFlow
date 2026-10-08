<section id="attendancePanel" class="attendance-panel" data-admin="{{ $isAdmin ? '1' : '0' }}" data-today="{{ now()->toDateString() }}">
 <header class="attendance-heading"><p class="cashier-eyebrow">STORE TEAM</p><h2>{{ $isAdmin ? 'Employee attendance' : 'Staff Attendance' }}</h2><p>{{ $isAdmin ? 'Read-only attendance and recorded reasons. Attendance cannot be edited.' : 'Record an employee’s arrival and departure when they report to you.' }}</p><p class="cell-sub">{{ now()->format('F j, Y') }} · {{ config('app.timezone') }} · Store hours 8 AM–5 PM</p></header>
 <div class="card attendance-toolbar">
 @if($isAdmin)
 <div><label for="attendanceView">View</label><select id="attendanceView" onchange="attendanceViewChanged()"><option value="day">Selected day</option><option value="history">Recorded history</option></select></div>
 <div><label for="attendanceDate">Date</label><input id="attendanceDate" type="date" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" onchange="attendanceFilterChanged()"></div>
 @endif
 <div><label for="attendanceSearch">Employee name</label><input id="attendanceSearch" type="search" maxlength="120" placeholder="Search employees" oninput="searchAttendance()"></div>
 <div><label for="attendanceStatus">Status</label><select id="attendanceStatus" onchange="attendanceFilterChanged()"><option value="">All statuses</option><option value="not_recorded">Not recorded</option><option value="late">Late</option><option value="checked_in">Checked in</option><option value="completed">Completed</option>@if($isAdmin)<option value="needs_review">Needs review</option>@endif<option value="absent">Absent</option><option value="leave">On leave</option></select></div>
 <div><label for="attendanceOrder">Sort</label><select id="attendanceOrder" onchange="attendanceFilterChanged()"><option value="newest">New to Old</option><option value="oldest">Old to New</option></select></div>
 <button type="button" class="btn btn-ghost" onclick="loadAttendance()">Refresh</button>
 </div>
 <p id="attendanceMessage" role="status" aria-live="polite"></p>
 <p class="cell-sub">No time in is “Not recorded” during store hours and “Absent” once the day closes. Early arrivals are allowed. Late arrivals and departures before nine elapsed hours require a reason; breaks are included. Missing departures remain visible; time out is never filled automatically.</p>
 <div class="card"><div class="data-table-wrapper"><table class="clean"><thead><tr><th>Employee</th><th>Date</th><th>Time in</th><th>Time out</th><th>Status</th><th>Actions</th></tr></thead><tbody id="attendanceBody"><tr><td colspan="6">Loading attendance…</td></tr></tbody></table></div><nav id="attendancePages" class="attendance-pages" aria-label="Attendance pages"></nav></div>
</section>
@if(!$isAdmin)
@include('partials.editor-drawer',['drawerId'=>'attendancePunchEditor','bodyId'=>'attendancePunchBody','title'=>'Confirm attendance','closeAction'=>'closeAttendancePunch()'])
@endif
@if($isAdmin)
@include('partials.editor-drawer',['drawerId'=>'attendanceEditor','bodyId'=>'attendanceEditorBody','title'=>'Attendance history','closeAction'=>'closeAttendanceEditor()'])
@endif
@push('styles')
<link rel="stylesheet" href="{{ asset('css/attendance.css') }}?v={{ asset_version('css/attendance.css') }}">
@endpush
@push('scripts')
<script src="{{ asset('js/attendance.js') }}?v={{ asset_version('js/attendance.js') }}"></script>
@endpush
