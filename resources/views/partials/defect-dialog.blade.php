<!-- Report Defect Dialog -->
<dialog id="defectReportDialog" class="employee-issue-dialog" aria-labelledby="defectReportTitle" style="width:420px;max-width:calc(100vw - 24px);border-radius:14px;padding:0;box-shadow:0 12px 28px rgba(0,0,0,0.18);">
  <div class="receipt-card" style="padding:22px;background:#fff;">
    <h3 id="defectReportTitle" style="margin:0 0 4px;font-size:16px;color:#0f172a;">Report Defect or Damage</h3>
    <p style="margin:0 0 16px;font-size:12px;color:#64748b;">A manager will review and write the units off stock.</p>
    <div style="display:flex;flex-direction:column;gap:12px;">
      <div>
        <label class="form-label" for="defectItem">Item</label>
        <select id="defectItem" class="form-input" style="width:100%;box-sizing:border-box;"></select>
      </div>
      <div>
        <label class="form-label" for="defectQty">Quantity</label>
        <input id="defectQty" type="number" min="1" class="form-input" style="width:100%;box-sizing:border-box;" placeholder="How many are damaged?">
      </div>
      <div>
        <label class="form-label" for="defectReason">Reason</label>
        <input id="defectReason" type="text" maxlength="255" class="form-input" style="width:100%;box-sizing:border-box;" placeholder="e.g. Leaking jug, torn seal, cracked cap">
      </div>
    </div>
    <p id="defectReportError" class="auth-error hidden" role="alert" style="margin:12px 0 0;font-size:13px;"></p>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
      <button type="button" class="btn btn-ghost" onclick="closeDefectReportDialog()">Cancel</button>
      <button type="button" id="defectSubmitBtn" class="btn btn-primary" onclick="submitDefectReport()">Submit report</button>
    </div>
  </div>
</dialog>
