<?php

namespace Tests\Feature;

use App\Models\StoredFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesFileFixtures;
use Tests\TestCase;

class FileUploadTest extends TestCase
{
    use MakesFileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('files.disk'));
        Notification::fake();
    }

    public function test_valid_pdf_upload_is_stored_with_a_row(): void
    {
        $file = $this->uploadedFileFromBytes($this->minimalPdfBytes(), 'report.pdf');

        $response = $this->postJson('/files', ['file' => $file]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.original_name', 'report.pdf');

        $this->assertDatabaseCount('stored_files', 1);
        $row = StoredFile::first();
        $this->assertSame('report.pdf', $row->original_name);
        $this->assertSame('application/pdf', $row->mime_type);
        $this->assertSame(strlen($this->minimalPdfBytes()), $row->size);
        $this->assertEquals(
            $row->created_at->addMinutes(config('files.ttl_minutes'))->timestamp,
            $row->expires_at->timestamp
        );

        Storage::disk(config('files.disk'))->assertExists($row->path);
    }

    public function test_valid_docx_upload_is_accepted(): void
    {
        $file = $this->uploadedFileFromBytes($this->minimalDocxBytes(), 'contract.docx');

        $response = $this->postJson('/files', ['file' => $file]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('stored_files', ['original_name' => 'contract.docx']);
    }

    public function test_file_at_exactly_the_size_limit_is_accepted(): void
    {
        $file = $this->uploadedFileFromBytes($this->pdfBytesOfSize(10240 * 1024), 'exact.pdf');

        $response = $this->postJson('/files', ['file' => $file]);

        $response->assertStatus(201);
    }

    public function test_file_one_byte_over_the_size_limit_is_rejected_with_422(): void
    {
        $file = $this->uploadedFileFromBytes($this->pdfBytesOfSize(10240 * 1024 + 1), 'toobig.pdf');

        $response = $this->postJson('/files', ['file' => $file]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('stored_files', 0);
    }

    public function test_empty_file_is_rejected(): void
    {
        $file = $this->uploadedFileFromBytes('', 'empty.pdf');

        $response = $this->postJson('/files', ['file' => $file]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('stored_files', 0);
    }

    public function test_text_file_renamed_to_pdf_is_rejected(): void
    {
        $file = $this->uploadedFileFromBytes('just a plain text file, not a pdf', 'notes.pdf');

        $response = $this->postJson('/files', ['file' => $file]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('stored_files', 0);
    }

    public function test_pdf_renamed_to_docx_is_rejected(): void
    {
        $file = $this->uploadedFileFromBytes($this->minimalPdfBytes(), 'fake.docx');

        $response = $this->postJson('/files', ['file' => $file]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('stored_files', 0);
    }

    public function test_generic_zip_renamed_to_docx_is_rejected(): void
    {
        $file = $this->uploadedFileFromBytes($this->genericZipBytes(), 'archive.docx');

        $response = $this->postJson('/files', ['file' => $file]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('stored_files', 0);
    }

    public function test_docx_that_libmagic_reports_as_zip_is_accepted_with_the_docx_mime(): void
    {
        $file = $this->uploadedFileFromBytes($this->docxBytesWithDocPropsFirst(), 'contract.docx');

        $response = $this->postJson('/files', ['file' => $file]);

        $response->assertStatus(201);
        $row = StoredFile::first();
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $row->mime_type);
        $this->assertStringEndsWith('.docx', $row->path);
    }

    public function test_docx_structured_zip_renamed_to_pdf_is_rejected(): void
    {
        $file = $this->uploadedFileFromBytes($this->docxBytesWithDocPropsFirst(), 'contract.pdf');

        $response = $this->postJson('/files', ['file' => $file]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('stored_files', 0);
    }

    public function test_file_name_with_invalid_utf8_is_rejected(): void
    {
        $file = $this->uploadedFileFromBytes($this->minimalPdfBytes(), "\xff.pdf");

        $response = $this->postJson('/files', ['file' => $file]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file' => 'The file name is not valid UTF-8.']);
        $this->assertDatabaseCount('stored_files', 0);
    }

    public function test_expires_at_is_exactly_created_at_plus_ttl(): void
    {
        // every clock read moves one second forward, so two separate now() calls cannot match
        $start = Carbon::parse('2026-01-01 00:00:00');
        $reads = 0;
        Carbon::setTestNow(function () use ($start, &$reads) {
            return $start->copy()->addSeconds($reads++);
        });

        $file = $this->uploadedFileFromBytes($this->minimalPdfBytes(), 'report.pdf');

        $this->postJson('/files', ['file' => $file])->assertStatus(201);

        Carbon::setTestNow();

        $row = StoredFile::first();
        $this->assertSame(
            $row->created_at->copy()->addMinutes(config('files.ttl_minutes'))->format('Y-m-d H:i:s'),
            $row->expires_at->format('Y-m-d H:i:s')
        );
    }

    public function test_upload_response_is_json_not_a_redirect(): void
    {
        $file = $this->uploadedFileFromBytes($this->minimalPdfBytes(), 'report.pdf');

        $response = $this->post('/files', ['file' => $file], ['Accept' => 'application/json']);

        $response->assertHeader('content-type', 'application/json');
    }

    protected function pdfBytesOfSize(int $totalBytes): string
    {
        $header = "%PDF-1.4\n";
        $footer = "\ntrailer\n<< /Size 1 /Root 1 0 R >>\n%%EOF";

        $padLen = $totalBytes - strlen($header) - strlen($footer) - 2;
        $comment = '%'.str_repeat('A', $padLen)."\n";

        $bytes = $header.$comment.$footer;

        $this->assertSame($totalBytes, strlen($bytes));

        return $bytes;
    }
}
