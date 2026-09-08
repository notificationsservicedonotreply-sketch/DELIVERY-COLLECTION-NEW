(() => {
    'use strict';
    const modal = document.getElementById('userModal');
    const form = document.getElementById('userForm');
    const message = document.getElementById('userMessage');
    if (!modal || !form || !message) return;
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const show = (text, type) => { message.textContent = text; message.className = `notice ${type}`; };
    const close = () => modal.classList.remove('active');
    const open = (user = null) => {
        form.reset();
        document.getElementById('userPk').value = user?.PK || '';
        document.getElementById('userId').value = user?.USERID || '';
        document.getElementById('userName').value = user?.NAME || '';
        document.getElementById('salesmanId').value = user?.SALESMANID || '';
        document.getElementById('imei').value = user?.IMEI || '';
        document.getElementById('canDelivery').checked = Boolean(Number(user?.CanDelivery));
        document.getElementById('canCollection').checked = Boolean(Number(user?.CanCollection));
        document.getElementById('canTransactions').checked = Boolean(Number(user?.CanTransactions));
        document.getElementById('canManageTransactions').checked = Boolean(Number(user?.CanManageTransactions));
        document.getElementById('canDeliveryTransactions').checked = Boolean(Number(user?.CanDeliveryTransactions));
        document.getElementById('canManageDeliveryTransactions').checked = Boolean(Number(user?.CanManageDeliveryTransactions));
        document.getElementById('canTriplistAssign').checked = Boolean(Number(user?.CanTriplistAssign));
        document.getElementById('canCustomerProfile').checked = Boolean(Number(user?.CanCustomerProfile));
        document.getElementById('locationLock').checked = Boolean(Number(user?.LocationLock));
        document.getElementById('userModalTitle').innerHTML = user
            ? '<i class="fa-solid fa-user-pen" aria-hidden="true"></i> Edit user'
            : '<i class="fa-solid fa-user-plus" aria-hidden="true"></i> Add user';
        document.getElementById('passwordHint').textContent = user ? '(leave blank to keep current password)' : '(minimum 10 characters)';
        document.getElementById('userPassword').required = !user;
        modal.classList.add('active');
    };
    async function request(action, data) {
        data.set('action', action); data.set('csrf_token', token);
        const response = await fetch('../Ajax/ajax_user_management.php', { method: 'POST', body: data, credentials: 'same-origin' });
        const result = await response.json(); if (!response.ok || !result.success) throw Error(result.message || 'Request failed.'); return result;
    }
    document.getElementById('addUser').addEventListener('click', () => open());
    document.querySelectorAll('[data-close-user]').forEach(button => button.addEventListener('click', close));
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
    document.querySelectorAll('.edit-user').forEach(button => button.addEventListener('click', () => open(JSON.parse(button.closest('tr').dataset.user))));
    document.querySelectorAll('.delete-user').forEach(button => button.addEventListener('click', async () => { if (!confirm('Delete this user and all module access?')) return; try { const data = new FormData(); data.set('pk', JSON.parse(button.closest('tr').dataset.user).PK); await request('delete', data); location.reload(); } catch (error) { show(error.message, 'error'); } }));
    form.addEventListener('submit', async event => { event.preventDefault(); try { await request('save', new FormData(form)); location.reload(); } catch (error) { show(error.message, 'error'); } });
})();
