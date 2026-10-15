function appQuestion(message, accept, input = null) {
  return new Promise(resolve => {
    const dialog = document.createElement('dialog');
    dialog.style.cssText = 'position:fixed;inset:0;margin:auto;width:min(440px,calc(100% - 32px));max-height:calc(100dvh - 32px);overflow-y:auto;box-sizing:border-box;border:0;border-radius:16px;padding:24px;font-size:16px' +
      (input ? ';box-shadow:0 8px 30px rgba(0,0,0,0.2)' : '');
    const text = document.createElement('p'); text.textContent = message;
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

function appPrompt(message, placeholder = '', accept = 'Confirm') {
  const input = document.createElement('textarea');
  input.placeholder = placeholder;
  input.rows = 3;
  input.style.cssText = 'width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #CBD5E1;border-radius:8px;font-family:inherit;font-size:14px;margin-bottom:18px;resize:vertical';
  return appQuestion(message, accept, input);
}
