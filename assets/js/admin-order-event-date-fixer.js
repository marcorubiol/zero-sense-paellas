document.addEventListener('DOMContentLoaded', function() {
    function fixEventDates() {
        const dateColumns = document.querySelectorAll('td.event_date');
        dateColumns.forEach(function(column) {
            const ourSpan = column.querySelector('span.zs-event-date');
            if (ourSpan) {
                const timestamp = ourSpan.getAttribute('data-timestamp');
                if (timestamp && !isNaN(timestamp)) {
                    const date = new Date(timestamp * 1000);
                    const day = String(date.getDate()).padStart(2, '0');
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const year = date.getFullYear();
                    const formattedDate = `${day}/${month}/${year}`;

                    if (column.innerHTML !== formattedDate) {
                        column.innerHTML = formattedDate;
                    }
                }
            }
        });
    }

    // Use a MutationObserver to watch for changes in the table.
    const observer = new MutationObserver(function() {
        // We can simply run the fixer on any change. It's idempotent.
        fixEventDates();
    });

    // Start observing the main content area for changes.
    const targetNode = document.getElementById('wpbody-content');
    if (targetNode) {
        observer.observe(targetNode, { childList: true, subtree: true });
    }

    // Initial run for the first page load.
    fixEventDates();
});
