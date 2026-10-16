<?php

namespace App\Http\Controllers;

use App\Support\TechnicalTestUploadAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UploadFileVerificationController extends Controller
{
    public function __construct(private TechnicalTestUploadAccess $technicalTestUploadAccess) {}

    public function applicant(Request $request, string $applicant): View
    {
        $applicantRecord = $this->technicalTestUploadAccess->applicantForRequest($applicant, $request, allowCompletedUpload: true);

        if ($this->technicalTestUploadAccess->hasTechnicalTestDocument($applicant)) {
            return $this->uploadedInformationView($applicantRecord);
        }

        return view('upload-files.verify-applicant', [
            'applicantId' => $applicant,
            'applicantName' => $applicantRecord?->full_name,
            'formAction' => $this->verificationCheckRoute($applicant),
            'isEmailVerified' => $this->technicalTestUploadAccess->isEmailVerified($request, $applicant),
            'maskedPhone' => $this->technicalTestUploadAccess->maskedPhone($applicantRecord),
        ]);
    }

    public function checkApplicant(Request $request, string $applicant): RedirectResponse
    {
        $this->technicalTestUploadAccess->applicantForRequest($applicant, $request, allowCompletedUpload: true);

        if ($this->technicalTestUploadAccess->hasTechnicalTestDocument($applicant)) {
            return redirect()->route('upload-files.generated.create', ['applicant' => $applicant]);
        }

        $validatedStep = $request->validate([
            'step' => ['required', 'in:email,pin'],
        ]);

        if ($validatedStep['step'] === 'email') {
            return $this->checkEmail($request, $applicant);
        }

        return $this->checkPin($request, $applicant);
    }

    private function checkEmail(Request $request, string $applicant): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $applicantRecord = $this->technicalTestUploadAccess->applicantForRequest($applicant, $request);

        if (! $this->technicalTestUploadAccess->emailMatches($applicantRecord, $validated['email'])) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'The email does not match the applicant record.']);
        }

        $this->technicalTestUploadAccess->markEmailVerified($request, $applicant);

        return redirect()->route('upload-files.verify.applicant.generated', ['applicant' => $applicant]);
    }

    private function checkPin(Request $request, string $applicant): RedirectResponse
    {
        if (! $this->technicalTestUploadAccess->isEmailVerified($request, $applicant)) {
            return redirect()->route('upload-files.verify.applicant.generated', ['applicant' => $applicant]);
        }

        $validated = $this->validatePin($request);
        $applicantRecord = $this->technicalTestUploadAccess->applicantForRequest($applicant, $request);

        if (! $this->technicalTestUploadAccess->phonePinMatches($applicantRecord, implode('', $validated['pin']))) {
            return back()->withErrors(['pin' => 'The last 4 phone digits do not match the applicant record.']);
        }

        $this->technicalTestUploadAccess->markUploadVerified($request, $applicant);

        return redirect()->route('upload-files.generated.create', ['applicant' => $applicant]);
    }

    /**
     * @return array{pin: array<int, string>}
     */
    private function validatePin(Request $request): array
    {
        return $request->validate([
            'pin' => ['required', 'array', 'size:4'],
            'pin.*' => ['required', 'digits:1'],
        ]);
    }

    private function verificationCheckRoute(string $applicant): string
    {
        return route('upload-files.verify.applicant.generated.check', ['applicant' => $applicant]);
    }

    private function uploadedInformationView(object $applicant): View
    {
        return view('upload-files.information', [
            'applicantName' => $applicant->full_name ?? null,
        ]);
    }
}
