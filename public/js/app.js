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
