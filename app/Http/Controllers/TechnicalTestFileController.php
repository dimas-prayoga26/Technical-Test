<?php

namespace App\Http\Controllers;

use App\Support\TechnicalTestUploadAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TechnicalTestFileController extends Controller
{
    public function __construct(private TechnicalTestUploadAccess $technicalTestUploadAccess) {}

    public function show(Request $request, string $applicant): StreamedResponse|BinaryFileResponse
    {
        $document = $this->technicalTestUploadAccess->technicalTestDocumentForRequest($applicant, $request);
        $filePath = $this->safeRelativePath((string) $document->file_path);
        $fileName = basename((string) ($document->original_name ?: $filePath));
        $headers = [
            'Content-Type' => (string) ($document->mime_type ?: 'application/octet-stream'),
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (Str::endsWith(Str::lower($filePath), '.svg')) {
            // SVG is XML that browsers can execute when opened directly, so sandbox it.
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        $disk = (string) config('technical_test_upload.storage_disk', 'public');

        if (Storage::disk($disk)->exists($filePath)) {
            return Storage::disk($disk)->response($filePath, $fileName, $headers);
        }

        foreach ($this->possiblePublicFilePaths($filePath) as $publicFilePath) {
            if (File::exists($publicFilePath)) {
                return response()->file($publicFilePath, $headers);
            }
        }

        abort(404);
    }

    private function safeRelativePath(string $filePath): string
    {
        $filePath = ltrim(str_replace('\\', '/', trim($filePath)), '/');

        abort_if(
            $filePath === ''
            || $filePath === '..'
            || Str::contains($filePath, '../')
            || preg_match('/^[A-Za-z]:/', $filePath) === 1,
            404,
        );

        return $filePath;
    }

    /**
     * @return array<int, string>
     */
    private function possiblePublicFilePaths(string $filePath): array
    {
        return array_values(array_unique([
            public_path($filePath),
            base_path($filePath),
            dirname(public_path()).DIRECTORY_SEPARATOR.$filePath,
            dirname(base_path()).DIRECTORY_SEPARATOR.$filePath,
            storage_path('app/public/'.$filePath),
        ]));
    }
}
