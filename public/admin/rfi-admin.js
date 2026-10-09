/* Shared helpers for the embedded admin pages. App Bridge adds the ID token to fetch(). */
window.RFI = (() => {
  async function api(method, path, body) {
    const res = await fetch('/api/admin/' + path, {
      method,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    let data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    if (!res.ok) {
      const err = new Error((data && data.message) || 'Request failed (' + res.status + ')');
      err.status = res.status;
      err.errors = (data && data.errors) || {};
      throw err;
    }
    return data;
  }

  async function download(path, filename) {
    const res = await fetch('/api/admin/' + path);
    if (!res.ok) throw new Error('Download failed (' + res.status + ')');
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

  function toast(message, isError) {
    if (window.shopify && shopify.toast) shopify.toast.show(message, { isError: !!isError });
  }

  function el(id) { return document.getElementById(id); }

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  async function copy(text) {
    try {
      await navigator.clipboard.writeText(text);
    } catch (e) {
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
    }
    toast('Copied');
  }

  function centsToMoney(cents, symbol) {
    if (cents === null || cents === undefined || isNaN(cents)) return '';
    const whole = Math.floor(Math.abs(cents) / 100).toLocaleString('en-ZA').replace(/\s|\u00a0/g, ',');
    const frac = Math.abs(cents) % 100;
    return (cents < 0 ? '-' : '') + (symbol || 'R') + whole + (frac ? '.' + String(frac).padStart(2, '0') : '');
  }

  function moneyToCents(value) {
    if (value === null || value === undefined || String(value).trim() === '') return null;
    const n = Number(String(value).replace(/[\s,]/g, ''));
    return isNaN(n) ? null : Math.round(n * 100);
  }

  function showErrors(errors, fieldMap) {
    document.querySelectorAll('[data-field]').forEach((node) => { node.error = ''; });
    Object.entries(errors || {}).forEach(([key, messages]) => {
      const name = key.split('.')[0];
      const node = (fieldMap && fieldMap[name]) || document.querySelector('[data-field="' + name + '"]');
      if (node) node.error = Array.isArray(messages) ? messages[0] : messages;
    });
  }

  return { api, download, toast, el, esc, copy, centsToMoney, moneyToCents, showErrors };
})();
