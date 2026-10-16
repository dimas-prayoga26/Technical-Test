<?php

namespace Tests\Feature;

use App\Support\TechnicalTestUploadAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class UploadFileTest extends TestCase
{
    private const APPLICANT_ID = '11111111-1111-8111-8111-111111111111';

    public function test_upload_file_page_renders(): void
    {
        $this->mockTechnicalTestAccessForUpload(verified: true);

        $response = $this
            ->withSession(['upload_file_verified_applicant_id' => self::APPLICANT_ID])
            ->get($this->generatedUploadRoute());

        $response
            ->assertOk()
            ->assertSee('Upload Technical Test')
            ->assertSee('ZIP, RAR, PDF, DOCX, XLSX, PNG, JPG, and SVG only.')
            ->assertSee('accept=".zip,.rar,.pdf,.docx,.xlsx,.png,.jpg,.jpeg,.svg"', false);
    }

    public function test_upload_file_page_shows_information_when_document_was_uploaded(): void
    {
        $this->mockTechnicalTestAccessForCompletedUpload();

        $response = $this
            ->withSession(['upload_file_verified_applicant_id' => self::APPLICANT_ID])
            ->get($this->generatedUploadRoute());

        $response
            ->assertOk()
            ->assertSee('Technical test received')
            ->assertDontSee('For Your Information')
            ->assertDontSee('Upload Technical Test');
    }

    public function test_upload_file_page_redirects_to_applicant_verification_when_unverified(): void
    {
        $this->mockTechnicalTestAccessForUpload(verified: false);

        $response = $this->get($this->generatedUploadRoute());

        $response->assertRedirect($this->generatedVerifyRoute());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedTechnicalTestFiles(): array
    {
        return [
            'zip archive' => ['fakeZip'],
            'rar archive' => ['fakeRar'],
            'pdf document' => ['fakePdf'],
            'word document' => ['fakeDocx'],
            'excel workbook' => ['fakeXlsx'],
            'png image' => ['fakePng'],
            'jpg image' => ['fakeJpg'],
            'svg image' => ['fakeSvg'],
        ];
    }

    #[DataProvider('acceptedTechnicalTestFiles')]
    public function test_allowed_technical_test_file_is_stored(string $fileFactory): void
    {
        $document = $this->{$fileFactory}();
        $this->mockTechnicalTestAccessForStore(verified: true, uploadedFile: $document->getClientOriginalName());

        $response = $this
            ->withSession(['upload_file_verified_applicant_id' => self::APPLICANT_ID])
            ->post($this->generatedUploadStoreRoute(), [
                'document' => $document,
            ]);

        $response
            ->assertRedirect($this->generatedUploadRoute())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'File uploaded successfully.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedTechnicalTestFiles(): array
    {
        return [
            'plain text file' => ['fakeTextFile'],
            'legacy word document' => ['fakeLegacyDoc'],
            'pdf with active content' => ['fakePdfWithJavaScript'],
            'docx with macro' => ['fakeDocxWithMacro'],
            'xlsx with macro' => ['fakeXlsxWithMacro'],
            'svg with script' => ['fakeSvgWithScript'],
            'svg with event handler' => ['fakeSvgWithEventHandler'],
            'zip with executable' => ['fakeZipWithExecutable'],
            'zip with path traversal' => ['fakeZipWithPathTraversal'],
            'text renamed to png' => ['fakeTextRenamedToPng'],
            'text renamed to rar' => ['fakeTextRenamedToRar'],
            'unsafe file name' => ['fakePdfWithUnsafeName'],
        ];
    }

    #[DataProvider('rejectedTechnicalTestFiles')]
    public function test_unsafe_or_unsupported_file_is_rejected(string $fileFactory): void
    {
        Storage::fake('public');
        $this->mockTechnicalTestAccessForUpload(verified: true);
        $document = $this->{$fileFactory}();

        $response = $this
            ->withSession(['upload_file_verified_applicant_id' => self::APPLICANT_ID])
            ->from($this->generatedUploadRoute())
            ->post($this->generatedUploadStoreRoute(), [
                'document' => $document,
            ]);

        $response
            ->assertRedirect($this->generatedUploadRoute())
            ->assertSessionHasErrors('document');

        Storage::disk('public')->assertMissing($document->hashName('uploaded-technical-tests'));
    }

    public function test_upload_is_rejected_when_unverified(): void
    {
        Storage::fake('public');
        $this->mockTechnicalTestAccessForUpload(verified: false);

        $document = $this->fakeZip();

        $response = $this->post($this->generatedUploadStoreRoute(), [
            'document' => $document,
        ]);

        $response->assertRedirect($this->generatedVerifyRoute());

        Storage::disk('public')->assertMissing($document->hashName('uploaded-technical-tests'));
    }

    private function mockTechnicalTestAccessForUpload(bool $verified): void
    {
        $this->mock(TechnicalTestUploadAccess::class, function ($mock) use ($verified): void {
            $mock->shouldReceive('applicantForRequest')->andReturn($this->applicantRecord());
            $mock->shouldReceive('hasTechnicalTestDocument')->andReturn(false);
            $mock->shouldReceive('isUploadVerified')->andReturn($verified);
        });
    }

    private function mockTechnicalTestAccessForStore(bool $verified, string $uploadedFile): void
    {
        $this->mock(TechnicalTestUploadAccess::class, function ($mock) use ($uploadedFile, $verified): void {
            $mock->shouldReceive('applicantForRequest')->andReturn($this->applicantRecord());
            $mock->shouldReceive('hasTechnicalTestDocument')->andReturn(false);
            $mock->shouldReceive('isUploadVerified')->andReturn($verified);
            $mock->shouldReceive('storeTechnicalTestDocument')->andReturn($uploadedFile);
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
        ];
    }

    private function fakePdf(string $name = 'technical-test.pdf', string $contents = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"): UploadedFile
    {
        return UploadedFile::fake()
            ->createWithContent($name, $contents)
            ->mimeType('application/pdf');
    }

    private function fakePdfWithJavaScript(): UploadedFile
    {
        return $this->fakePdf('technical-test.pdf', "%PDF-1.4\n/OpenAction << /S /JavaScript /JS (app.alert('x')) >>\n%%EOF");
    }

    private function fakePdfWithUnsafeName(): UploadedFile
    {
        return $this->fakePdf('technical-test<script>.pdf');
    }

    private function fakeDocx(): UploadedFile
    {
        return $this->fakeOfficeFile('technical-test.docx', 'word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
    }

    private function fakeDocxWithMacro(): UploadedFile
    {
        return $this->fakeOfficeFile('technical-test.docx', 'word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>', [
            'word/vbaProject.bin' => 'macro-content',
        ]);
    }

    private function fakeXlsx(): UploadedFile
    {
        return $this->fakeOfficeFile('technical-test.xlsx', 'xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets/></workbook>');
    }

    private function fakeXlsxWithMacro(): UploadedFile
    {
        return $this->fakeOfficeFile('technical-test.xlsx', 'xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets/></workbook>', [
            'xl/vbaProject.bin' => 'macro-content',
        ]);
    }

    private function fakePng(): UploadedFile
    {
        return UploadedFile::fake()->image('technical-test.png');
    }

    private function fakeJpg(): UploadedFile
    {
        return UploadedFile::fake()->image('technical-test.jpg');
    }

    private function fakeSvg(string $contents = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="#1e3fb4"/></svg>'): UploadedFile
    {
        return UploadedFile::fake()
            ->createWithContent('technical-test.svg', $contents)
            ->mimeType('image/svg+xml');
    }

    private function fakeSvgWithScript(): UploadedFile
    {
        return $this->fakeSvg('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>');
    }

    private function fakeSvgWithEventHandler(): UploadedFile
    {
        return $this->fakeSvg('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>');
    }

    private function fakeRar(): UploadedFile
    {
        return UploadedFile::fake()
            ->createWithContent('technical-test.rar', "Rar!\x1A\x07\x01\x00".str_repeat("\x00", 32))
            ->mimeType('application/vnd.rar');
    }

    private function fakeTextRenamedToRar(): UploadedFile
    {
        return UploadedFile::fake()
            ->createWithContent('technical-test.rar', 'not really a rar archive')
            ->mimeType('application/vnd.rar');
    }

    private function fakeTextRenamedToPng(): UploadedFile
    {
        return UploadedFile::fake()
            ->createWithContent('technical-test.png', 'not really a png image')
            ->mimeType('image/png');
    }

    private function fakeTextFile(): UploadedFile
    {
        return UploadedFile::fake()
            ->createWithContent('notes.txt', 'plain text')
            ->mimeType('text/plain');
    }

    private function fakeLegacyDoc(): UploadedFile
    {
        return UploadedFile::fake()
            ->createWithContent('technical-test.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 32))
            ->mimeType('application/msword');
    }

    private function fakeZip(): UploadedFile
    {
        return $this->fakeZipWithEntries([
            'project/README.md' => '# Technical test',
            'project/src/app.js' => 'console.log("hello");',
        ]);
    }

    private function fakeZipWithExecutable(): UploadedFile
    {
        return $this->fakeZipWithEntries([
            'project/README.md' => '# Technical test',
            'project/setup.exe' => 'MZ-binary',
        ]);
    }

    private function fakeZipWithPathTraversal(): UploadedFile
    {
        return $this->fakeZipWithEntries([
            '../outside.txt' => 'escaped',
        ]);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function fakeZipWithEntries(array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'technical-test-zip-');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $entryName => $contents) {
            $zip->addFromString($entryName, $contents);
        }

        $zip->close();

        return new UploadedFile($path, 'technical-test.zip', 'application/zip', null, true);
    }

    /**
     * @param  array<string, string>  $extraEntries
     */
    private function fakeOfficeFile(string $name, string $mainPartName, string $mainPartContents, array $extraEntries = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'technical-test-office-');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="'.$mainPartName.'"/></Relationships>');
        $zip->addFromString($mainPartName, '<?xml version="1.0" encoding="UTF-8"?>'.$mainPartContents);

        foreach ($extraEntries as $entryName => $contents) {
            $zip->addFromString($entryName, $contents);
        }

        $zip->close();

        return new UploadedFile($path, $name, 'application/octet-stream', null, true);
    }

    private function generatedVerifyRoute(): string
    {
        return route('upload-files.verify.applicant.generated', ['applicant' => self::APPLICANT_ID]);
    }

    private function generatedUploadRoute(): string
    {
        return route('upload-files.generated.create', ['applicant' => self::APPLICANT_ID]);
    }

    private function generatedUploadStoreRoute(): string
    {
        return route('upload-files.generated.store', ['applicant' => self::APPLICANT_ID]);
    }
}
