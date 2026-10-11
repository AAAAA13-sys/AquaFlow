function appConfirm(message, accept = 'Confirm') {
  return new Promise(resolve => {
    const dialog = document.createElement('dialog');
    dialog.style.cssText = 'position:fixed;inset:0;margin:auto;width:min(440px,calc(100% - 32px));max-height:calc(100dvh - 32px);overflow-y:auto;box-sizing:border-box;border:0;border-radius:16px;padding:24px;font-size:16px';
    const text = document.createElement('p'); text.textContent = message;
    text.style.cssText = 'margin:0 0 20px;line-height:1.5;overflow-wrap:anywhere';
    const actions = document.createElement('div');
    actions.style.cssText = 'display:flex;justify-content:flex-end;align-items:center;gap:12px;flex-wrap:wrap';
    const cancel = document.createElement('button'); cancel.textContent = 'Cancel'; cancel.className = 'btn';
    const ok = document.createElement('button'); ok.textContent = accept; ok.className = 'btn btn-primary';
    const finish = value => { dialog.close(); dialog.remove(); resolve(value); };
    cancel.onclick = () => finish(false); ok.onclick = () => finish(true);
    dialog.addEventListener('cancel', event => { event.preventDefault(); finish(false); });
    actions.append(cancel, ok);
    dialog.append(text, actions); document.body.append(dialog); dialog.showModal(); cancel.focus();
  });
}

function appNotice(message) {
  let notice = document.getElementById('appNotice');
  if (!notice) {
    notice = document.createElement('aside'); notice.id = 'appNotice'; notice.setAttribute('role', 'alert');
    notice.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(440px,calc(100% - 32px));max-height:calc(100dvh - 32px);overflow-y:auto;box-sizing:border-box;padding:20px;background:#fff;border:2px solid #0284c7;border-radius:12px;z-index:10000;box-shadow:0 4px 24px #0003;font-size:16px';
    document.body.append(notice);
  }
  notice.replaceChildren();
  const text = document.createElement('p'); text.textContent = message;
  const close = document.createElement('button'); close.className = 'btn'; close.textContent = 'Dismiss'; close.onclick = () => notice.remove();
  notice.append(text, close);
}
