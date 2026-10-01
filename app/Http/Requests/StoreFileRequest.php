<?php

namespace App\Http\Requests;

use App\Support\FileType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.config('files.max_size_kb'), 'extensions:pdf,docx'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('file')) {
                    return;
                }

                $file = $this->file('file');
                $name = $file->getClientOriginalName();

                // MySQL rejects it on insert with a 500
                if (! mb_check_encoding($name, 'UTF-8')) {
                    $validator->errors()->add('file', 'The file name is not valid UTF-8.');

                    return;
                }

                $type = FileType::detect($file);

                if ($type === null) {
                    $validator->errors()->add('file', 'The file must be a PDF or DOCX document.');

                    return;
                }

                // a PDF renamed to .docx passes the extensions rule, the content has to match too
                if ($type !== strtolower($file->getClientOriginalExtension())) {
                    $validator->errors()->add('file', 'The file content does not match its extension.');

                    return;
                }

                if (mb_strlen($name) > 255) {
                    $validator->errors()->add('file', 'The file name must not be longer than 255 characters.');
                }
            },
        ];
    }
}
