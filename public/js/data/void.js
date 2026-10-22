// Credentials stay in this dialog only; never persist them with POS submissions.
function requestVoidApproval(payload) {
  if (requestVoidApproval.busy) return Promise.resolve(false);
  requestVoidApproval.busy = true;
  return new Promise(resolve => {
    const dialog = document.createElement('dialog');
    dialog.style.cssText = 'position:fixed;inset:0;margin:auto;width:min(460px,calc(100% - 32px));max-height:90dvh;overflow:auto;border:0;border-radius:16px;padding:24px';
    dialog.setAttribute('aria-label', 'Manager approval for void');
    dialog.innerHTML = '<form><h2>Approve void</h2><p>A higher-ranked manager must approve this action. For a cash sale, return the receipt total to the customer before confirming.</p>' +
      '<label>Reason<textarea name="reason" required minlength="5" maxlength="500" class="form-input"></textarea></label>' +
      '<label>Approver username<input name="approver_username" required autocomplete="off" class="form-input"></label>' +
      '<label for="voidPassword">Manager password</label><div class="credential-control"><input id="voidPassword" name="approver_password" type="password" required autocomplete="off" class="form-input"><button type="button" class="password-toggle-btn" onclick="togglePassword(\'voidPassword\',this)" aria-controls="voidPassword" aria-pressed="false" aria-label="Show password" title="Show password">' + EYE_ICON_SVG + '</button></div>' +
      '<p role="alert" class="text-red-600"></p><div style="display:flex;gap:12px;justify-content:flex-end;margin-top:20px"><button type="button" data-cancel class="btn">Cancel</button><button class="btn btn-primary" type="submit">Approve void</button></div></form>';
    const form = dialog.querySelector('form'), cancel = dialog.querySelector('[data-cancel]'), submit = dialog.querySelector('[type="submit"]');
    const key = crypto.randomUUID();
    let pending = false, submitted = null;
    const finish = result => { if (pending) return; form.reset(); dialog.close(); dialog.remove(); requestVoidApproval.busy = false; resolve(result); };
    cancel.onclick = () => finish(false);
    dialog.addEventListener('cancel', event => { event.preventDefault(); finish(false); });
    form.onsubmit = async event => {
      event.preventDefault(); if (pending) return;
      pending = true; submit.disabled = true; cancel.disabled = true;
      const values = Object.fromEntries(new FormData(form));
      const details = { ...payload, submission_key: key, reason: values.reason, approver_username: values.approver_username };
      // Retrying an uncertain response must keep the exact original request.
      if (submitted && JSON.stringify(details) !== submitted) {
        dialog.querySelector('[role="alert"]').textContent = 'Retry with the original reason and approver, or cancel and check history first.';
        pending = false; submit.disabled = false; cancel.disabled = false; return;
      }
      submitted = JSON.stringify(details);
      try {
        await API.request('voids', {method:'POST', body:{...details, approver_password:values.approver_password}});
        pending = false; finish(true);
      } catch (error) {
        dialog.querySelector('[role="alert"]').textContent = error.message || 'Could not confirm the void. Retry with the same details.';
        form.elements.approver_password.value = '';
        pending = false; submit.disabled = false; cancel.disabled = false;
      }
    };
    document.body.append(dialog); dialog.showModal(); form.elements.reason.focus();
  });
}

async function voidSale(index) {
  const sale = cashierHistoryRows[index];
  if (!sale || sale.voided) return;
  if (await requestVoidApproval({transaction_id:sale.id})) {
    appNotice('Sale voided. The original receipt remains in history.');
    await renderHistoryPage();
  }
}

async function voidCart() {
  if (!posChangeAllowed() || !POS.cart.length) return false;
  const signature = cartSignature();
  const approved = await requestVoidApproval({cart:POS.cart.map(item => ({product_id:item.id, quantity:item.q}))});
  if (!approved || signature !== cartSignature()) return false;
  POS.cart = []; savePOSState(); renderPOS(); return true;
}

async function approveCartReduction(index, quantity) {
  const item = POS.cart[index], signature = cartSignature();
  if (!item) return false;
  return await requestVoidApproval({cart:[{product_id:item.id, quantity:Math.min(item.q, quantity)}]}) && signature === cartSignature();
}
