let fulfillmentFlight = false;
let fulfillmentBusy = false;
function fulfillmentKey() { return 'aquaflow_fulfillment:' + getSession().u; }

function renderFulfillmentMonitor(container, data) {
  const totals = {preparing:0, ready:0, out_for_delivery:0, delivered:0, ...data.quantities};
  const labels = {preparing:'Preparing',ready:'Ready',out_for_delivery:'Out for delivery',delivered:'Delivered today'};
  container.innerHTML = '<div class="delivery-monitor-totals">'+Object.entries(labels).map(([key,label])=>'<div><span>'+label+'</span> <b>'+Number(totals[key])+'</b> <small>delivery items</small></div>').join('')+'</div>' +
    '<div class="data-table-wrapper" tabindex="0" role="region" aria-label="Order and delivery monitoring"><table><thead><tr><th scope="col">Order / customer</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Items</th><th scope="col">Rider</th><th scope="col">Pending remittance</th><th scope="col">Issues / evidence</th></tr></thead><tbody>' +
    (data.orders.map(job=>'<tr><td><b>'+esc(job.receipt)+'</b><span class="cash-cell-sub">'+esc(job.customer)+'</span></td><td>'+esc(job.order_type)+'</td><td>'+esc(job.status === 'delivered' ? 'Delivered' : labels[job.status] || job.status)+'</td><td class="table-item-cell"><span class="table-item-lines">'+esc(job.description).replace(/,\s*(?=\d+\s*[×x]\s)/g, '\n')+'</span></td><td>'+(job.rider_id?'Rider #'+Number(job.rider_id):job.order_type==='Delivery'?'Awaiting FIFO dispatch':'—')+'</td><td>'+money(job.proof?.pending_remittance || 0)+'</td><td>'+(job.issues?.length?'<span>'+job.issues.map(esc).join('; ')+'</span>':'No reported issues')+(job.proof?'<button type="button" class="btn btn-ghost btn-sm" onclick="viewDeliveryHistory('+Number(job.id)+')">View evidence</button>':'')+'</td></tr>').join('') || '<tr><td colspan="7" class="empty-cell">No active orders or outstanding remittances.</td></tr>')+'</tbody></table></div>';
  document.getElementById('fulfillmentStatus').textContent = 'Updated '+new Date(data.updated_at).toLocaleTimeString()+'. Quantities above count delivery items, not sales revenue.';
}

async function sendFulfillmentAction(job, action, extra = {}) {
  if (fulfillmentBusy) return;
  fulfillmentBusy = true;
  try {
    let pending = JSON.parse(sessionStorage.getItem(fulfillmentKey()) || 'null');
    if (job && pending) throw new Error('Retry the saved handover before changing another order.');
    if (!pending) {
      if (!job || !await appConfirm('Confirm ' + action.replaceAll('_', ' ') + ' for ' + job.receipt + '?', 'Confirm order action')) return;
      pending = {id:job.id, body:{action, ...extra, version:job.version, submission_key:newSubmissionKey()}};
      sessionStorage.setItem(fulfillmentKey(), JSON.stringify(pending));
    }
    const result = await API.request('fulfillment/' + pending.id, {method:'POST', body:pending.body});
    if (!result?.order) throw new Error('Order response was incomplete. Retry the saved action.');
    sessionStorage.removeItem(fulfillmentKey());
  } catch (error) {
    if ([400,401,403,404,409,419,422].includes(error.status)) sessionStorage.removeItem(fulfillmentKey());
    appNotice(error.message || 'Action could not be confirmed. Retry the saved action.');
  } finally { fulfillmentBusy = false; await refreshFulfillment(true); }
}

