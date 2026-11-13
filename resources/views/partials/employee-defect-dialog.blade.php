<!-- Report Defect / Damaged stock dialog.
     Shared by the employee queue page and the employee activity page. -->
<dialog id="empDefectDialog" class="employee-issue-dialog" aria-labelledby="empDefectTitle">
  <div class="emp-dialog-body">
    <h3 id="empDefectTitle" class="emp-dialog-title">Report Defect or Damage</h3>
    <p class="emp-dialog-sub">A manager will review and write the units off stock.</p>
    <div class="emp-dialog-fields">
      <div>
        <label class="form-label" for="empDefectItem">Item</label>
        <select id="empDefectItem" class="form-input"></select>
      </div>
      <div>
        <label class="form-label" for="empDefectQty">Quantity</label>
        <input id="empDefectQty" type="number" min="1" inputmode="numeric" class="form-input" placeholder="How many are damaged?">
      </div>
      <div>
        <label class="form-label" for="empDefectReason">Reason</label>
        <input id="empDefectReason" type="text" maxlength="255" class="form-input" placeholder="e.g. Leaking jug, torn seal">
      </div>
    </div>
    <p id="empDefectError" class="auth-error hidden" role="alert"></p>
    <div class="emp-dialog-actions">
      <button type="button" class="btn btn-ghost emp-dialog-btn" onclick="closeEmployeeDefectDialog()">Cancel</button>
      <button type="button" id="empDefectSubmitBtn" class="btn btn-primary emp-dialog-btn" onclick="submitEmployeeDefect()">Submit report</button>
    </div>
  </div>
</dialog>
