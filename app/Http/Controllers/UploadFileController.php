<?php

namespace App\Http\Controllers;

use App\Rules\SafeTechnicalTestDocument;
use App\Support\TechnicalTestUploadAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;

class UploadFileController extends Controller
{
    public function __construct(private TechnicalTestUploadAccess $technicalTestUploadAccess) {}

    public function create(Request $request, string $applicant): RedirectResponse|View
    {
        $applicantRecord = $this->technicalTestUploadAccess->applicantForRequest($applicant, $request, allowCompletedUpload: true);

        if ($this->technicalTestUploadAccess->hasTechnicalTestDocument($applicant)) {
            return view('upload-files.information', [
                'applicantName' => $applicantRecord->full_name ?? null,
            ]);
        }

        if (! $this->technicalTestUploadAccess->isUploadVerified($request, $applicant)) {
            return redirect()->route('upload-files.verify.applicant.generated', ['applicant' => $applicant]);
        }

        return view('upload-files.create', [
            'formAction' => route('upload-files.generated.store', ['applicant' => $applicant]),
        ]);
    }

    public function store(Request $request, string $applicant): RedirectResponse
    {
        $this->technicalTestUploadAccess->applicantForRequest($applicant, $request, allowCompletedUpload: true);

        if ($this->technicalTestUploadAccess->hasTechnicalTestDocument($applicant)) {
            return redirect()->route('upload-files.generated.create', ['applicant' => $applicant]);
        }

        if (! $this->technicalTestUploadAccess->isUploadVerified($request, $applicant)) {
            return redirect()->route('upload-files.verify.applicant.generated', ['applicant' => $applicant]);
        }

        $document = $this->validatedDocument($request);
        $uploadedFile = $this->technicalTestUploadAccess->storeTechnicalTestDocument($applicant, $document, $request);

        return redirect()
            ->route('upload-files.generated.create', ['applicant' => $applicant])
            ->with('status', 'File uploaded successfully.')
            ->with('uploaded_file', $uploadedFile);
    }

    private function validatedDocument(Request $request): UploadedFile
    {
        $validated = $request->validate([
            'document' => [
                'required',
                'file',
                'extensions:'.implode(',', SafeTechnicalTestDocument::ALLOWED_EXTENSIONS),
                'max:5120',
                new SafeTechnicalTestDocument,
            ],
        ], [
            'document.required' => 'Please upload your technical test result.',
            'document.file' => 'Please upload a valid technical test file.',
            'document.extensions' => 'The file must be a ZIP, RAR, PDF, DOCX, XLSX, PNG, JPG, or SVG file.',
            'document.max' => 'The file may not be greater than 5 MB.',
        ]);

        /** @var UploadedFile $document */
        $document = $validated['document'];

        return $document;
    }
}
