/**
 * Generic sort + search + pagination for any <table class="table dynamic-table">.
 * The responsive mobile card layout is pure CSS (see dashboard.css) and
 * needs no JS at all -- this file adds click-to-sort, a search box, a
 * "Show N entries" page-size picker, and Previous/Next pagination.
 *
 * Opt-in per table:
 *   - class="dynamic-table" on the <table>
 *   - data-sortable on each sortable <th> (skip it on action/photo columns)
 *   - data-label="Column Name" on each <td>, used by the mobile card layout
 *   - data-no-search on the <table> to skip the whole toolbar (search,
 *     page-size picker, count, and pagination) -- e.g. an already tiny
 *     table where all of that would just be clutter
 *   - data-page-size="25" on the <table> to change the default rows per
 *     page (defaults to 10, matching the "Show 10 entries" convention)
 *
 * A row with a single <td colspan="..."> is treated as an empty-state /
 * message row, not a data row -- it's excluded from sorting, searching,
 * counting, and pagination, and left in place (always visible).
 */
(function () {
    const PAGE_SIZE_OPTIONS = [10, 25, 50, 100];

    function cellText(cell) {
        return (cell ? cell.textContent : '').trim().toLowerCase();
    }

    function isDataRow(row) {
        return !row.querySelector('td[colspan]');
    }

    function initTable(table) {
        const tbody = table.tBodies[0];
        if (!tbody) return;
        const dataRows = () => Array.from(tbody.rows).filter(isDataRow);

        const enhanced = !table.hasAttribute('data-no-search') && dataRows().length > 5;

        let currentPage = 1;
        const requestedPageSize = parseInt(table.getAttribute('data-page-size'), 10);
        let pageSize = PAGE_SIZE_OPTIONS.includes(requestedPageSize) ? requestedPageSize : 10;

        let search = null;
        let pageSizeSelect = null;
        let count = null;
        let paginationNav = null;

        // --- Build toolbar (page-size picker + search) and bottom bar
        //     (count + pagination) -----------------------------------
        if (enhanced) {
            const wrapper = table.closest('.table-wrapper');

            const toolbar = document.createElement('div');
            toolbar.className = 'dynamic-table-toolbar';

            const sizeLabel = document.createElement('label');
            sizeLabel.className = 'dynamic-table-pagesize';
            sizeLabel.append(document.createTextNode('Show '));

            pageSizeSelect = document.createElement('select');
            pageSizeSelect.className = 'input dynamic-table-pagesize-select';
            pageSizeSelect.setAttribute('aria-label', 'Rows per page');
            PAGE_SIZE_OPTIONS.forEach((n) => {
                const opt = document.createElement('option');
                opt.value = String(n);
                opt.textContent = String(n);
                if (n === pageSize) opt.selected = true;
                pageSizeSelect.appendChild(opt);
            });
            const allOpt = document.createElement('option');
            allOpt.value = 'all';
            allOpt.textContent = 'All';
            pageSizeSelect.appendChild(allOpt);
            sizeLabel.appendChild(pageSizeSelect);
            sizeLabel.append(document.createTextNode(' entries'));
            toolbar.appendChild(sizeLabel);

            search = document.createElement('input');
            search.type = 'search';
            search.className = 'input dynamic-table-search';
            search.placeholder = 'Search this table…';
            search.setAttribute('aria-label', 'Search this table');
            toolbar.appendChild(search);

            (wrapper || table).before(toolbar);

            const bottomBar = document.createElement('div');
            bottomBar.className = 'dynamic-table-bottombar';

            count = document.createElement('span');
            count.className = 'dynamic-table-count';
            bottomBar.appendChild(count);

            paginationNav = document.createElement('nav');
            paginationNav.className = 'dynamic-table-pagination';
            paginationNav.setAttribute('aria-label', 'Table pagination');
            bottomBar.appendChild(paginationNav);

            (wrapper || table).after(bottomBar);
        }

        // --- Search filter: marks rows as dt-search-hidden, returns the
        //     rows that survive the filter (in current DOM order) -------
        function matchedRows() {
            const query = search ? search.value.trim().toLowerCase() : '';
            return dataRows().filter((row) => {
                const matches = query === '' || cellText(row).includes(query);
                row.classList.toggle('dt-search-hidden', !matches);
                return matches;
            });
        }

        // --- Pagination: given the searched-and-sorted rows, shows only
        //     the current page, hiding the rest via dt-page-hidden -------
        function render() {
            if (!enhanced) return;

            const matches = matchedRows();
            const total = matches.length;
            const effectiveSize = pageSize === 'all' ? Math.max(total, 1) : pageSize;
            const totalPages = Math.max(1, Math.ceil(total / effectiveSize));
            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            const start = (currentPage - 1) * effectiveSize;
            const end = pageSize === 'all' ? total : Math.min(start + effectiveSize, total);

            dataRows().forEach((row) => row.classList.remove('dt-page-hidden'));
            matches.forEach((row, i) => {
                if (i < start || i >= end) row.classList.add('dt-page-hidden');
            });

            count.textContent = total === 0
                ? 'Showing 0 of 0 entries'
                : `Showing ${start + 1} to ${end} of ${total} entr${total === 1 ? 'y' : 'ies'}`;

            buildPagination(totalPages);
        }

        function buildPagination(totalPages) {
            paginationNav.innerHTML = '';
            if (totalPages <= 1) return;

            const makeBtn = (label, page, opts) => {
                opts = opts || {};
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = label;
                btn.className = 'dt-page-btn' + (opts.active ? ' dt-page-btn--active' : '');
                btn.disabled = !!opts.disabled;
                if (opts.active) btn.setAttribute('aria-current', 'page');
                btn.addEventListener('click', () => {
                    currentPage = page;
                    render();
                });
                return btn;
            };

            paginationNav.appendChild(makeBtn('Previous', currentPage - 1, { disabled: currentPage === 1 }));

            // Windowed page numbers with ellipses: always show first, last,
            // and a small window around the current page.
            const pages = [];
            for (let p = 1; p <= totalPages; p++) {
                if (p === 1 || p === totalPages || Math.abs(p - currentPage) <= 1) {
                    pages.push(p);
                }
            }
            let previousShown = 0;
            pages.forEach((p) => {
                if (p - previousShown > 1) {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'dt-page-ellipsis';
                    ellipsis.textContent = '…';
                    paginationNav.appendChild(ellipsis);
                }
                paginationNav.appendChild(makeBtn(String(p), p, { active: p === currentPage }));
                previousShown = p;
            });

            paginationNav.appendChild(makeBtn('Next', currentPage + 1, { disabled: currentPage === totalPages }));
        }

        if (search) {
            search.addEventListener('input', () => {
                currentPage = 1;
                render();
            });
        }

        if (pageSizeSelect) {
            pageSizeSelect.addEventListener('change', () => {
                pageSize = pageSizeSelect.value === 'all' ? 'all' : parseInt(pageSizeSelect.value, 10);
                currentPage = 1;
                render();
            });
        }

        // --- Sort ------------------------------------------------------
        const headerCells = Array.from((table.tHead && table.tHead.rows[0] && table.tHead.rows[0].cells) || []);
        headerCells.forEach((th, columnIndex) => {
            if (!th.hasAttribute('data-sortable')) return;

            th.addEventListener('click', () => {
                const nextDirection = th.classList.contains('dt-sort-asc') ? 'desc' : 'asc';
                headerCells.forEach((other) => other.classList.remove('dt-sort-asc', 'dt-sort-desc'));
                th.classList.add(nextDirection === 'asc' ? 'dt-sort-asc' : 'dt-sort-desc');

                const rows = dataRows();
                const numeric = rows.every((row) => {
                    const value = cellText(row.cells[columnIndex]).replace(/[,\s]/g, '');
                    return value === '' || !isNaN(parseFloat(value));
                });

                rows.sort((a, b) => {
                    const aText = cellText(a.cells[columnIndex]);
                    const bText = cellText(b.cells[columnIndex]);
                    const comparison = numeric
                        ? (parseFloat(aText.replace(/[,\s]/g, '')) || 0) - (parseFloat(bText.replace(/[,\s]/g, '')) || 0)
                        : aText.localeCompare(bText);
                    return nextDirection === 'asc' ? comparison : -comparison;
                });

                rows.forEach((row) => tbody.appendChild(row));
                currentPage = 1;
                render();
            });
        });

        render();
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('table.dynamic-table').forEach(initTable);
    });
})();
