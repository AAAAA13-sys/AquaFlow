// Keep an uncertain delivery's original photo across reloads without putting image bytes in sessionStorage.
function deliveryPhoto(key, operation, file) {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('aquaflow-delivery-proofs', 1);
    request.onupgradeneeded = () => request.result.createObjectStore('photos');
    request.onerror = () => reject(new Error('Photo recovery storage is unavailable. Keep this tab open and try again.'));
    request.onsuccess = () => {
      const database = request.result;
      const transaction = database.transaction('photos', operation === 'get' ? 'readonly' : 'readwrite');
      const store = transaction.objectStore('photos');
      const action = operation === 'put' ? store.put(file, key) : operation === 'delete' ? store.delete(key) : store.get(key);
      transaction.oncomplete = () => { database.close(); resolve(action.result); };
      transaction.onerror = transaction.onabort = () => { database.close(); reject(new Error('Photo could not be saved for retry. Keep this tab open and try again.')); };
    };
  });
}

async function employeeRequestBody(pending) {
  if (pending.body.action !== 'deliver') return pending.body;
  const photo = await deliveryPhoto(pending.body.submission_key, 'get');
  if (!photo) throw new Error('The saved delivery photo is missing. Ask the cashier to check this order before clearing the saved action.');
  const form = new FormData();
  for (const [key, value] of Object.entries(pending.body)) if (value !== null) form.append(key, String(value));
  form.append('photo', photo, photo.name || 'delivery.jpg');
  return form;
}

function openDeliveryEvidence(job) {
  let dialog = document.getElementById('deliveryEvidence');
  if (!dialog) {
    dialog = document.createElement('dialog');
    dialog.id = 'deliveryEvidence';
    dialog.className = 'employee-issue-dialog';
    dialog.setAttribute('aria-labelledby', 'deliveryEvidenceTitle');
    dialog.innerHTML = `<form style="padding:24px;max-width:440px;display:grid;gap:12px" id="deliveryEvidenceForm">
      <h2 id="deliveryEvidenceTitle">Confirm delivery</h2><p id="deliveryEvidenceOrder"></p>
      <p>Ask the buyer to check the items and cash amount before sharing their private code. Delivery does not settle their account; cashier-accepted remittance does.</p>
      <label>Delivery photo (JPEG, PNG or WebP, up to 5 MB)<input name="photo" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" required></label>
      <label>Recipient name<input name="recipient_name" minlength="2" maxlength="100" required class="form-input"></label>
      <label>Buyer confirmation code<input name="recipient_code" inputmode="numeric" pattern="[0-9]{8}" minlength="8" maxlength="8" autocomplete="off" required class="form-input"></label>
      <label>Items received<input name="received_quantity" type="number" min="1" max="1000" step="1" required class="form-input"></label>
      <label>Cash collected from buyer<input name="reported_cash" type="number" min="0" step="0.01" required class="form-input"></label>
      <label>Reason for partial/no collection, if applicable<textarea name="collection_note" minlength="5" maxlength="500" class="form-input"></textarea></label>
      <p id="deliveryEvidenceError" role="alert"></p>
      <button type="submit" class="btn btn-primary">Save delivery and cash report</button>
      <button type="button" class="btn" onclick="document.getElementById('deliveryEvidence').close()">Cancel</button>
    </form>`;
    document.body.append(dialog);
  }
  const form = dialog.querySelector('form');
  form.reset();
  document.getElementById('deliveryEvidenceOrder').textContent = job.receipt + ' ? ' + job.customer + '. Quantity: ' + job.expected_quantity + '. Collect up to ' + Number(job.collection_limit || 0).toFixed(2) + '.';
  document.getElementById('deliveryEvidenceError').textContent = '';
  form.elements.reported_cash.max = job.collection_limit || 0;
  form.elements.reported_cash.value = job.collection_limit || 0;
  form.onsubmit = async event => {
    event.preventDefault();
    const values = Object.fromEntries(new FormData(form));
    const error = document.getElementById('deliveryEvidenceError');
    if (!values.photo?.size || values.photo.size > 5 * 1024 * 1024) { error.textContent = 'Choose a photo up to 5 MB.'; return; }
    if (Number(values.received_quantity) !== job.expected_quantity) { error.textContent = 'Received quantity must match the order. Report an issue if items are missing.'; return; }
    if (Number(values.reported_cash) < Number(job.collection_limit) && String(values.collection_note).trim().length < 5) { error.textContent = 'Explain why the full amount was not collected.'; return; }
    values.collection_note = String(values.collection_note).trim() || null;
    dialog.close();
    await changeJob(job, 'deliver', null, false, values);
  };
  dialog.showModal();
  form.elements.photo.focus();
}
