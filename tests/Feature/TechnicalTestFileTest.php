<?php

namespace Tests\Feature;

use App\Support\TechnicalTestUploadAccess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TechnicalTestFileTest extends TestCase
{
    private const APPLICANT_ID = '11111111-1111-8111-8111-111111111111';

    public function test_uploaded_technical_test_file_can_be_served(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('uploaded-technical-tests/result.zip', 'zip-content');
        $this->mockTechnicalTestDocument('uploaded-technical-tests/result.zip', 'result.zip', 'application/zip');

        $response = $this->get($this->technicalTestFileRoute());

        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('content-type'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
        $this->assertNull($response->headers->get('content-security-policy'));
    }

    public function test_uploaded_svg_is_served_inside_a_sandbox(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('uploaded-technical-tests/diagram.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->mockTechnicalTestDocument('uploaded-technical-tests/diagram.svg', 'diagram.svg', 'image/svg+xml');

        $response = $this->get($this->technicalTestFileRoute());

        $response->assertOk();
        $this->assertSame('image/svg+xml', $response->headers->get('content-type'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('content-security-policy'));
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('content-security-policy'));
    }

    public function test_missing_technical_test_file_returns_not_found(): void
    {
        Storage::fake('public');
        $this->mockTechnicalTestDocument('uploaded-technical-tests/missing.zip', 'missing.zip', 'application/zip');

        $response = $this->get($this->technicalTestFileRoute());

        $response->assertNotFound();
    }

    public function test_uploaded_technical_test_file_can_be_served_from_project_root_upload_directory(): void
    {
        Storage::fake('public');

        $filePath = base_path('uploaded-technical-tests/base-path-result.jpg');
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, 'image-content');
        $this->mockTechnicalTestDocument('uploaded-technical-tests/base-path-result.jpg', 'base-path-result.jpg', 'image/jpeg');

        try {
            $response = $this->get($this->technicalTestFileRoute());

            $response->assertOk();
            $this->assertSame('image/jpeg', $response->headers->get('content-type'));
        } finally {
            File::deleteDirectory(dirname($filePath));
        }
    }

    public function test_unsafe_technical_test_file_path_returns_not_found(): void
    {
        Storage::fake('public');
        $this->mockTechnicalTestDocument('../secret.zip', 'secret.zip', 'application/zip');

        $response = $this->get($this->technicalTestFileRoute());

        $response->assertNotFound();
    }

    private function mockTechnicalTestDocument(string $filePath, string $originalName, string $mimeType): void
    {
        $this->mock(TechnicalTestUploadAccess::class, function ($mock) use ($filePath, $originalName, $mimeType): void {
            $mock->shouldReceive('technicalTestDocumentForRequest')
                ->once()
                ->andReturn((object) [
                    'file_path' => $filePath,
                    'original_name' => $originalName,
                    'mime_type' => $mimeType,
                ]);
        });
    }

    private function technicalTestFileRoute(): string
    {
        return route('technical-test-files.show', ['applicant' => self::APPLICANT_ID]);
    }
}
