(() => {
    'use strict';
    const modal = document.getElementById('customerModal');
    const form = document.getElementById('customerForm');
    const message = document.getElementById('customerMessage');
    const tbody = document.getElementById('customerTableBody');
    const rowTemplate = document.getElementById('customerRowTemplate');
    const pageSizeSelect = document.getElementById('customerPageSize');
    const searchInput = document.getElementById('customerSearchInput');
    const countLabel = document.getElementById('customerCount');
    const pagination = document.getElementById('customerPagination');
    if (!modal || !form || !message || !tbody || !rowTemplate) return;
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // --- Server-side list state --------------------------------------
    // Everything the table shows comes from the 'list' action below --
    // there is no client-side filtering/sorting of rows anymore, because
    // with 50k+ customers the browser only ever holds the current page.
    let currentPage = 1;
    let pageSize = parseInt(pageSizeSelect?.value, 10) || 100;
    let searchTerm = '';
    let searchDebounce = null;
    let requestToken = 0; // guards against an older, slower request overwriting a newer one

    const show = (text, type) => { message.textContent = text; message.className = `notice ${type}`; };

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function renderRows(customers) {
        tbody.innerHTML = '';
        if (!customers.length) {
            tbody.innerHTML = `<tr><td colspan="7">${searchTerm ? 'No customers match your search.' : 'No customers yet. Click "Add customer" to create one.'}</td></tr>`;
            return;
        }
        const fragment = document.createDocumentFragment();
        customers.forEach((customer) => {
            const row = rowTemplate.content.firstElementChild.cloneNode(true);
            row.dataset.customer = JSON.stringify(customer);
            row.querySelector('.js-customer-id').textContent = customer.CustomerID || '';
            row.querySelector('.js-customer-name').textContent = customer.CustomerName || '';
            row.querySelector('.js-customer-barangay').textContent = customer.Barangay || '';
            row.querySelector('.js-customer-municipality').textContent = customer.Municipality || '';
            row.querySelector('.js-customer-province').textContent = customer.Province || '';
            const sellingTypeCell = row.querySelector('.js-customer-selling-type');
            const sellingType = (customer.SellingType || '').trim();
            sellingTypeCell.innerHTML = sellingType
                ? `<span class="status-pill status-pill--on">${escapeHtml(sellingType)}</span>`
                : '<span class="status-pill status-pill--off">—</span>';
            fragment.appendChild(row);
        });
        tbody.appendChild(fragment);
    }

    function renderPagination(total, page, size) {
        if (!countLabel || !pagination) return;
        const totalPages = Math.max(1, Math.ceil(total / size));
        if (page > totalPages) page = totalPages;
        const start = total === 0 ? 0 : (page - 1) * size + 1;
        const end = Math.min(page * size, total);
        countLabel.textContent = total === 0
            ? 'Showing 0 of 0 entries'
            : `Showing ${start} to ${end} of ${total} entr${total === 1 ? 'y' : 'ies'}`;

        pagination.innerHTML = '';
        if (totalPages <= 1) return;

        const makeBtn = (label, targetPage, opts = {}) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = label;
            btn.className = 'dt-page-btn' + (opts.active ? ' dt-page-btn--active' : '');
            btn.disabled = !!opts.disabled;
            if (opts.active) btn.setAttribute('aria-current', 'page');
            btn.addEventListener('click', () => loadPage(targetPage));
            return btn;
        };

        pagination.appendChild(makeBtn('Previous', page - 1, { disabled: page === 1 }));

        const pages = [];
        for (let p = 1; p <= totalPages; p++) {
            if (p === 1 || p === totalPages || Math.abs(p - page) <= 1) pages.push(p);
        }
        let previousShown = 0;
        pages.forEach((p) => {
            if (p - previousShown > 1) {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'dt-page-ellipsis';
                ellipsis.textContent = '…';
                pagination.appendChild(ellipsis);
            }
            pagination.appendChild(makeBtn(String(p), p, { active: p === page }));
            previousShown = p;
        });

        pagination.appendChild(makeBtn('Next', page + 1, { disabled: page === totalPages }));
    }

    async function loadPage(page) {
        currentPage = Math.max(1, page);
        const myRequest = ++requestToken;
        tbody.innerHTML = '<tr><td colspan="7">Loading customers…</td></tr>';
        try {
            const data = new FormData();
            data.set('action', 'list');
            data.set('csrf_token', token);
            data.set('page', String(currentPage));
            data.set('page_size', String(pageSize));
            data.set('search', searchTerm);
            const response = await fetch('../Ajax/ajax_customer_profile.php', { method: 'POST', body: data, credentials: 'same-origin' });
            const result = await response.json();
            if (myRequest !== requestToken) return; // a newer request already superseded this one
            if (!response.ok || !result.success) throw Error(result.message || 'Failed to load customers.');
            renderRows(result.rows || []);
            renderPagination(result.total || 0, result.page || currentPage, result.pageSize || pageSize);
        } catch (error) {
            if (myRequest !== requestToken) return;
            tbody.innerHTML = '<tr><td colspan="7">Could not load customers. Please try again.</td></tr>';
            show(error.message, 'error');
        }
    }

    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', () => {
            pageSize = parseInt(pageSizeSelect.value, 10) || 100;
            loadPage(1);
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(() => {
                searchTerm = searchInput.value.trim();
                loadPage(1);
            }, 300);
        });
    }

    // --- Add/Edit/Delete modal (same shape as before) -----------------
    const close = () => modal.classList.remove('active');
    const open = (customer = null) => {
        form.reset();
        document.getElementById('customerPk').value = customer?.PK_cust ?? '';
        document.getElementById('customerId').value = customer?.CustomerID ?? '';
        document.getElementById('customerName').value = customer?.CustomerName ?? '';
        document.getElementById('customerStreet').value = customer?.Street ?? '';
        document.getElementById('customerBarangay').value = customer?.Barangay ?? '';
        document.getElementById('customerMunicipality').value = customer?.Municipality ?? '';
        document.getElementById('customerProvince').value = customer?.Province ?? '';
        document.getElementById('customerLatitude').value = customer?.Latitude ?? '';
        document.getElementById('customerLongitude').value = customer?.Longitude ?? '';
        document.getElementById('customerSellingType').value = customer?.SellingType ?? '';
        document.getElementById('customerModalTitle').innerHTML = customer
            ? '<i class="fa-solid fa-pen" aria-hidden="true"></i> Edit customer'
            : '<i class="fa-solid fa-user-plus" aria-hidden="true"></i> Add customer';
        modal.classList.add('active');
    };
    async function request(action, data) {
        data.set('action', action); data.set('csrf_token', token);
        const response = await fetch('../Ajax/ajax_customer_profile.php', { method: 'POST', body: data, credentials: 'same-origin' });
        const result = await response.json(); if (!response.ok || !result.success) throw Error(result.message || 'Request failed.'); return result;
    }
    document.getElementById('addCustomer')?.addEventListener('click', () => open());
    document.querySelectorAll('[data-close-customer]').forEach(button => button.addEventListener('click', close));
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });

    // Event delegation on the tbody -- rows are re-created on every
    // loadPage(), so listeners are attached once here instead of being
    // re-bound after every render.
    tbody.addEventListener('click', (event) => {
        const editButton = event.target.closest('.edit-customer');
        const deleteButton = event.target.closest('.delete-customer');
        if (editButton) {
            const row = editButton.closest('tr');
            if (row?.dataset.customer) open(JSON.parse(row.dataset.customer));
            return;
        }
        if (deleteButton) {
            const row = deleteButton.closest('tr');
            if (!row?.dataset.customer) return;
            const customer = JSON.parse(row.dataset.customer);
            if (!confirm(`Delete "${customer.CustomerName}" (${customer.CustomerID})?`)) return;
            (async () => {
                try {
                    const data = new FormData();
                    data.set('pk', customer.PK_cust);
                    await request('delete', data);
                    show('Customer deleted.', 'success');
                    loadPage(currentPage);
                } catch (error) { show(error.message, 'error'); }
            })();
        }
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        try {
            await request('save', new FormData(form));
            close();
            show('Customer saved.', 'success');
            loadPage(currentPage);
        } catch (error) { show(error.message, 'error'); }
    });

    // Initial load.
    loadPage(1);
})();