async function acceptDeliveryRemittance(job, form) {
  if (fulfillmentBusy || form.dataset.accepted === 'true' || !form.reportValidity()) return;
  fulfillmentBusy = true;
  const button = form.querySelector('button[type="submit"]');
  button.disabled = true;
  try {
    const amount = Number(form.elements.amount.value);
    const note = form.elements.note.value.trim() || null;
    if (!await appConfirm('Accept ' + amount.toFixed(2) + ' physically counted cash from rider #' + job.rider_id + ' for ' + job.receipt + '? This reduces buyer debt and adds cash to your drawer.', 'Accept remittance')) return;
    const result = await Submissions.send('deliveries/' + job.id + '/remit', {amount, note});
    form.dataset.accepted = 'true';
    if (result.customer) API.replaceCustomer(result.customer);
    API.replaceSnapshot('transactions', result.transaction, row => row.no);
    saveDB();
    Submissions.clear();
    appNotice('Remittance accepted. Payment receipt: ' + result.transaction.no + '. See transaction history to reprint.');
  } catch (error) { appNotice(error.message || 'Remittance needs confirmation. Recover its receipt before accepting another payment.'); }
  finally { button.disabled = form.dataset.accepted === 'true'; fulfillmentBusy = false; await refreshFulfillment(true); }
}

async function refreshFulfillment(force = false) {
  if (fulfillmentFlight || fulfillmentBusy || document.hidden || (!force && document.activeElement?.closest('#fulfillmentRows'))) return;
  fulfillmentFlight = true;
  try {
    const data = await API.request('fulfillment');
    const container = document.getElementById('fulfillmentRows');
    if (container.dataset?.mode === 'monitor') { renderFulfillmentMonitor(container, data); return; }
    container.replaceChildren();
    const totals = {preparing:0, ready:0, out_for_delivery:0, delivered:0, ...data.quantities};
    if (sessionStorage.getItem(fulfillmentKey())) {
      const retry = document.createElement('button'); retry.type = 'button'; retry.className = 'btn btn-primary'; retry.textContent = 'Retry saved handover'; retry.onclick = () => sendFulfillmentAction(); container.append(retry);
    }
    if (!data.orders.length) {
      const empty = document.createElement('p'); empty.className = 'empty-cell'; empty.textContent = 'No active orders or outstanding delivery remittances. New orders will appear here automatically.'; container.append(empty);
    }
    for (const job of data.orders) {
      const row = document.createElement('div'); row.style.cssText = 'padding:12px 0;border-bottom:1px solid #ddd;overflow-wrap:anywhere';
      const text = document.createElement('p'); text.textContent = job.receipt + ' | ' + job.customer + ' | ' + job.status.replaceAll('_', ' ') + ' | ' + job.description + (job.issues.length ? ' | Reported issue: ' + job.issues.join('; ') : '');
      row.append(text);
      if (job.order_type === 'Delivery' && job.status === 'ready' && !job.handed_over_at) {
        const form = document.createElement('form');
        form.innerHTML = '<label>Count all receipt items handed to the rider<input name="quantity" class="form-input" type="number" min="1" max="1000" step="1" required></label><button class="btn btn-primary" type="submit">Confirm prepared order handover</button>';
        form.onsubmit = event => { event.preventDefault(); if (form.reportValidity()) sendFulfillmentAction(job, 'handover', {handover_quantity:Number(form.elements.quantity.value)}); };
        row.append(form);
      }
      if (job.confirmation_code) {
        const code = document.createElement('p'); code.textContent = 'Buyer confirmation code: ' + job.confirmation_code + '. Give this privately to the buyer, never to the rider or on the rider delivery slip. Ask the buyer to share it only after checking items and cash paid.';
        const reset = document.createElement('button'); reset.type = 'button'; reset.className = 'btn'; reset.textContent = 'Replace compromised buyer code'; reset.onclick = () => sendFulfillmentAction(job, 'reset_code');
        row.append(code, reset);
      }
      if (job.proof) {
        const evidence = document.createElement('p'); evidence.textContent = 'Received by ' + job.proof.recipient_name + ' | Quantity: ' + job.proof.received_quantity + ' | Rider #' + job.rider_id + ' reported cash: ' + Number(job.proof.reported_cash).toFixed(2) + ' | Outstanding remittance: ' + Number(job.proof.pending_remittance).toFixed(2) + (job.proof.collection_note ? ' | Collection note: ' + job.proof.collection_note : '');
        const photo = document.createElement('a'); photo.href = job.proof.photo_url; photo.target = '_blank'; photo.rel = 'noopener'; photo.textContent = 'Review private delivery photo'; row.append(evidence, photo);
        for (const accepted of job.proof.remittances || []) {
          const history = document.createElement('p'); history.textContent = 'Accepted ' + Number(accepted.amount).toFixed(2) + ' by cashier #' + accepted.cashier_id + ' | Payment #' + accepted.transaction_id + (accepted.note ? ' | ' + accepted.note : ''); row.append(history);
        }
        if (job.proof.pending_remittance > 0) {
          const form = document.createElement('form');
          form.innerHTML = '<label>Cash physically received<input name="amount" class="form-input" type="number" min="0.01" step="0.01" required></label><label>Short-remittance explanation, if applicable<textarea name="note" class="form-input" minlength="5" maxlength="500"></textarea></label><button class="btn btn-primary" type="submit">Accept counted remittance</button>';
          form.elements.amount.max = job.proof.pending_remittance;
          form.onsubmit = event => { event.preventDefault(); acceptDeliveryRemittance(job, form); };
          row.append(form);
        }
      }
      container.append(row);
    }
    document.getElementById('fulfillmentStatus').textContent = 'Updated ' + new Date(data.updated_at).toLocaleTimeString() + ' | Preparing: ' + totals.preparing + ' | Ready: ' + totals.ready + ' | Out for delivery: ' + totals.out_for_delivery + ' | Delivered today: ' + totals.delivered;
  } catch (error) { document.getElementById('fulfillmentStatus').textContent = 'Offline or unavailable - quantities are not current.'; }
  finally { fulfillmentFlight = false; }
}
document.addEventListener('DOMContentLoaded', () => refreshFulfillment());
window.addEventListener('focus', () => refreshFulfillment());
window.addEventListener('online', () => refreshFulfillment());
setInterval(() => refreshFulfillment(), 5000);

