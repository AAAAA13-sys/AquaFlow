// AquaFlow - API client for the Laravel backend.
//
// The app is served by Laravel, so the API lives under /api/. Requests are
// session authenticated and state-changing calls carry the CSRF token from the
// <meta name="csrf-token"> tag (rendered by the Blade layout).

const API_TIMEOUT_MS = 15000;

const API = {
  base: '/api/',

  csrf() {
    // Prefer the XSRF-TOKEN cookie: Laravel refreshes it on every response, so
    // the client always holds the current token (including right after login,
    // when the session and its token are regenerated). The meta tag is the
    // documented fallback.
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    if (match && match[1]) {
      return decodeURIComponent(match[1]);
    }

    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  },

  /** Header Laravel expects for the encrypted XSRF-TOKEN cookie value. */
  csrfHeaderName() {
    return document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/) ? 'X-XSRF-TOKEN' : 'X-CSRF-TOKEN';
  },

  async request(path, options) {
    options = options || {};
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), options.timeout || API_TIMEOUT_MS);
    const method = (options.method || 'GET').toUpperCase();

    const headers = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    };
    if (method !== 'GET' && method !== 'HEAD') {
      headers[this.csrfHeaderName()] = this.csrf();
    }
    const multipart = typeof FormData !== 'undefined' && options.body instanceof FormData;
    if (options.body !== undefined && !multipart) {
      headers['Content-Type'] = 'application/json';
    }

    let response;
    try {
      response = await fetch(this.base + path, {
        method: method,
        credentials: 'same-origin',
        headers: headers,
        body: multipart ? options.body : options.body !== undefined ? JSON.stringify(options.body) : undefined,
        signal: controller.signal
      });
    } catch (error) {
      clearTimeout(timer);
      const offline = new Error('Cannot reach the server. Is it running?');
      offline.offline = true;
      throw offline;
    }
    clearTimeout(timer);

    let data = null;
    if (response.status !== 204) {
      try { data = await response.json(); } catch (error) { data = null; }
    }

    if (!response.ok) {
      if (response.status === 401) {
        API.handleUnauthorized();
      }
      const failure = new Error(API.errorMessage(data, response.status));
      failure.status = response.status;
      failure.data = data;
      throw failure;
    }

    return data;
  },

  /** Laravel returns {message, errors:{field:[...]}} for validation failures. */
  errorMessage(data, status) {
    if (data && data.errors) {
      const first = Object.values(data.errors)[0];
      if (Array.isArray(first) && first.length) return first[0];
    }
    if (data && data.message) return data.message;
    if (data && data.error) return data.error;
    return 'Request failed (' + status + ')';
  },

  handleUnauthorized() {
    const path = window.location.pathname;
    const onLoginPage = path === '/' || path.startsWith('/owner/login');
    if (!onLoginPage) {
      window.location.href = '/';
    }
  },

  get(path) { return this.request(path); },
  getWithQuery(path, params) {
    const query = new URLSearchParams(params || {}).toString();
    return this.get(path + (query ? '?' + query : ''));
  },
  post(path, body) { return this.request(path, { method: 'POST', body: body || {} }); },
  patch(path, body) { return this.request(path, { method: 'PATCH', body: body || {} }); },
  put(path, body) { return this.request(path, { method: 'PUT', body: body || {} }); },
  del(path) { return this.request(path, { method: 'DELETE' }); },

  // ---- Authentication -------------------------------------------------
  logout() { return this.post('auth/logout'); },
  session() { return this.get('auth/session'); },

  // ---- Data -----------------------------------------------------------
  bootstrap() { return this.get('bootstrap'); },

  // Customers / receivables
  customers(params) { return this.getWithQuery('customers', params); },
  createCustomer(customer) { return this.post('customers', customer); },
  settleDebt(customerId, amount) { return Submissions.send('customers/' + customerId + '/settle', { amount: amount }); },
  logReturn(customerId, kind) { return this.post('customers/' + customerId + '/returns', { kind: kind }); },

  // Approvals (maker-checker)
  approvals(params) { return this.getWithQuery('approvals', params); },
  approval(id) { return this.get('approvals/' + id); },
  decideApproval(id, decision, note) {
    return this.post('approvals/' + id + '/decision', {
      decision: decision,
      note: note || undefined,
      submission_key: newSubmissionKey()
    });
  },
  cancelApproval(id, note) { return this.post('approvals/' + id + '/cancel', { note: note || undefined }); },

  // Audit trail (rank-scoped server-side)
  auditLogs(params) { return this.getWithQuery('audit-logs', params); },

  // Inventory
  inventory() { return this.get('inventory'); },
  createInventory(item) { return this.post('inventory', item); },
  updateInventoryLead(id, lead) { return this.patch('inventory/' + id, { lead_time_days: lead }); },
  updateInventory(id, payload) { return this.patch('inventory/' + id, payload); },
  adjustInventory(id, direction) { return this.patch('inventory/' + id, { direction: direction }); },
  deleteInventory(id) { return this.del('inventory/' + id); },
  recalculateInventory() { return this.post('inventory/recalculate'); },

  // Suppliers
  suppliers() { return this.get('suppliers'); },
  createSupplier(supplier) { return this.post('suppliers', supplier); },
  updateSupplier(id, payload) { return this.patch('suppliers/' + id, payload); },
  deleteSupplier(id) { return this.del('suppliers/' + id); },

  // Till catalog (owner-managed products)
  products() { return this.get('products'); },
  createProduct(product) { return this.post('products', product); },
  updateProduct(id, payload) { return this.patch('products/' + id, payload); },
  deleteProduct(id) { return this.del('products/' + id); },

  // Sales
  transactions(params) { return this.getWithQuery('transactions', params); },
  createTransaction(payload) { return Submissions.send('transactions', payload); },

  // Production queue
  queue() { return this.get('queue'); },
  advanceQueue(id) { return this.post('queue/' + id + '/advance'); },

  // Settings
  settings() { return this.get('settings'); },
  updateSettings(settings) { return this.put('settings', settings); },

  // Forecasting
  forecasts(series) { return this.get('forecast' + (series ? '?series=' + encodeURIComponent(series) : '')); },
  runForecast() { return this.request('forecast/run', { method: 'POST', body: {}, timeout: 300000 }); },

  // Replenishment advisories
  advisories() { return this.get('advisories'); },

  // Defect & damaged-stock reporting
  defectReports(status) { return this.getWithQuery('defects', status ? { status: status } : {}); },
  createDefectReport(payload) { return this.post('defects', payload); },
  resolveDefectReport(id, action, notes) {
    return this.patch('defects/' + id + '/' + action, notes === undefined || notes === null ? {} : { notes: notes });
  },

  // Users
  users() { return this.get('users'); },
  createUser(user) { return this.post('users', user); },
  updateUser(id, payload) { return this.patch('users/' + id, payload); },
  deleteUser(id) { return this.del('users/' + id); },

  // ---- Local snapshot helpers ----------------------------------------
  replaceSnapshot(key, item, resolveId = row => row.id) {
    const list = DB[key] || (DB[key] = []);
    const index = list.findIndex(row => resolveId(row) === resolveId(item));
    if (index >= 0) list[index] = item;
    else list.push(item);
  },

  removeSnapshot(key, id, resolveId = row => row.id) {
    if (!Array.isArray(DB[key])) return;
    DB[key] = DB[key].filter(row => resolveId(row) !== id);
  },

  supplierId(supplier) { return supplier.id !== undefined ? supplier.id : supplier.name; },

  replaceCustomer(customer) { this.replaceSnapshot('customers', customer); },
  replaceInventory(item) { this.replaceSnapshot('inventory', item); },
  removeInventory(id) { this.removeSnapshot('inventory', id); },
  replaceSupplier(supplier) { this.replaceSnapshot('suppliers', supplier, this.supplierId); },
  removeSupplier(idOrName) { this.removeSnapshot('suppliers', idOrName, this.supplierId); },
  replaceProduct(product) { this.replaceSnapshot('products', product); },
  removeProduct(id) { this.removeSnapshot('products', id); },
  replaceUser(user) { this.replaceSnapshot('users', user); },
  removeUser(id) { this.removeSnapshot('users', id); }
};
