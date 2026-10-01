@extends('layouts.app')

@section('title', 'Files')

@section('content')
    <div id="files-alert"></div>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <div>
                <span class="h5 mb-0">Files</span>
                <span class="text-muted small ms-2">{{ $files->total() }} total</span>
            </div>
            <a href="{{ route('files.create') }}" class="btn btn-primary btn-sm">Upload</a>
        </div>

        @if ($files->total() === 0)
            <div class="card-body text-center text-muted py-5" id="empty-state">
                No files yet. <a href="{{ route('files.create') }}">Upload one</a>.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="files-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Size</th>
                            <th>Uploaded</th>
                            <th class="text-nowrap">Expires in</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($files as $file)
                            <tr data-id="{{ $file->id }}">
                                <td>
                                    @php $isPdf = str_contains($file->mime_type, 'pdf'); @endphp
                                    <div class="d-flex align-items-center">
                                        <span class="badge {{ $isPdf ? 'bg-danger-subtle text-danger-emphasis' : 'bg-primary-subtle text-primary-emphasis' }} me-2">{{ $isPdf ? 'PDF' : 'DOCX' }}</span>
                                        <span class="text-truncate-name" title="{{ $file->original_name }}">{{ $file->original_name }}</span>
                                    </div>
                                </td>
                                <td class="text-nowrap">{{ \Illuminate\Support\Number::fileSize($file->size) }}</td>
                                <td class="text-nowrap">{{ $file->created_at->diffForHumans() }}</td>
                                <td class="text-nowrap" title="{{ $file->expires_at->utc()->format('Y-m-d H:i:s \U\T\C') }}">
                                    <span class="badge bg-warning-subtle text-warning-emphasis">{{ $file->expires_at->isPast() ? 'expired, pending purge' : $file->expires_at->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-danger delete-file" data-url="{{ route('files.destroy', $file) }}" data-name="{{ $file->original_name }}">Delete</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($files->hasPages())
                <div class="card-footer bg-white">
                    {{ $files->links('pagination::bootstrap-5') }}
                </div>
            @endif
        @endif
    </div>

    <div class="modal fade" id="delete-file-modal" tabindex="-1" aria-labelledby="delete-file-modal-label" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="delete-file-modal-label">Delete file</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Delete <strong id="delete-file-modal-name"></strong>? The deletion notice is sent by email.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="delete-file-modal-confirm">Delete</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            const $table = $('#files-table');
            const $modal = $('#delete-file-modal');
            const modal = new bootstrap.Modal($modal[0]);
            let $pendingButton = null;

            function afterRowRemoved() {
                if ($table.find('tbody tr').length === 0) {
                    location.reload();
                }
            }

            $table.on('click', '.delete-file', function () {
                $pendingButton = $(this);
                $('#delete-file-modal-name').text($pendingButton.data('name'));
                modal.show();
            });

            $('#delete-file-modal-confirm').on('click', function () {
                const $button = $pendingButton;
                if (!$button) {
                    return;
                }

                const $confirmButton = $(this);
                const $row = $button.closest('tr');
                const url = $button.data('url');

                $confirmButton.prop('disabled', true);
                $button.prop('disabled', true);

                $.ajax({ url: url, method: 'DELETE' })
                    .done(function () {
                        $row.remove();
                        showAlert($('#files-alert'), 'success', 'File deleted.');
                        afterRowRemoved();
                    })
                    .fail(function (xhr) {
                        if (xhr.status === 404) {
                            $row.remove();
                            showAlert($('#files-alert'), 'info', 'File was already deleted.');
                            afterRowRemoved();
                            return;
                        }

                        showAlert($('#files-alert'), 'danger', ajaxErrorMessage(xhr, 'Could not delete the file, try again.'));
                        $button.prop('disabled', false);
                    })
                    .always(function () {
                        $confirmButton.prop('disabled', false);
                        modal.hide();
                    });
            });

            $modal.on('hidden.bs.modal', function () {
                if ($pendingButton) {
                    $pendingButton.prop('disabled', false);
                }
                $pendingButton = null;
            });
        });
    </script>
@endpush