async function viewDeliveryHistory(id) {
  try {
    const {order:job} = await API.request('deliveries/' + id);
    let dialog = document.getElementById('deliveryHistory');
    if (!dialog) {
      dialog = document.createElement('dialog'); dialog.id = 'deliveryHistory'; dialog.setAttribute('aria-labelledby', 'deliveryHistoryTitle');
      dialog.innerHTML = '<h2 id="deliveryHistoryTitle">Delivery evidence and remittances</h2><div id="deliveryHistoryContent"></div><button type="button" class="btn" onclick="document.getElementById(\'deliveryHistory\').close()">Close</button>';
      document.body.append(dialog);
    }
    const body = document.getElementById('deliveryHistoryContent'); body.replaceChildren();
    const summary = document.createElement('p'); summary.textContent = job.receipt + ' ? ' + job.customer; body.append(summary);
    if (!job.proof) {
      const missing = document.createElement('p'); missing.textContent = 'No delivery evidence is recorded. Historical orders completed before this feature are not retroactively verified.'; body.append(missing);
    } else {
      const proof = job.proof;
      const details = document.createElement('p'); details.textContent = 'Recipient: ' + proof.recipient_name + ' | Items: ' + proof.received_quantity + ' | Reported cash: ' + Number(proof.reported_cash).toFixed(2) + ' | Outstanding remittance: ' + Number(proof.pending_remittance).toFixed(2) + (proof.collection_note ? ' | ' + proof.collection_note : ''); body.append(details);
      const photo = document.createElement('a'); photo.href = proof.photo_url; photo.target = '_blank'; photo.rel = 'noopener'; photo.textContent = 'View private delivery photo'; body.append(photo);
      for (const accepted of proof.remittances) {
        const line = document.createElement('p'); line.textContent = 'Accepted ' + Number(accepted.amount).toFixed(2) + ' by cashier #' + accepted.cashier_id + ' on ' + accepted.at + ' | Payment #' + accepted.transaction_id + (accepted.note ? ' | ' + accepted.note : ''); body.append(line);
      }
    }
    dialog.showModal();
  } catch (error) { appNotice(error.message || 'Delivery history could not be loaded.'); }
}
