<?php

namespace Tests\Feature;

use App\Support\TechnicalTestUploadAccess;
use Tests\TestCase;

class UploadFileVerificationTest extends TestCase
{
    private const APPLICANT_ID = '11111111-1111-8111-8111-111111111111';

    public function test_applicant_verification_page_renders_email_step(): void
    {
        $this->mockTechnicalTestAccessForEmailStep();

        $response = $this->get($this->generatedVerifyRoute());

        $response
            ->assertOk()
            ->assertSee('Verify Applicant Email')
            ->assertSee('Technical Test Applicant')
            ->assertDontSee('demo@example.com')
            ->assertDontSee('0812 3456 ****')
            ->assertDontSee('7890');
    }

    public function test_applicant_verification_page_shows_information_when_document_was_uploaded(): void
    {
        $this->mockTechnicalTestAccessForCompletedUpload();

        $response = $this->get($this->generatedVerifyRoute());

        $response
            ->assertOk()
            ->assertSee('Technical test received')
            ->assertSee('Technical Test Applicant')
            ->assertDontSee('For Your Information')
            ->assertDontSee('Verify Applicant Email');
    }

    public function test_valid_email_redirects_back_to_applicant_verification_phone_step(): void
    {
        $this->mockTechnicalTestAccessForEmailCheck(emailMatches: true);

        $response = $this->post($this->generatedVerifyCheckRoute(), [
            'step' => 'email',
            'email' => 'candidate@example.test',
        ]);

        $response
            ->assertRedirect($this->generatedVerifyRoute());
    }

    public function test_phone_step_renders_after_email_verification(): void
    {
        $this->mockTechnicalTestAccessForPhoneStep();

        $response = $this
            ->withSession(['upload_file_email_verified_applicant_id' => self::APPLICANT_ID])
            ->get($this->generatedVerifyRoute());

        $response
            ->assertOk()
            ->assertSee('Enter the Last 4 Phone Digits')
            ->assertSee('0812 3456 ****')
            ->assertDontSee('Verify Applicant Email')
            ->assertDontSee('7890');
    }

    public function test_valid_phone_digits_grant_upload_access(): void
    {
        $this->mockTechnicalTestAccessForPinCheck(pinMatches: true);

        $response = $this
            ->withSession(['upload_file_email_verified_applicant_id' => self::APPLICANT_ID])
            ->post($this->generatedVerifyCheckRoute(), [
                'step' => 'pin',
                'pin' => ['7', '8', '9', '0'],
            ]);

        $response
            ->assertRedirect($this->generatedUploadRoute());
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->mockTechnicalTestAccessForEmailCheck(emailMatches: false);

        $response = $this
            ->from($this->generatedVerifyRoute())
            ->post($this->generatedVerifyCheckRoute(), [
                'step' => 'email',
                'email' => 'wrong@example.com',
            ]);

        $response
            ->assertRedirect($this->generatedVerifyRoute())
            ->assertSessionHasErrors('email');
    }

    public function test_invalid_phone_digits_are_rejected(): void
    {
        $this->mockTechnicalTestAccessForPinCheck(pinMatches: false);

        $response = $this
            ->withSession(['upload_file_email_verified_applicant_id' => self::APPLICANT_ID])
            ->from($this->generatedVerifyRoute())
            ->post($this->generatedVerifyCheckRoute(), [
                'step' => 'pin',
                'pin' => ['0', '0', '0', '0'],
            ]);

        $response
            ->assertRedirect($this->generatedVerifyRoute())
            ->assertSessionHasErrors('pin');
    }

    public function test_phone_step_post_redirects_to_email_step_without_email_verification(): void
    {
        $this->mockTechnicalTestAccessForMissingEmailVerification();

        $response = $this->post($this->generatedVerifyCheckRoute(), [
            'step' => 'pin',
            'pin' => ['7', '8', '9', '0'],
        ]);

        $response->assertRedirect($this->generatedVerifyRoute());
    }

    private function mockTechnicalTestAccessForEmailStep(): void
    {
        $this->mock(TechnicalTestUploadAccess::class, function ($mock): void {
            $mock->shouldReceive('applicantForRequest')->andReturn($this->applicantRecord());
            $mock->shouldReceive('hasTechnicalTestDocument')->andReturn(false);
            $mock->shouldReceive('isEmailVerified')->andReturn(false);
            $mock->shouldReceive('maskedPhone')->andReturn('0812 3456 ****');
        });
    }

    private function mockTechnicalTestAccessForPhoneStep(): void
    {
        $this->mock(TechnicalTestUploadAccess::class, function ($mock): void {
            $mock->shouldReceive('applicantForRequest')->andReturn($this->applicantRecord());
            $mock->shouldReceive('hasTechnicalTestDocument')->andReturn(false);
            $mock->shouldReceive('isEmailVerified')->andReturn(true);
            $mock->shouldReceive('maskedPhone')->andReturn('0812 3456 ****');
        });
    }

    private function mockTechnicalTestAccessForEmailCheck(bool $emailMatches): void
    {
        $this->mock(TechnicalTestUploadAccess::class, function ($mock) use ($emailMatches): void {
            $mock->shouldReceive('applicantForRequest')->andReturn($this->applicantRecord());
            $mock->shouldReceive('hasTechnicalTestDocument')->andReturn(false);
            $mock->shouldReceive('emailMatches')->andReturn($emailMatches);
            $mock->shouldReceive('markEmailVerified')->zeroOrMoreTimes();
        });
    }

    private function mockTechnicalTestAccessForPinCheck(bool $pinMatches): void
    {
        $this->mock(TechnicalTestUploadAccess::class, function ($mock) use ($pinMatches): void {
            $mock->shouldReceive('applicantForRequest')->andReturn($this->applicantRecord());
            $mock->shouldReceive('hasTechnicalTestDocument')->andReturn(false);
            $mock->shouldReceive('isEmailVerified')->andReturn(true);
            $mock->shouldReceive('phonePinMatches')->andReturn($pinMatches);
            $mock->shouldReceive('markUploadVerified')->zeroOrMoreTimes();
        });
    }

    private function mockTechnicalTestAccessForMissingEmailVerification(): void
    {
        $this->mock(TechnicalTestUploadAccess::class, function ($mock): void {
            $mock->shouldReceive('applicantForRequest')->andReturn($this->applicantRecord());
            $mock->shouldReceive('hasTechnicalTestDocument')->andReturn(false);
            $mock->shouldReceive('isEmailVerified')->andReturn(false);
        });
    }

    private function mockTechnicalTestAccessForCompletedUpload(): void
    {
        $this->mock(TechnicalTestUploadAccess::class, function ($mock): void {
            $mock->shouldReceive('applicantForRequest')->andReturn($this->applicantRecord());
            $mock->shouldReceive('hasTechnicalTestDocument')->andReturn(true);
        });
    }

    private function applicantRecord(): object
    {
        return (object) [
            'id' => self::APPLICANT_ID,
            'full_name' => 'Technical Test Applicant',
            'email' => 'candidate@example.test',
            'phone' => '081234567890',
            'brand_key' => 'niskala',
        ];
    }

    private function generatedVerifyRoute(): string
    {
        return route('upload-files.verify.applicant.generated', ['applicant' => self::APPLICANT_ID]);
    }

    private function generatedVerifyCheckRoute(): string
    {
        return route('upload-files.verify.applicant.generated.check', ['applicant' => self::APPLICANT_ID]);
    }

    private function generatedUploadRoute(): string
    {
        return route('upload-files.generated.create', ['applicant' => self::APPLICANT_ID]);
    }
}
