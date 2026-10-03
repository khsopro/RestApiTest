// DRK-CMS – kleine Helfer für den Verwaltungsbereich (funktioniert auch ohne JavaScript)
document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
        e.preventDefault();
    }
});

document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-add-row]');
    if (add) {
        var tbody = document.getElementById(add.getAttribute('data-add-row')).tBodies[0];
        var row = tbody.rows[tbody.rows.length - 1].cloneNode(true);
        row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        tbody.appendChild(row);
        row.querySelector('input').focus();
    }
    var remove = e.target.closest('[data-remove-row]');
    if (remove) {
        var tr = remove.closest('tr');
        if (tr.parentNode.rows.length > 1) {
            tr.remove();
        } else {
            tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        }
    }
});
