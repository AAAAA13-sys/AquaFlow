let currentProfileData = null;

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

  // Reset password inputs
  const currentPass = document.getElementById('profileCurrentPasswordInput');
  const newPass = document.getElementById('profileNewPasswordInput');
  const confirmPass = document.getElementById('profileConfirmPasswordInput');
  if (currentPass) currentPass.value = '';
  if (newPass) newPass.value = '';
  if (confirmPass) confirmPass.value = '';

  modal.classList.remove('hidden');

  try {
    const data = await API.get('profile');
    currentProfileData = data;
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
      const roleName = user.role === 'admin' ? 'Store Owner' : (user.role === 'rider' ? 'Delivery Rider' : (user.role === 'refiller' ? 'Refill Operator' : 'Cashier'));
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
    if (errEl) {
      errEl.textContent = err.message || 'Unable to load profile data.';
      errEl.classList.remove('hidden');
    }
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
  const form = event.target;
  const saveBtn = document.getElementById('profileSaveBtn');
  const errEl = document.getElementById('profileError');
  const successEl = document.getElementById('profileSuccess');

  if (errEl) errEl.classList.add('hidden');
  if (successEl) successEl.classList.add('hidden');

  const name = (document.getElementById('profileNameInput')?.value || '').trim();
  const currentPassword = (document.getElementById('profileCurrentPasswordInput')?.value || '');
  const newPassword = (document.getElementById('profileNewPasswordInput')?.value || '');
  const confirmPassword = (document.getElementById('profileConfirmPasswordInput')?.value || '');

  if (!name) {
    if (errEl) {
      errEl.textContent = 'Display name cannot be empty.';
      errEl.classList.remove('hidden');
    }
    return false;
  }

  if (newPassword || confirmPassword || currentPassword) {
    if (!currentPassword) {
      if (errEl) {
        errEl.textContent = 'Current password is required to change password.';
        errEl.classList.remove('hidden');
      }
      return false;
    }
    if (newPassword.length < 8) {
      if (errEl) {
        errEl.textContent = 'New password must be at least 8 characters long.';
        errEl.classList.remove('hidden');
      }
      return false;
    }
    if (newPassword !== confirmPassword) {
      if (errEl) {
        errEl.textContent = 'New password and confirmation do not match.';
        errEl.classList.remove('hidden');
      }
      return false;
    }
  }

  const payload = { name };
  if (newPassword) {
    payload.current_password = currentPassword;
    payload.new_password = newPassword;
    payload.new_password_confirmation = confirmPassword;
  }

  if (saveBtn) saveBtn.disabled = true;

  try {
    const res = await API.request('profile', { method: 'PUT', body: payload });
    const updatedUser = res.user || {};

    if (successEl) {
      successEl.textContent = res.message || 'Profile updated successfully!';
      successEl.classList.remove('hidden');
    }

    // Reset password inputs
    const curP = document.getElementById('profileCurrentPasswordInput');
    const newP = document.getElementById('profileNewPasswordInput');
    const confP = document.getElementById('profileConfirmPasswordInput');
    if (curP) curP.value = '';
    if (newP) newP.value = '';
    if (confP) confP.value = '';

    // Update avatar and name badges in the active DOM session
    const overviewName = document.getElementById('profileOverviewName');
    const badgeEl = document.getElementById('profileAvatarBadge');
    if (overviewName) overviewName.textContent = updatedUser.name || name;
    if (badgeEl) badgeEl.textContent = computeInitials(updatedUser.name || name);

    // Update global session header elements if present on page
    const whoEl = document.getElementById('who');
    if (whoEl) whoEl.textContent = updatedUser.name || name;

    const empHeaderName = document.getElementById('empHeaderName');
    if (empHeaderName) empHeaderName.textContent = updatedUser.name || name;

    const empAvatar = document.getElementById('empAvatar');
    if (empAvatar) empAvatar.textContent = computeInitials(updatedUser.name || name);

    // Close after short pause so user sees confirmation
    setTimeout(() => {
      closeProfileModal();
    }, 1200);
  } catch (error) {
    if (errEl) {
      errEl.textContent = error.message || 'Failed to update profile.';
      errEl.classList.remove('hidden');
    }
  } finally {
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
