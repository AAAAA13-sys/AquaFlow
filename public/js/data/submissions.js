// Preserve the original financial request until its receipt is acknowledged.
// sessionStorage survives refresh and isolates independent cashier tabs.
const Submissions = {
  flight: null,
  storageKey() {
    const session = getSession();
    if (!session || !session.u) throw new Error('Sign in before recording a transaction.');
    return 'aquaflow_submission:' + session.u;
  },
  pending() { return JSON.parse(sessionStorage.getItem(this.storageKey()) || 'null'); },
  save(entry) { sessionStorage.setItem(this.storageKey(), JSON.stringify(entry)); this.render(); },
  clear() { sessionStorage.removeItem(this.storageKey()); this.render(); },
  async send(path, payload) {
    const signature = JSON.stringify([path, payload]);
    let entry = this.pending();
    if (entry && entry.signature !== signature) {
      throw new Error('Recover the previous receipt using the payment notice before starting another transaction.');
    }
    if (entry && entry.result) return entry.result;
    if (this.flight) return this.flight;
    if (!entry) {
      entry = {path, signature, body:{...payload, submission_key:crypto.randomUUID()}};
      this.save(entry); // If browser storage is unavailable, do not send a payment.
    }
    this.flight = this.perform(entry);
    try { return await this.flight; } finally { this.flight = null; }
  },
  async perform(entry) {
    try {
      const result = await API.post(entry.path, entry.body);
      if (!result || !result.transaction) throw new Error('Receipt response was incomplete. Recover the receipt before taking another payment.');
      entry.result = result;
      this.save(entry);
      return result;
    } catch (error) {
      // Validation failures roll back server writes; uncertain failures retain the key.
      if (error.status === 422) this.clear();
      else this.render();
      throw error;
    }
  },
  async recover() {
    if (this.flight) return;
    const entry = this.pending();
    if (!entry) return;
    const button = document.getElementById('recoverPayment');
    if (button) button.disabled = true;
    try {
      const [, payload] = JSON.parse(entry.signature);
      const result = await this.send(entry.path, payload);
      if (result.customer) API.replaceCustomer(result.customer);
      API.replaceSnapshot('transactions', result.transaction, row => row.no);
      if (typeof saveDB === 'function') saveDB();
      alert('Receipt ' + result.transaction.no + ' is saved. Amount: ' + Number(result.transaction.total).toFixed(2) + '. You can reprint it from sales history.');
      if (entry.path === 'transactions') {
        if (typeof closeReceipt === 'function' && document.getElementById('posNext')) closeReceipt();
        else localStorage.removeItem('aquaflow_pos');
      }
      this.clear();
    } catch (error) { alert(error.message); }
    finally { if (button) button.disabled = false; }
  },
  render() {
    const box = document.getElementById('paymentRecovery');
    if (!box) return;
    const entry = this.pending();
    box.hidden = !entry;
    if (entry) document.getElementById('recoverPayment').textContent = entry.result ? 'Finish transaction' : 'Recover receipt';
    if (entry) document.getElementById('paymentRecoveryText').textContent = entry.result
      ? 'Receipt ' + entry.result.transaction.no + ' is saved. Finish this transaction before taking another payment.'
      : 'A payment needs confirmation. Recover its receipt before taking another payment.';
  }
};
document.addEventListener('DOMContentLoaded', () => {
  if (!getSession() || !/^\/(admin|cashier)(\/|$)/.test(window.location.pathname)) return;
  const box = document.createElement('aside');
  box.id = 'paymentRecovery';
  box.className = 'payment-recovery';
  box.setAttribute('role', 'status');
  box.innerHTML = '<span id="paymentRecoveryText"></span><button id="recoverPayment" type="button" class="btn btn-primary">Recover receipt</button>';
  document.body.prepend(box);
  document.getElementById('recoverPayment').addEventListener('click', () => Submissions.recover());
  Submissions.render();
});
