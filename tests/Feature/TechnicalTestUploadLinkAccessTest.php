<?php

namespace Tests\Feature;

use App\Support\TechnicalTestUploadAccess;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Mockery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class TechnicalTestUploadLinkAccessTest extends TestCase
{
    private const APPLICANT_ID = '11111111-1111-8111-8111-111111111111';

    public function test_phone_and_email_verification_helpers_match_applicant_data(): void
    {
        $access = new TechnicalTestUploadAccess;
        $applicant = (object) [
            'email' => 'Candidate@Example.Test',
            'phone' => '0812-3456-7890',
        ];

        $this->assertTrue($access->emailMatches($applicant, 'candidate@example.test'));
        $this->assertTrue($access->phonePinMatches($applicant, '7890'));
        $this->assertSame('0812 3456 ****', $access->maskedPhone($applicant));
    }

    public function test_only_technical_test_hosts_resolve_to_a_brand(): void
    {
        $hostBrands = config('technical_test_upload.host_brands');
        $brandDomains = [
            'rnb.co.id' => 'rnb',
            'trah.co.id' => 'trah',
            'karpetmerah.id' => 'kma',
            'rne.co.id' => 'rne',
            'coffeeniskala.com' => 'niskala',
            'tims.co.id' => 'tms',
        ];

        foreach ($brandDomains as $domain => $brandKey) {
            $this->assertSame($brandKey, $hostBrands['technical-test.'.$domain] ?? null);
            $this->assertArrayNotHasKey('assessment-test.'.$domain, $hostBrands);
        }
    }

    public function test_applicant_lookup_uses_configured_connection_for_request_domain(): void
    {
        config([
            'technical_test_upload.connection' => 'technical_test',
            'technical_test_upload.host_brands' => [
                'technical-test.rnb.co.id' => 'rnb',
                'technical-test.trah.co.id' => 'trah',
            ],
        ]);

        $connection = Mockery::mock(ConnectionInterface::class);
        $applicantQuery = Mockery::mock();
        $uploadRequestQuery = Mockery::mock();

        DB::shouldReceive('connection')
            ->twice()
            ->with('technical_test')
            ->andReturn($connection);

        $connection->shouldReceive('table')
            ->once()
            ->with('applicants')
            ->andReturn($applicantQuery);

        $applicantQuery->shouldReceive('select')
            ->once()
            ->with(['id', 'full_name', 'email', 'phone', 'brand_key'])
            ->andReturnSelf();
        $applicantQuery->shouldReceive('where')
            ->once()
            ->with('id', self::APPLICANT_ID)
            ->andReturnSelf();
        $applicantQuery->shouldReceive('whereNull')
            ->once()
            ->with('deleted_at')
            ->andReturnSelf();
        $applicantQuery->shouldReceive('first')
            ->once()
            ->andReturn((object) [
                'id' => self::APPLICANT_ID,
                'full_name' => 'TRAH Applicant',
                'email' => 'candidate@example.test',
                'phone' => '081234567890',
                'brand_key' => 'trah',
            ]);

        $connection->shouldReceive('table')
            ->once()
            ->with('applicant_technical_test_upload_requests')
            ->andReturn($uploadRequestQuery);

        $uploadRequestQuery->shouldReceive('select')
            ->once()
            ->with(['id', 'used_at', 'revoked_at', 'expires_at'])
            ->andReturnSelf();
        $uploadRequestQuery->shouldReceive('where')
            ->once()
            ->with('applicant_id', self::APPLICANT_ID)
            ->andReturnSelf();
        $uploadRequestQuery->shouldReceive('latest')
            ->once()
            ->with('created_at')
            ->andReturnSelf();
        $uploadRequestQuery->shouldReceive('first')
            ->once()
            ->andReturn((object) [
                'id' => 'request-id',
                'used_at' => null,
                'revoked_at' => null,
                'expires_at' => now()->addHour(),
            ]);

        $request = Request::create(
            '/'.self::APPLICANT_ID.'/upload-file',
            server: ['HTTP_HOST' => 'technical-test.trah.co.id'],
        );
        $applicant = (new TechnicalTestUploadAccess)->applicantForRequest(self::APPLICANT_ID, $request);

        $this->assertSame('TRAH Applicant', $applicant->full_name);
    }

    public function test_revoked_applicant_upload_request_is_rejected(): void
    {
        config([
            'technical_test_upload.connection' => 'technical_test',
            'technical_test_upload.host_brands' => [
                'technical-test.trah.co.id' => 'trah',
            ],
        ]);

        $connection = Mockery::mock(ConnectionInterface::class);
        $applicantQuery = Mockery::mock();
        $uploadRequestQuery = Mockery::mock();

        DB::shouldReceive('connection')
            ->twice()
            ->with('technical_test')
            ->andReturn($connection);

        $connection->shouldReceive('table')
            ->once()
            ->with('applicants')
            ->andReturn($applicantQuery);

        $applicantQuery->shouldReceive('select')
            ->once()
            ->with(['id', 'full_name', 'email', 'phone', 'brand_key'])
            ->andReturnSelf();
        $applicantQuery->shouldReceive('where')
            ->once()
            ->with('id', self::APPLICANT_ID)
            ->andReturnSelf();
        $applicantQuery->shouldReceive('whereNull')
            ->once()
            ->with('deleted_at')
            ->andReturnSelf();
        $applicantQuery->shouldReceive('first')
            ->once()
            ->andReturn((object) [
                'id' => self::APPLICANT_ID,
                'full_name' => 'TRAH Applicant',
                'email' => 'candidate@example.test',
                'phone' => '081234567890',
                'brand_key' => 'trah',
            ]);

        $connection->shouldReceive('table')
            ->once()
            ->with('applicant_technical_test_upload_requests')
            ->andReturn($uploadRequestQuery);

        $uploadRequestQuery->shouldReceive('select')
            ->once()
            ->with(['id', 'used_at', 'revoked_at', 'expires_at'])
            ->andReturnSelf();
        $uploadRequestQuery->shouldReceive('where')
            ->once()
            ->with('applicant_id', self::APPLICANT_ID)
            ->andReturnSelf();
        $uploadRequestQuery->shouldReceive('latest')
            ->once()
            ->with('created_at')
            ->andReturnSelf();
        $uploadRequestQuery->shouldReceive('first')
            ->once()
            ->andReturn((object) [
                'id' => 'request-id',
                'used_at' => null,
                'revoked_at' => now(),
                'expires_at' => now()->addHour(),
            ]);

        $request = Request::create(
            '/'.self::APPLICANT_ID.'/upload-file',
            server: ['HTTP_HOST' => 'technical-test.trah.co.id'],
        );

        $this->expectException(NotFoundHttpException::class);

        (new TechnicalTestUploadAccess)->applicantForRequest(self::APPLICANT_ID, $request);
    }

    public function test_generated_applicant_upload_routes_and_brand_guard_are_registered(): void
    {
        $verifyRoute = Route::getRoutes()->getByName('upload-files.verify.applicant.generated');
        $verifyPostRoute = Route::getRoutes()->getByName('upload-files.verify.applicant.generated.check');
        $uploadRoute = Route::getRoutes()->getByName('upload-files.generated.create');
        $uploadPostRoute = Route::getRoutes()->getByName('upload-files.generated.store');
        $technicalTestFileRoute = Route::getRoutes()->getByName('technical-test-files.show');
        $routes = File::get(base_path('routes/web.php'));
        $access = File::get(app_path('Support/TechnicalTestUploadAccess.php'));
        $technicalTestConfig = File::get(config_path('technical_test_upload.php'));
        $databaseConfig = File::get(config_path('database.php'));
        $verificationController = File::get(app_path('Http/Controllers/UploadFileVerificationController.php'));
        $uploadController = File::get(app_path('Http/Controllers/UploadFileController.php'));
        $technicalTestFileController = File::get(app_path('Http/Controllers/TechnicalTestFileController.php'));
        $verifyView = File::get(resource_path('views/upload-files/verify-applicant.blade.php'));
        $uploadView = File::get(resource_path('views/upload-files/create.blade.php'));
        $informationView = File::get(resource_path('views/upload-files/information.blade.php'));
        $notFoundView = File::get(resource_path('views/errors/404.blade.php'));

        $this->assertSame('{applicant}/upload-file/verify-applicant', $verifyRoute?->uri());
        $this->assertSame('{applicant}/upload-file/verify-applicant', $verifyPostRoute?->uri());
        $this->assertSame('{applicant}/upload-file', $uploadRoute?->uri());
        $this->assertSame('{applicant}/upload-file', $uploadPostRoute?->uri());
        $this->assertSame('{applicant}/technical-test-file', $technicalTestFileRoute?->uri());
        $this->assertStringContainsString('Route::fallback(function ()', $routes);
        $this->assertStringContainsString('brandKeyForHost', $access);
        $this->assertStringContainsString("config('technical_test_upload.connection'", $access);
        $this->assertStringContainsString("config('technical_test_upload.host_brands', [])", $access);
        $this->assertStringContainsString("'revoked_at'", $access);
        $this->assertStringContainsString('$uploadRequest->revoked_at === null', $access);
        $this->assertStringContainsString("'connection' => env('TECHNICAL_TEST_UPLOAD_DB_CONNECTION', env('DB_CONNECTION', 'mysql'))", $technicalTestConfig);
        $this->assertStringContainsString("'document_type' => 'technical_test'", $technicalTestConfig);
        $this->assertStringNotContainsString("'technical_test_upload' => [", $databaseConfig);
        $this->assertStringContainsString('storeTechnicalTestDocument', $access);
        $this->assertStringContainsString('technicalTestDocumentForRequest', $access);
        $this->assertStringContainsString('applicant_technical_test_upload_requests', $access);
        $this->assertStringContainsString('applicant_documents', $access);
        $this->assertStringContainsString('TechnicalTestUploadAccess', $verificationController);
        $this->assertStringContainsString('TechnicalTestUploadAccess', $uploadController);
        $this->assertStringContainsString('hasTechnicalTestDocument', $verificationController);
        $this->assertStringContainsString('hasTechnicalTestDocument', $uploadController);
        $this->assertStringContainsString('Storage::disk($disk)->response', $technicalTestFileController);
        $this->assertStringContainsString('$formAction', $verifyView);
        $this->assertStringContainsString('$formAction', $uploadView);
        $this->assertStringContainsString('Upload Technical Test', $uploadView);
        $this->assertStringContainsString('Technical test received', $informationView);
        $this->assertStringContainsString('The page you were looking for is not found!', $notFoundView);
        $this->assertStringContainsString('/gymove/assets/css/style.css', $notFoundView);
    }

    public function test_technical_test_contract_matches_andalan_migration_project(): void
    {
        $andalanProjectPath = base_path('..'.DIRECTORY_SEPARATOR.'andalan-migration');

        if (! File::isDirectory($andalanProjectPath)) {
            $this->markTestSkipped('andalan-migration project is not available beside Hasil-Technical-Test.');
        }

        $routes = File::get(base_path('routes/web.php'));
        $access = File::get(app_path('Support/TechnicalTestUploadAccess.php'));
        $andalanService = File::get($andalanProjectPath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'ApplicantTechnicalTestUploadLinkService.php');
        $andalanUploadRequestModel = File::get($andalanProjectPath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'ApplicantTechnicalTestUploadRequest.php');
        $andalanDocumentModel = File::get($andalanProjectPath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'ApplicantDocument.php');
        $andalanTechnicalTestConfig = File::get($andalanProjectPath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'technical_test_upload.php');
        $andalanAssessmentView = File::get($andalanProjectPath.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'applicant_data'.DIRECTORY_SEPARATOR.'assessment.blade.php');

        $this->assertStringContainsString('/{applicant}/upload-file/verify-applicant', $routes);
        $this->assertStringContainsString('/{applicant}/technical-test-file', $routes);
        $this->assertStringContainsString("config('technical_test_upload.document_type', 'technical_test')", $access);
        $this->assertStringContainsString("config('technical_test_upload.connection'", $access);
        $this->assertStringContainsString('applicant_technical_test_upload_requests', $access);
        $this->assertStringNotContainsString('brand_connections', $access);

        $this->assertStringContainsString('/upload-file/verify-applicant', $andalanService);
        $this->assertStringContainsString('/technical-test-file', $andalanService);
        $this->assertStringContainsString('class ApplicantTechnicalTestUploadRequest', $andalanUploadRequestModel);
        $this->assertStringContainsString("public const TYPE_TECHNICAL_TEST = 'technical_test';", $andalanDocumentModel);
        $this->assertStringContainsString("'trah' => 'https://technical-test.trah.co.id'", $andalanTechnicalTestConfig);
        $this->assertStringContainsString('technicalTestFileUrl', $andalanAssessmentView);
    }
}
