(() => {
    'use strict';
    const modal = document.getElementById('tripAssignModal');
    const form = document.getElementById('tripAssignForm');
    const message = document.getElementById('tripAssignMessage');
    if (!modal || !form || !message) return;
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const show = (text, type) => { message.textContent = text; message.className = `notice ${type}`; };
    const close = () => modal.classList.remove('active');
    const open = (assignment = null) => {
        form.reset();
        document.getElementById('tripOriginalUserId').value = assignment?.USERID || '';
        document.getElementById('tripOriginalTripId').value = assignment?.TRIPID || '';
        document.getElementById('tripUserId').value = assignment?.USERID || '';
        document.getElementById('tripId').value = assignment?.TRIPID || '';
        document.getElementById('tripStatus').value = assignment && Number(assignment.Status) === 0 ? '0' : '1';
        document.getElementById('tripDate').value = assignment?.TripDate ? String(assignment.TripDate).slice(0, 10) : '';
        document.getElementById('tripAssignModalTitle').innerHTML = assignment
            ? '<i class="fa-solid fa-pen" aria-hidden="true"></i> Edit assignment'
            : '<i class="fa-solid fa-route" aria-hidden="true"></i> Assign trip';
        modal.classList.add('active');
    };
    async function request(action, data) {
        data.set('action', action); data.set('csrf_token', token);
        const response = await fetch('../Ajax/ajax_trip_list_assign.php', { method: 'POST', body: data, credentials: 'same-origin' });
        const result = await response.json(); if (!response.ok || !result.success) throw Error(result.message || 'Request failed.'); return result;
    }
    document.getElementById('addTripAssign').addEventListener('click', () => open());
    document.querySelectorAll('[data-close-trip-assign]').forEach(button => button.addEventListener('click', close));
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
    document.querySelectorAll('.edit-trip-assign').forEach(button => button.addEventListener('click', () => open(JSON.parse(button.closest('tr').dataset.assignment))));
    document.querySelectorAll('.delete-trip-assign').forEach(button => button.addEventListener('click', async () => {
        if (!confirm('Remove this trip assignment?')) return;
        try {
            const assignment = JSON.parse(button.closest('tr').dataset.assignment);
            const data = new FormData();
            data.set('user_id', assignment.USERID);
            data.set('trip_id', assignment.TRIPID);
            await request('delete', data);
            location.reload();
        } catch (error) { show(error.message, 'error'); }
    }));
    form.addEventListener('submit', async event => {
        event.preventDefault();
        try { await request('save', new FormData(form)); location.reload(); } catch (error) { show(error.message, 'error'); }
    });
})();
