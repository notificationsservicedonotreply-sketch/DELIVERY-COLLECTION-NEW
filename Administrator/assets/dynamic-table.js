/**
 * Generic sort + search for any <table class="table dynamic-table">.
 * The responsive mobile card layout is pure CSS (see dashboard.css) and
 * needs no JS at all -- this file only adds click-to-sort and a search box.
 *
 * Opt-in per table:
 *   - class="dynamic-table" on the <table>
 *   - data-sortable on each sortable <th> (skip it on action/photo columns)
 *   - data-label="Column Name" on each <td>, used by the mobile card layout
 *   - data-no-search on the <table> to skip the search box (e.g. an already
 *     tiny table where a search box would just be clutter)
 *
 * A row with a single <td colspan="..."> is treated as an empty-state /
 * message row, not a data row -- it's excluded from sorting and searching,
 * and left in place.
 */
(function () {
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

        // --- Search --------------------------------------------------
        // Skipped for very small tables (not worth the clutter) and for
        // tables that explicitly opt out.
        if (!table.hasAttribute('data-no-search') && dataRows().length > 5) {
            const toolbar = document.createElement('div');
            toolbar.className = 'dynamic-table-toolbar';

            const search = document.createElement('input');
            search.type = 'search';
            search.className = 'input dynamic-table-search';
            search.placeholder = 'Search this table…';
            search.setAttribute('aria-label', 'Search this table');

            const count = document.createElement('span');
            count.className = 'dynamic-table-count';

            toolbar.appendChild(search);
            toolbar.appendChild(count);

            const wrapper = table.closest('.table-wrapper');
            (wrapper || table).before(toolbar);

            const updateCount = () => {
                const rows = dataRows();
                const visible = rows.filter((row) => !row.classList.contains('dt-hidden')).length;
                count.textContent = visible === rows.length
                    ? `${rows.length} row${rows.length === 1 ? '' : 's'}`
                    : `${visible} / ${rows.length} rows`;
            };

            search.addEventListener('input', () => {
                const query = search.value.trim().toLowerCase();
                dataRows().forEach((row) => {
                    const matches = query === '' || cellText(row).includes(query);
                    row.classList.toggle('dt-hidden', !matches);
                });
                updateCount();
            });

            updateCount();
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
            });
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('table.dynamic-table').forEach(initTable);
    });
})();
