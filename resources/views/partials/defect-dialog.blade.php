<!-- Report Defect Dialog -->
<dialog id="defectReportDialog" class="defect-dialog" aria-labelledby="defectReportTitle">
  <div class="defect-card">
    <h3 id="defectReportTitle" class="defect-title">Report Defect or Damage</h3>
    <p class="defect-sub">A manager will review and write the units off stock.</p>
    <div class="defect-fields">
      <div>
        <label class="form-label" for="defectItem">Item</label>
        <select id="defectItem" class="form-input"></select>
      </div>
      <div>
        <label class="form-label" for="defectQty">Quantity</label>
        <input id="defectQty" type="number" min="1" class="form-input" placeholder="How many are damaged?">
      </div>
      <div>
        <label class="form-label" for="defectReason">Reason</label>
        <input id="defectReason" type="text" maxlength="255" class="form-input" placeholder="e.g. Leaking jug, torn seal, cracked cap">
      </div>
    </div>
    <p id="defectReportError" class="auth-error hidden" role="alert"></p>
    <div class="defect-actions">
      <button type="button" class="btn btn-ghost" onclick="closeDefectReportDialog()">Cancel</button>
      <button type="button" id="defectSubmitBtn" class="btn btn-primary" onclick="submitDefectReport()">Submit report</button>
    </div>
  </div>
</dialog>
