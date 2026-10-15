function showProfileError(message) {
  const error = document.getElementById('profileError');
  if (error) {
    error.textContent = message;
    error.classList.remove('hidden');
  }
  return false;
}

function clearProfilePasswords() {
  for (const id of ['profileCurrentPasswordInput', 'profileNewPasswordInput', 'profileConfirmPasswordInput']) {
    const input = document.getElementById(id);
    if (input) input.value = '';
  }
}

function computeInitials(name) {
  if (!name) return 'U';
  const parts = name.trim().split(/\s+/);
  if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

async function openProfileModal() {
  const modal = document.getElementById('profileModal');
  if (!modal) return;

  const errEl = document.getElementById('profileError');
  const successEl = document.getElementById('profileSuccess');
  if (errEl) errEl.classList.add('hidden');
  if (successEl) successEl.classList.add('hidden');

  clearProfilePasswords();

  modal.classList.remove('hidden');

  try {
    const data = await API.get('profile');
    const user = data.user || {};
    const employee = data.employee;

    const nameEl = document.getElementById('profileOverviewName');
    const roleEl = document.getElementById('profileOverviewRole');
    const userEl = document.getElementById('profileOverviewUsername');
    const badgeEl = document.getElementById('profileAvatarBadge');
    const nameInput = document.getElementById('profileNameInput');

    if (nameEl) nameEl.textContent = user.name || 'User';
    if (userEl) userEl.textContent = user.u || user.username || '--';
    if (roleEl) {
      const roleName = user.role === 'admin' ? 'Store Owner / Admin' : 'Cashier';
      roleEl.textContent = roleName;
    }
    if (badgeEl) badgeEl.textContent = computeInitials(user.name);
    if (nameInput) nameInput.value = user.name || '';

    const jobWrap = document.getElementById('profileOverviewJobWrapper');
    const jobEl = document.getElementById('profileOverviewJob');
    if (jobWrap && jobEl) {
      if (employee && employee.job_title) {
        jobEl.textContent = employee.job_title;
        jobWrap.classList.remove('hidden');
      } else {
        jobWrap.classList.add('hidden');
      }
    }
  } catch (err) {
    showProfileError(err.message || 'Unable to load profile data.');
  }
}

function closeProfileModal() {
  const modal = document.getElementById('profileModal');
  if (modal) modal.classList.add('hidden');
}

function toggleProfilePassword(inputId, btn) {
  const input = document.getElementById(inputId);
  if (!input) return;
  const isPass = input.type === 'password';
  input.type = isPass ? 'text' : 'password';
  if (btn) btn.textContent = isPass ? 'Hide' : 'Show';
}

async function saveProfile(event) {
  event.preventDefault();
  if (saveProfile.busy) return false;
  const saveBtn = document.getElementById('profileSaveBtn');
  const errEl = document.getElementById('profileError');
  const successEl = document.getElementById('profileSuccess');

  if (errEl) errEl.classList.add('hidden');
  if (successEl) successEl.classList.add('hidden');

  const name = (document.getElementById('profileNameInput')?.value || '').trim();
  const currentPassword = (document.getElementById('profileCurrentPasswordInput')?.value || '');
  const newPassword = (document.getElementById('profileNewPasswordInput')?.value || '');
  const confirmPassword = (document.getElementById('profileConfirmPasswordInput')?.value || '');

  if (!name || name.length > 100) return showProfileError('Enter a display name from 1 to 100 characters.');
  if (newPassword || confirmPassword || currentPassword) {
    if (!currentPassword) return showProfileError('Current password is required to change password.');
    if (!validNewPassword(newPassword)) return showProfileError('Use 8 to 64 characters with uppercase, lowercase and a number, without spaces.');
    if (newPassword !== confirmPassword) return showProfileError('New password and confirmation do not match.');
  }

  const payload = { name };
  if (newPassword) {
    payload.current_password = currentPassword;
    payload.new_password = newPassword;
    payload.new_password_confirmation = confirmPassword;
  }

  saveProfile.busy = true;
  if (saveBtn) saveBtn.disabled = true;

  try {
    const res = await API.request('profile', { method: 'PUT', body: payload });
    const updatedUser = res.user || {};

    if (successEl) {
      successEl.textContent = res.message || 'Profile updated successfully!';
      successEl.classList.remove('hidden');
    }

    clearProfilePasswords();
    const displayName = updatedUser.name || name;
    for (const id of ['profileOverviewName', 'who', 'empHeaderName']) {
      const element = document.getElementById(id);
      if (element) element.textContent = displayName;
    }
    for (const id of ['profileAvatarBadge', 'empAvatar']) {
      const element = document.getElementById(id);
      if (element) element.textContent = computeInitials(displayName);
    }

    // Close after short pause so user sees confirmation
    setTimeout(() => {
      closeProfileModal();
    }, 1200);
  } catch (error) {
    showProfileError(error.message || 'Failed to update profile.');
  } finally {
    saveProfile.busy = false;
    if (saveBtn) saveBtn.disabled = false;
  }

  return false;
}

// Close on Escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeProfileModal();
  }
});
