let appDialogSeq = 0;

function setDialogAttr(el, name, value) {
  if (typeof el.setAttribute === 'function') el.setAttribute(name, value);
  else el[name] = value;
}

function appQuestion(message, accept, input = null) {
  return new Promise(resolve => {
    const dialog = document.createElement('dialog');
    dialog.style.cssText = 'position:fixed;inset:0;margin:auto;width:min(440px,calc(100% - 32px));max-height:calc(100dvh - 32px);overflow-y:auto;box-sizing:border-box;border:0;border-radius:16px;padding:24px;font-size:16px' +
      (input ? ';box-shadow:0 8px 30px rgba(0,0,0,0.2)' : '');
    // The message names the dialog, and labels the reason box when there is one,
    // so a screen reader announces the question rather than just "dialog".
    const textId = 'appDialogText' + (++appDialogSeq);
    const text = document.createElement('p'); text.textContent = message; text.id = textId;
    setDialogAttr(dialog, 'aria-labelledby', textId);
    if (input) setDialogAttr(input, 'aria-labelledby', textId);
    text.style.cssText = input
      ? 'margin:0 0 14px;line-height:1.5;font-weight:600;color:#1E293B'
      : 'margin:0 0 20px;line-height:1.5;overflow-wrap:anywhere';
    const actions = document.createElement('div');
    actions.style.cssText = 'display:flex;justify-content:flex-end;align-items:center;gap:12px;flex-wrap:wrap';
    const cancel = document.createElement('button'); cancel.textContent = 'Cancel'; cancel.className = 'btn';
    const ok = document.createElement('button'); ok.textContent = accept; ok.className = 'btn btn-primary';
    const finish = value => { dialog.close(); dialog.remove(); resolve(value); };
    cancel.onclick = () => finish(input ? null : false);
    ok.onclick = () => finish(input ? input.value.trim() : true);
    dialog.addEventListener('cancel', event => { event.preventDefault(); finish(input ? null : false); });
    actions.append(cancel, ok);
    dialog.append(text);
    if (input) dialog.append(input);
    dialog.append(actions); document.body.append(dialog); dialog.showModal();
    (input || cancel).focus();
  });
}

function appConfirm(message, accept = 'Confirm') {
  return appQuestion(message, accept);
}

// A blocking notice. Focus moves to Dismiss so a keyboard user can read and
// close it without hunting for it; Escape also closes it, and focus returns to
// whatever the person was using before it appeared.
let appNoticeReturnFocus = null;

function appNotice(message) {
  let notice = document.getElementById('appNotice');
  if (!notice) {
    appNoticeReturnFocus = document.activeElement || null;
    notice = document.createElement('aside'); notice.id = 'appNotice'; notice.setAttribute('role', 'alert');
    notice.setAttribute('aria-labelledby', 'appNoticeText');
    notice.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(440px,calc(100% - 32px));max-height:calc(100dvh - 32px);overflow-y:auto;box-sizing:border-box;padding:20px;background:#fff;border:2px solid #0284c7;border-radius:12px;z-index:10000;box-shadow:0 4px 24px #0003;font-size:16px';
    notice.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeAppNotice(); }
    });
    document.body.append(notice);
  }
  notice.replaceChildren();
  const text = document.createElement('p'); text.id = 'appNoticeText'; text.textContent = message;
  const close = document.createElement('button'); close.type = 'button'; close.className = 'btn'; close.textContent = 'Dismiss'; close.onclick = closeAppNotice;
  notice.append(text, close);
  if (typeof close.focus === 'function') close.focus();
}

function closeAppNotice() {
  const notice = document.getElementById('appNotice');
  if (notice) notice.remove();
  const target = appNoticeReturnFocus;
  appNoticeReturnFocus = null;
  if (target && target.isConnected && typeof target.focus === 'function') target.focus();
}

function appPrompt(message, placeholder = '', accept = 'Confirm') {
  const input = document.createElement('textarea');
  input.placeholder = placeholder;
  input.rows = 3;
  input.style.cssText = 'width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #CBD5E1;border-radius:8px;font-family:inherit;font-size:14px;margin-bottom:18px;resize:vertical';
  return appQuestion(message, accept, input);
}

/* ---------------------------------------------------------------------------
   Side drawers (partials/editor-drawer.blade.php).

   Each page opens and closes its own drawer by toggling .hidden, so focus is
   managed here once instead of in every opener: when a drawer appears, focus
   moves into it (unless the opener already focused a field inside); when it
   closes, focus returns to the control that opened it. Escape closes the
   visible drawer through its own Close button, so the page's close handler
   (and any unsaved-change guard it has) still runs.
   ------------------------------------------------------------------------ */
const SideDrawerFocus = {
  lastOutside: null,
  openers: new WeakMap(),

  init() {
    if (typeof document === 'undefined' || typeof document.querySelectorAll !== 'function' || typeof MutationObserver === 'undefined') return;
    document.addEventListener('focusin', event => {
      const target = event.target;
      if (target && target.closest && !target.closest('.side-drawer')) this.lastOutside = target;
    });
    document.addEventListener('keydown', event => {
      if (event.key !== 'Escape' || event.defaultPrevented) return;
      // A modal dialog, the notice or the profile modal handles its own Escape.
      if (document.querySelector('dialog[open], #appNotice, #profileModal:not(.hidden)')) return;
      const open = [...document.querySelectorAll('.side-drawer:not(.hidden)')].pop();
      const close = open && open.querySelector('[data-drawer-close]');
      if (close) { event.preventDefault(); close.click(); }
    });
    const observer = new MutationObserver(records => {
      for (const record of records) this.changed(record.target, record.oldValue || '');
    });
    document.querySelectorAll('.side-drawer').forEach(drawer =>
      observer.observe(drawer, { attributes: true, attributeFilter: ['class'], attributeOldValue: true }));
  },

  changed(drawer, oldClass) {
    const wasHidden = /(^|\s)hidden(\s|$)/.test(oldClass);
    const isHidden = drawer.classList.contains('hidden');
    if (wasHidden && !isHidden) {
      this.openers.set(drawer, this.lastOutside);
      if (!drawer.contains(document.activeElement)) drawer.focus({ preventScroll: false });
    } else if (!wasHidden && isHidden) {
      const opener = this.openers.get(drawer);
      this.openers.delete(drawer);
      const focusWasInside = drawer.contains(document.activeElement) || document.activeElement === document.body || !document.activeElement;
      if (focusWasInside && opener && opener.isConnected && typeof opener.focus === 'function') opener.focus();
    }
  }
};

if (typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => SideDrawerFocus.init());
  else SideDrawerFocus.init();
}
