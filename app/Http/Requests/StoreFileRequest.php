<?php

namespace App\Http\Requests;

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
            'file' => ['required', 'file', 'max:'.config('files.max_size_kb'), 'extensions:pdf,docx', 'mimes:pdf,docx'],
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

                // Both rules accept any allowed type independently, so a PDF renamed to .docx passes them.
                if ($file->guessExtension() !== strtolower($file->getClientOriginalExtension())) {
                    $validator->errors()->add('file', 'The file content does not match its extension.');

                    return;
                }

                if (mb_strlen($file->getClientOriginalName()) > 255) {
                    $validator->errors()->add('file', 'The file name must not be longer than 255 characters.');
                }
            },
        ];
    }
}
