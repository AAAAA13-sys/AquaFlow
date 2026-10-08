let attendanceRows = [], attendancePage = 1, attendanceRequest = 0, attendanceTimer;
let attendanceEditing = null, attendanceAuditPage = 1, attendanceAuditRequest = 0;
const attendanceLabels = {late:'Late',not_recorded:'Not recorded',checked_in:'Checked in',completed:'Completed',needs_review:'Needs review',absent:'Absent',leave:'On leave'};
function attendanceIsAdmin() { return document.getElementById('attendancePanel')?.dataset.admin === '1'; }
function attendanceTime(value) { return value ? value.slice(11,16) : '—'; }
function attendanceFilters() {
 const filters={page:attendancePage,search:document.getElementById('attendanceSearch').value,status:document.getElementById('attendanceStatus').value,order:document.getElementById('attendanceOrder').value};
 if(attendanceIsAdmin()) {
  if(document.getElementById('attendanceView').value==='history') filters.history=1;
  else filters.date=document.getElementById('attendanceDate').value;
 }
 return filters;
}
function attendanceViewChanged() {
 const history=document.getElementById('attendanceView').value==='history';
 document.getElementById('attendanceDate').disabled=history;
 const status=document.getElementById('attendanceStatus');
 status.querySelector('[value="not_recorded"]').disabled=history;
 if(history && status.value==='not_recorded') status.value='';
 attendanceFilterChanged();
}
function attendanceFilterChanged() { attendancePage=1; loadAttendance(); }
function searchAttendance() { clearTimeout(attendanceTimer); attendanceRequest++; attendancePage=1; attendanceTimer=setTimeout(loadAttendance,250); }
async function loadAttendance() {
 const body=document.getElementById('attendanceBody'); if(!body) return;
 const request=++attendanceRequest; body.setAttribute('aria-busy','true');
 try {
  const response=await API.getWithQuery('attendance',attendanceFilters());
  if(request!==attendanceRequest) return;
  if(attendancePage>response.meta.last_page) {attendancePage=response.meta.last_page; return loadAttendance();}
  attendanceRows=response.rows; document.getElementById('attendancePanel').dataset.today=response.today;
  const date=document.getElementById('attendanceDate'); if(date) date.max=response.today;
  document.getElementById('attendanceMessage').textContent='';
  body.innerHTML=attendanceRows.map((row,index)=>{
   let actions='';
   if(!attendanceIsAdmin() && row.attendance_date===response.today) {
    if(!row.id && ['not_recorded','absent'].includes(row.status) && row.active) actions='<button type="button" class="btn btn-primary btn-sm" onclick="punchAttendance('+index+',\'time-in\',this)">Time in</button>';
    if((row.progress || row.status)==='checked_in') actions='<button type="button" class="btn btn-primary btn-sm" onclick="punchAttendance('+index+',\'time-out\',this)">Time out</button>';
   }
   if(attendanceIsAdmin()) actions+='<button type="button" class="btn btn-ghost btn-sm" onclick="reviewAttendance('+index+')">View history</button>';
   return '<tr><td><b>'+esc(row.employee_name)+'</b><span class="cell-sub">'+esc(row.job_title)+(row.active ? '' : ' · Inactive')+'</span></td><td>'+esc(row.attendance_date)+'</td><td>'+esc(attendanceTime(row.time_in))+'<span class="cell-sub">'+esc(row.recorded_in_by || '')+'</span></td><td>'+esc(attendanceTime(row.time_out))+'<span class="cell-sub">'+esc(row.recorded_out_by || '')+'</span></td><td><span class="pill '+(row.status==='completed' ? 'pill-ok' : ['needs_review','late','absent'].includes(row.status) ? 'pill-warn' : 'pill-neutral')+'">'+esc(attendanceLabels[row.status])+'</span>'+(row.status==='late' ? '<span class="cell-sub">'+esc(attendanceLabels[row.progress] || '')+'</span>' : '')+'</td><td><div class="attendance-actions">'+(actions || '—')+'</div></td></tr>';
  }).join('') || '<tr><td colspan="6" class="empty-cell">No employees match this view. Try another date or clear the filters.</td></tr>';
  const meta=response.meta;
  document.getElementById('attendancePages').innerHTML='<button type="button" class="btn btn-ghost btn-sm" onclick="attendancePage--;loadAttendance()"'+(meta.current_page<=1 ? ' disabled' : '')+'>Previous</button><span>'+meta.total+' records · Page '+meta.current_page+' of '+meta.last_page+'</span><button type="button" class="btn btn-ghost btn-sm" onclick="attendancePage++;loadAttendance()"'+(meta.current_page>=meta.last_page ? ' disabled' : '')+'>Next</button>';
 } catch(error) {
  if(request!==attendanceRequest) return;
  body.innerHTML='<tr><td colspan="6" role="alert">'+esc(error.message || 'Unable to load attendance.')+' <button type="button" class="btn btn-ghost" onclick="loadAttendance()">Retry</button></td></tr>';
  document.getElementById('attendancePages').innerHTML='';
 } finally { if(request===attendanceRequest) body.removeAttribute('aria-busy'); }
}
let attendancePunch = null;
function closeAttendancePunch() {
 if(attendancePunch?.busy) return;
 attendancePunch=null;
 document.getElementById('attendancePunchEditor').classList.add('hidden');
}
function punchAttendance(index,action,button) {
 const row=attendanceRows[index]; if(!row || button.disabled || attendancePunch?.busy) return;
 attendancePunch={employeeId:row.employee_id,action,busy:false};
 const label=action==='time-in' ? 'time in' : 'time out';
 const rule=action==='time-in' ? 'Early arrivals need no reason. Arrivals from 8:01 AM onward require a late-arrival reason.' : 'A reason is required before completing nine hours, including breaks. Expected time out: '+esc((row.expected_time_out || '').replace('T', ' '))+'.';
 document.getElementById('attendancePunchBody').innerHTML='<h3>'+esc(row.employee_name)+'</h3><p>Confirm '+label+' for this employee. The server records the current station time.</p><p>'+rule+'</p><form class="attendance-form" onsubmit="return submitAttendancePunch(event)"><label for="attendancePunchReason">Reason for schedule exception</label><textarea id="attendancePunchReason" minlength="5" maxlength="500" rows="3" placeholder="For example: medical appointment or delayed arrival"></textarea><p class="cell-sub">Use 5-500 characters when a reason is needed. The admin can review this note.</p><p id="attendancePunchError" role="alert"></p><button type="submit" class="btn btn-primary">Confirm '+label+'</button></form>';
 document.getElementById('attendancePunchEditor').classList.remove('hidden');
 document.getElementById('attendancePunchReason').focus();
}
async function submitAttendancePunch(event) {
 event.preventDefault(); const punch=attendancePunch; if(!punch || punch.busy) return false;
 const button=event.currentTarget.querySelector('button[type="submit"]');
 const reason=document.getElementById('attendancePunchReason').value.trim();
 const error=document.getElementById('attendancePunchError'); error.textContent='';
 if(reason && (reason.length<5 || reason.length>500)) { error.textContent='Use 5-500 characters for the reason.'; return false; }
 punch.busy=true; button.disabled=true;
 try {
  await API.post('attendance/employees/'+punch.employeeId+'/'+punch.action,{reason:reason || null});
  punch.busy=false; closeAttendancePunch(); await loadAttendance();
 } catch(failure) {
  error.textContent=failure.message || 'Attendance was not saved. Please retry.';
  if(failure.data?.errors?.reason) document.getElementById('attendancePunchReason').focus();
 } finally { punch.busy=false; button.disabled=false; }
 return false;
}
function closeAttendanceEditor() { attendanceAuditRequest++; attendanceEditing=null; document.getElementById('attendanceEditor').classList.add('hidden'); }
function reviewAttendance(index) {
 const row=attendanceRows[index]; if(!row || !attendanceIsAdmin()) return;
 attendanceEditing={...row}; attendanceAuditPage=1;
 document.getElementById('attendanceEditorBody').innerHTML='<h3>'+esc(row.employee_name)+'</h3><p>Read-only attendance history. Recorded times and reasons cannot be edited.</p><div id="attendanceAudit"></div><nav id="attendanceAuditPages" class="attendance-pages" aria-label="Change history pages"></nav>';
 document.getElementById('attendanceEditor').classList.remove('hidden'); loadAttendanceAudit();
}
function attendanceSnapshot(snapshot) {
 if(!snapshot) return 'No previous record';
 return (attendanceLabels[snapshot.type] || snapshot.type)+' · In: '+(snapshot.time_in || '—')+' · Out: '+(snapshot.time_out || '—');
}
async function loadAttendanceAudit() {
 const row=attendanceEditing; if(!row) return; const request=++attendanceAuditRequest;
 try {
  const data=await API.getWithQuery('attendance/employees/'+row.employee_id+'/changes',{page:attendanceAuditPage});
  if(request!==attendanceAuditRequest) return;
  document.getElementById('attendanceAudit').innerHTML=data.data.map(change=>'<article class="attendance-audit"><b>'+esc(change.action)+' · '+esc(change.attendance_date)+'</b><p>'+esc(change.operator)+' · '+esc(change.created_at)+'</p><p>'+esc(change.reason || '')+'</p><p>Before: '+esc(attendanceSnapshot(change.before))+'</p><p>After: '+esc(attendanceSnapshot(change.after))+'</p></article>').join('') || '<p>No recorded changes yet.</p>';
  document.getElementById('attendanceAuditPages').innerHTML='<button type="button" class="btn btn-ghost btn-sm" onclick="attendanceAuditPage--;loadAttendanceAudit()"'+(data.current_page<=1 ? ' disabled' : '')+'>Previous</button><span>Page '+data.current_page+' of '+data.last_page+'</span><button type="button" class="btn btn-ghost btn-sm" onclick="attendanceAuditPage++;loadAttendanceAudit()"'+(data.current_page>=data.last_page ? ' disabled' : '')+'>Next</button>';
 } catch(error) { if(request===attendanceAuditRequest) document.getElementById('attendanceAudit').textContent=error.message; }
}
