document.querySelectorAll('.clickable-row').forEach(row => {
    row.addEventListener('mousedown', event => {
        const id = row.dataset.id;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'Edit.php';
        form.style.display = 'none';

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'id';
        input.value = id;
        form.appendChild(input);

        if (event.button === 1) {
            event.preventDefault();
            form.target = '_blank';
            document.body.appendChild(form);
            form.submit();
            form.remove();
        } else if (event.button === 0) {
            event.preventDefault();
            document.body.appendChild(form);
            form.submit();
        }
    });
});


let currentSort = { index: null, asc: true };

document.querySelectorAll('.list-table th').forEach((th, index) => {
    th.addEventListener('click', () => {
        const tbody = th.closest('table').querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const isAsc = currentSort.index === index ? !currentSort.asc : true;
        currentSort = { index, asc: isAsc };

        rows.sort((a, b) => {
            const aText = a.children[index].innerText.trim().toLowerCase();
            const bText = b.children[index].innerText.trim().toLowerCase();
            if (th.innerText.toLowerCase().includes('datum')) {
                const parseDate = str => {
                    const parts = str.split(/[.\s:]/);
                    return new Date(parts[2], parts[1] - 1, parts[0], parts[3] || 0, parts[4] || 0);
                };
                return (isAsc ? 1 : -1) * (parseDate(aText) - parseDate(bText));
            }
            return isAsc ? aText.localeCompare(bText) : bText.localeCompare(aText);
        });

        rows.forEach(row => tbody.appendChild(row));
        document.querySelectorAll('.list-table th').forEach(th => th.classList.remove('sorted-asc', 'sorted-desc'));
        th.classList.add(isAsc ? 'sorted-asc' : 'sorted-desc');
    });
});

document.getElementById('table-search')?.addEventListener('input', function () {
    const searchTerm = this.value.toLowerCase();
    document.querySelectorAll('.list-table tbody tr').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(searchTerm) ? '' : 'none';
    });
});


function downloadCSV() {
    const table = window.listConfig?.table || 'tabelle';
    const url = `../create_csv.php?table=${encodeURIComponent(table)}`;
    window.open(url, '_blank');
}

