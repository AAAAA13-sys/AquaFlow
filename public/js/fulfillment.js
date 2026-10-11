let fulfillmentFlight = false;
async function refreshFulfillment() {
  if (fulfillmentFlight || document.hidden) return;
  fulfillmentFlight = true;
  try {
    const data = await API.request('fulfillment');
    const container = document.getElementById('fulfillmentRows'); container.replaceChildren();
    const totals = {preparing:0, ready:0, out_for_delivery:0, delivered:0, ...data.quantities};
    for (const job of data.orders) {
      const row = document.createElement('div'); row.style.cssText = 'padding:12px 0;border-bottom:1px solid #ddd;overflow-wrap:anywhere';
      const text = document.createElement('p'); text.textContent = job.receipt + ' | ' + job.customer + ' | ' + job.status.replaceAll('_', ' ') + ' | ' + job.description + (job.issues.length ? ' | Reported issue: ' + job.issues.join('; ') : '');
      row.append(text);
      if (getSession()?.role === 'admin' && job.order_type === 'Delivery' && ['preparing', 'ready'].includes(job.status)) {
        const select = document.createElement('select'); select.className = 'form-input'; select.setAttribute('aria-label', 'Assign rider for ' + job.receipt);
        const blank = document.createElement('option'); blank.value = ''; blank.textContent = 'Choose rider'; select.append(blank);
        for (const rider of (DB.users || []).filter(u => u.role === 'rider' && u.active)) {
          const option = document.createElement('option'); option.value = rider.id; option.textContent = rider.name; option.selected = rider.id === job.rider_id; select.append(option);
        }
        const button = document.createElement('button'); button.className = 'btn'; button.textContent = 'Assign rider';
        button.onclick = async () => { button.disabled = true; try { await API.request('fulfillment/' + job.id, {method:'POST', body:{action:'assign', rider_id:Number(select.value), version:job.version, submission_key:crypto.randomUUID()}}); await refreshFulfillment(); } catch (error) { document.getElementById('fulfillmentStatus').textContent = error.message; } finally { button.disabled = false; } };
        row.append(select, button);
      }
      container.append(row);
    }
    document.getElementById('fulfillmentStatus').textContent = 'Updated ' + new Date(data.updated_at).toLocaleTimeString() + ' | Preparing: ' + totals.preparing + ' | Ready: ' + totals.ready + ' | Out for delivery: ' + totals.out_for_delivery + ' | Delivered today: ' + totals.delivered;
  } catch (error) { document.getElementById('fulfillmentStatus').textContent = 'Offline or unavailable - quantities are not current.'; }
  finally { fulfillmentFlight = false; }
}
document.addEventListener('DOMContentLoaded', refreshFulfillment);
window.addEventListener('focus', refreshFulfillment);
window.addEventListener('online', refreshFulfillment);
setInterval(refreshFulfillment, 5000);
