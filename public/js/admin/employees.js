let employees = [];
function employeeForm(employee = null) {
 const id=employee ? employee.id : 0, prefix=id ? 'editEmployee' : 'newEmployee';
 return '<form class="admin-entry-form" onsubmit="return saveEmployee(event,'+id+')">'+
 '<div class="admin-entry-field"><label class="form-label" for="'+prefix+'Name">Full name</label><input class="form-input" id="'+prefix+'Name" name="name" required maxlength="120" value="'+esc(employee?.name || '')+'"></div>'+
 '<div class="admin-entry-field"><label class="form-label" for="'+prefix+'Job">Job title</label><input class="form-input" id="'+prefix+'Job" name="job_title" required maxlength="120" value="'+esc(employee?.job_title || '')+'"></div>'+
 '<div class="admin-entry-field"><label class="form-label" for="'+prefix+'Contact">Contact number (optional)</label><input class="form-input" id="'+prefix+'Contact" name="contact_number" type="tel" maxlength="50" value="'+esc(employee?.contact_number || '')+'"></div>'+
 '<div class="admin-entry-field"><label class="form-label" for="'+prefix+'Status">Employment status</label><select class="form-input" id="'+prefix+'Status" name="is_active"><option value="1">Active</option><option value="0"'+(employee && !employee.is_active ? ' selected' : '')+'>Inactive</option></select></div>'+
 '<p class="auth-error hidden" role="alert"></p><button class="btn btn-primary" type="submit">'+(id ? 'Save changes' : 'Add employee')+'</button></form>';
}
async function loadEmployees() {
 try { employees=(await API.get('employees')).employees || []; renderEmployees(); }
 catch(error) { document.getElementById('employeeBody').innerHTML='<tr><td colspan="5" role="alert">'+esc(error.message)+'</td></tr>'; }
}
function renderEmployees() {
 document.getElementById('employeeSummary').innerHTML='<div class="card"><span>Total employees</span><h3>'+employees.length+'</h3></div><div class="card"><span>Active</span><h3>'+employees.filter(e=>e.is_active).length+'</h3></div><div class="card"><span>Inactive</span><h3>'+employees.filter(e=>!e.is_active).length+'</h3></div>';
 const rows=filterTableRows(employees,'employeeBody');
 document.getElementById('employeeBody').innerHTML=rows.map(e=>'<tr><td><b>'+esc(e.name)+'</b></td><td>'+esc(e.job_title)+'</td><td>'+esc(e.contact_number || '—')+'</td><td><span class="pill '+(e.is_active ? 'pill-ok' : 'pill-neutral')+'">'+(e.is_active ? 'Active' : 'Inactive')+'</span></td><td><button class="btn btn-ghost btn-sm" onclick="editEmployee('+e.id+')">Edit</button> <button class="btn btn-ghost btn-sm" onclick="deleteEmployee('+e.id+')">Delete</button></td></tr>').join('') || '<tr><td colspan="5" class="empty-cell">No employees found. Add your first store employee above.</td></tr>';
}
function editEmployee(id) { const employee=employees.find(e=>e.id===id); if(!employee) return; document.getElementById('employeeEditBody').innerHTML=employeeForm(employee); document.getElementById('employeeEditWrap').classList.remove('hidden'); document.getElementById('editEmployeeName').focus(); }
function closeEmployeeEditor() { document.getElementById('employeeEditWrap').classList.add('hidden'); }
async function saveEmployee(event,id) {
 event.preventDefault(); const form=event.currentTarget, button=form.querySelector('button[type="submit"]'), error=form.querySelector('[role="alert"]');
 const data=Object.fromEntries(new FormData(form)); data.is_active=data.is_active==='1'; data.contact_number=data.contact_number || null;
 button.disabled=true; error.classList.add('hidden');
 try { await API.request('employees'+(id ? '/'+id : ''),{method:id ? 'PATCH' : 'POST',body:data}); if(id) closeEmployeeEditor(); else { form.reset(); document.getElementById('employeeFormPanel').classList.add('hidden'); document.querySelector('[aria-controls="employeeFormPanel"]').setAttribute('aria-expanded','false'); } await loadEmployees(); }
 catch(failure) { error.textContent=failure.message || 'Unable to save employee.'; error.classList.remove('hidden'); }
 finally { button.disabled=false; } return false;
}
async function deleteEmployee(id) { const employee=employees.find(e=>e.id===id); if(!employee || !confirm('Delete '+employee.name+' from the employee directory?')) return; try { await API.request('employees/'+id,{method:'DELETE'}); await loadEmployees(); } catch(error) { alert(error.message); } }
