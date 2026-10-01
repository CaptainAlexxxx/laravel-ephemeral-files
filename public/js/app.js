$.ajaxSetup({
    headers: {
        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
        Accept: 'application/json',
    },
});

function showAlert(container, type, message) {
    const $alert = $('<div>', { class: `alert alert-${type}` }).text(message);
    container.empty().append($alert);
}

function ajaxErrorMessage(xhr, fallback) {
    // 419: the CSRF token is stale, retrying without a reload keeps failing
    if (xhr.status === 419) {
        return 'Your session expired. Reload the page and try again.';
    }

    return fallback;
}
