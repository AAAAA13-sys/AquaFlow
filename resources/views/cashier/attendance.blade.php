@extends('layouts.cashier')
@section('title', 'Staff Attendance | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Staff Attendance')
@section('cashier-body')
<main class="admin-main-content cashier-page">@include('partials.attendance-panel',['isAdmin'=>false])</main>
@endsection
@component('partials.page-startup',['portal'=>'cashier'])
await loadAttendance();
startPageRefresh(loadAttendance);
@endcomponent
