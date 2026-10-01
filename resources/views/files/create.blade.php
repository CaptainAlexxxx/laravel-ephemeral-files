@extends('layouts.app')

@section('title', 'Upload')

@section('content')
    <div class="card" style="max-width: 32rem; margin: 0 auto;">
        <div class="card-body">
            <h1 class="h4 card-title mb-3">Upload a file</h1>

            <div id="upload-alert"></div>

            <form id="upload-form" data-max-kb="{{ config('files.max_size_kb') }}">
                <div class="mb-3">
                    <input type="file" class="form-control" id="file" name="file" accept=".pdf,.docx">
                    <div class="form-text">PDF or DOCX, up to {{ (int) (config('files.max_size_kb') / 1024) }} MB.</div>
                </div>

                <div class="progress mb-3" style="height: 1rem; display: none;" id="upload-progress-wrap">
                    <div class="progress-bar" id="upload-progress" role="progressbar" style="width: 0%"></div>
                </div>

                <button type="submit" class="btn btn-primary" id="upload-submit">Upload</button>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            const $form = $('#upload-form');
            const $fileInput = $('#file');
            const $submit = $('#upload-submit');
            const $alert = $('#upload-alert');
            const $progressWrap = $('#upload-progress-wrap');
            const $progress = $('#upload-progress');
            const maxKb = parseInt($form.data('max-kb'), 10);

            function resetProgress() {
                $progressWrap.hide();
                $progress.css('width', '0%');
            }

            $form.on('submit', function (e) {
                e.preventDefault();
                $alert.empty();

                const file = $fileInput[0].files[0];
                if (!file) {
                    showAlert($alert, 'danger', 'Please choose a file.');
                    return;
                }

                const ext = file.name.split('.').pop().toLowerCase();
                if (ext !== 'pdf' && ext !== 'docx') {
                    showAlert($alert, 'danger', 'Only PDF or DOCX files are allowed.');
                    return;
                }

                if (file.size > maxKb * 1024) {
                    showAlert($alert, 'danger', 'File is too large.');
                    return;
                }

                const formData = new FormData();
                formData.append('file', file);

                $submit.prop('disabled', true);
                $progressWrap.show();

                $.ajax({
                    url: '{{ route('files.store') }}',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    xhr: function () {
                        const xhr = $.ajaxSettings.xhr();
                        if (xhr.upload) {
                            xhr.upload.addEventListener('progress', function (evt) {
                                if (evt.lengthComputable) {
                                    const pct = Math.round((evt.loaded / evt.total) * 100);
                                    $progress.css('width', pct + '%');
                                }
                            });
                        }
                        return xhr;
                    },
                })
                    .done(function (response) {
                        const name = response.data.original_name;
                        const $link = $('<a>', { href: '{{ route('files.index') }}' }).text('View files');
                        const $msg = $('<div>').text('Uploaded: ' + name + ' ');
                        $msg.append($link);
                        $alert.empty().append($('<div>', { class: 'alert alert-success' }).append($msg));
                        $form[0].reset();
                        resetProgress();
                    })
                    .fail(function (xhr) {
                        if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors && xhr.responseJSON.errors.file) {
                            showAlert($alert, 'danger', xhr.responseJSON.errors.file[0]);
                        } else if (xhr.status === 413) {
                            showAlert($alert, 'danger', 'File is too large.');
                        } else {
                            showAlert($alert, 'danger', 'Upload failed, try again.');
                        }
                        resetProgress();
                    })
                    .always(function () {
                        $submit.prop('disabled', false);
                    });
            });
        });
    </script>
@endpush
