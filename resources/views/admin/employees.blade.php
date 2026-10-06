@extends('layouts.admin')
@section('title', 'Employees | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Employees')
@section('admin-content')
<section class="admin-page">
 <div class="admin-page-titlebar">
 @include('partials.admin-page-header',['eyebrow'=>'STORE TEAM','heading'=>'Employees','description'=>'Manage the people who refill, deliver and keep the store running. Login accounts are managed in Staff & Access.'])
 <button class="btn btn-primary" type="button" aria-controls="employeeFormPanel" aria-expanded="false" onclick="toggleAddForm(this)">Add employee</button>
 </div>
 <div id="employeeSummary" class="admin-summary"></div>
 <div id="employeeFormPanel" class="card hidden"><h3 class="panel-heading">Add employee</h3><div id="employeeCreate"></div></div>
 @include('partials.table-filters',['target'=>'employeeBody','label'=>'employees','refresh'=>'renderEmployees()'])
 <div class="data-table-wrapper"><table class="clean"><thead><tr><th>Employee</th><th>Job title</th><th>Contact number</th><th>Status</th><th>Actions</th></tr></thead><tbody id="employeeBody"></tbody></table></div>
</section>
@include('partials.editor-drawer',['drawerId'=>'employeeEditWrap','bodyId'=>'employeeEditBody','title'=>'Edit employee','closeAction'=>'closeEmployeeEditor()'])
@endsection
@component('partials.page-startup',['portal'=>'admin','pageTitle'=>'Employees'])
document.getElementById('employeeCreate').innerHTML=employeeForm();
await loadEmployees();
@endcomponent
