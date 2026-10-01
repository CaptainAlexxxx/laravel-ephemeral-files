@extends('layouts.app')

@section('title', 'Files')

@section('content')
    <h1 class="h4 mb-3">Files</h1>

    <div id="files-alert"></div>

    @if ($files->total() === 0)
        <p id="empty-state">No files yet. <a href="{{ route('files.create') }}">Upload one</a>.</p>
    @else
        <table class="table table-striped" id="files-table" data-previous-page-url="{{ $files->previousPageUrl() }}">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Size</th>
                    <th>Uploaded</th>
                    <th>Expires in</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($files as $file)
                    <tr data-id="{{ $file->id }}">
                        <td>{{ $file->original_name }}</td>
                        <td>{{ \Illuminate\Support\Number::fileSize($file->size) }}</td>
                        <td>{{ $file->created_at->diffForHumans() }}</td>
                        <td title="{{ $file->expires_at->utc()->format('Y-m-d H:i:s \U\T\C') }}">{{ $file->expires_at->isPast() ? 'expired, pending purge' : $file->expires_at->diffForHumans() }}</td>
                        <td>
                            <button type="button" class="btn btn-sm btn-danger delete-file" data-url="{{ route('files.destroy', $file) }}">Delete</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $files->links('pagination::bootstrap-5') }}
    @endif
@endsection

@push('scripts')
    <script>
        $(function () {
            const $table = $('#files-table');

            function afterRowRemoved() {
                if ($table.find('tbody tr').length > 0) {
                    return;
                }

                const previousPageUrl = $table.data('previous-page-url');
                if (previousPageUrl) {
                    location.href = previousPageUrl;
                } else {
                    location.reload();
                }
            }

            $table.on('click', '.delete-file', function () {
                if (!confirm('Delete this file?')) {
                    return;
                }

                const $button = $(this);
                const $row = $button.closest('tr');
                const url = $button.data('url');

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
                    });
            });
        });
    </script>
@endpush
