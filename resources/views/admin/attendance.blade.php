@extends('layouts.admin')
@section('title', 'Attendance | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Attendance')
@section('admin-content')
@include('partials.attendance-panel',['isAdmin'=>true])
@endsection
@component('partials.page-startup',['portal'=>'admin','pageTitle'=>'Attendance'])
await loadAttendance();
startPageRefresh(loadAttendance);
@endcomponent
