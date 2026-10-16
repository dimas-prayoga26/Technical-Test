<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;
use ZipArchive;

class SafeTechnicalTestDocument implements ValidationRule
{
    /**
     * File extensions a candidate may upload as a technical test result.
     *
     * @var list<string>
     */
    public const ALLOWED_EXTENSIONS = ['zip', 'rar', 'pdf', 'docx', 'xlsx', 'png', 'jpg', 'jpeg', 'svg'];

    private const SAFE_DOCUMENT_NAME_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9 ._\-()]{0,199}\.(zip|rar|pdf|docx|xlsx|png|jpe?g|svg)\z/i';

    private const UNSAFE_PDF_PATTERN = '/\/(?:JavaScript|JS|OpenAction|AA|Launch|EmbeddedFile|RichMedia|SubmitForm|ImportData)\b|<script\b|<\?php/i';

    private const UNSAFE_SVG_PATTERN = '/<script\b|<foreignobject\b|<iframe\b|<embed\b|<object\b|<!entity\b|<!doctype\b|<\?php|javascript:|\son[a-z]+\s*=/i';

    private const UNSAFE_ARCHIVE_ENTRY_PATTERN = '/\.(?:exe|msi|scr|com|dll|bat|cmd|ps1|vbs|vbe|jse|wsf|hta|lnk|cpl|jar|apk|app|sh)\z/i';

    private const RAR4_SIGNATURE = "Rar!\x1A\x07\x00";

    private const RAR5_SIGNATURE = "Rar!\x1A\x07\x01\x00";

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('Please upload a valid technical test file.');

            return;
        }

        if (! $this->hasSafeDocumentName($value)) {
            $fail('Use a simple file name ending in .zip, .rar, .pdf, .docx, .xlsx, .png, .jpg, or .svg. Letters, numbers, spaces, dots, dashes, underscores, and parentheses are allowed.');

            return;
        }

        if (! $this->hasSafeDocumentContent($value)) {
            $fail('The file content does not match its type, or it contains active content, macros, or executable files.');
        }
    }

    private function hasSafeDocumentName(UploadedFile $document): bool
    {
        $fileName = $document->getClientOriginalName();
        $normalizedFileName = str_replace('\\', '/', $fileName);

        return $fileName === basename($normalizedFileName)
            && ! Str::contains($fileName, '..')
            && preg_match('/[\x00-\x1F\x7F]/', $fileName) !== 1
            && preg_match(self::SAFE_DOCUMENT_NAME_PATTERN, $fileName) === 1;
    }

    private function hasSafeDocumentContent(UploadedFile $document): bool
    {
        $path = $document->getRealPath();

        if ($path === false) {
            return false;
        }

        return match (Str::lower($document->getClientOriginalExtension())) {
            'pdf' => $this->hasSafePdfContent($path),
            'docx' => $this->hasSafeOfficeContent($path, 'word/document.xml'),
            'xlsx' => $this->hasSafeOfficeContent($path, 'xl/workbook.xml'),
            'png' => $this->hasImageContent($path, "\x89PNG\r\n\x1A\n", IMAGETYPE_PNG),
            'jpg', 'jpeg' => $this->hasImageContent($path, "\xFF\xD8\xFF", IMAGETYPE_JPEG),
            'svg' => $this->hasSafeSvgContent($path),
            'zip' => $this->hasSafeZipContent($path),
            'rar' => $this->hasRarSignature($path),
            default => false,
        };
    }

    private function hasSafePdfContent(string $path): bool
    {
        $contents = file_get_contents($path);

        return is_string($contents)
            && str_starts_with($contents, '%PDF-')
            && preg_match(self::UNSAFE_PDF_PATTERN, $contents) !== 1;
    }

    private function hasImageContent(string $path, string $signature, int $imageType): bool
    {
        if (! $this->startsWith($path, $signature)) {
            return false;
        }

        $imageSize = @getimagesize($path);

        return is_array($imageSize) && $imageSize[2] === $imageType;
    }

    private function hasSafeSvgContent(string $path): bool
    {
        $contents = file_get_contents($path);

        return is_string($contents)
            && preg_match('/<svg\b/i', $contents) === 1
            && preg_match(self::UNSAFE_SVG_PATTERN, $contents) !== 1;
    }

    private function hasRarSignature(string $path): bool
    {
        return $this->startsWith($path, self::RAR5_SIGNATURE)
            || $this->startsWith($path, self::RAR4_SIGNATURE);
    }

    private function hasSafeZipContent(string $path): bool
    {
        return $this->withZipArchive($path, function (ZipArchive $zip): bool {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = $zip->getNameIndex($index);

                if (! is_string($entryName) || ! $this->archiveEntryIsSafe($entryName)) {
                    return false;
                }
            }

            return true;
        });
    }

    private function hasSafeOfficeContent(string $path, string $mainPartName): bool
    {
        return $this->withZipArchive($path, function (ZipArchive $zip) use ($mainPartName): bool {
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName($mainPartName) === false) {
                return false;
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = $zip->getNameIndex($index);

                if (! is_string($entryName) || ! $this->officeEntryIsSafe($zip, $entryName)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * @param  Closure(ZipArchive): bool  $inspect
     */
    private function withZipArchive(string $path, Closure $inspect): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return false;
        }

        try {
            return $inspect($zip);
        } finally {
            $zip->close();
        }
    }

    private function archiveEntryIsSafe(string $entryName): bool
    {
        $normalizedEntryName = str_replace('\\', '/', $entryName);

        return ! Str::startsWith($normalizedEntryName, '/')
            && ! Str::contains($normalizedEntryName, '../')
            && preg_match('/^[A-Za-z]:/', $normalizedEntryName) !== 1
            && preg_match(self::UNSAFE_ARCHIVE_ENTRY_PATTERN, $normalizedEntryName) !== 1;
    }

    private function officeEntryIsSafe(ZipArchive $zip, string $entryName): bool
    {
        $normalizedEntryName = Str::lower(str_replace('\\', '/', $entryName));

        if (
            Str::startsWith($normalizedEntryName, '/')
            || Str::contains($normalizedEntryName, '../')
            || preg_match('/(^|\/)(vbaproject\.bin|.*\.(?:exe|js|vbs|scr|bat|cmd|ps1))\z/i', $normalizedEntryName) === 1
            || Str::contains($normalizedEntryName, ['activex', 'embeddings', 'oleobject'])
        ) {
            return false;
        }

        if (! Str::endsWith($normalizedEntryName, ['.xml', '.rels'])) {
            return true;
        }

        $contents = $zip->getFromName($entryName);

        return is_string($contents)
            && preg_match('/targetmode=["\']external["\']|javascript:|<script\b|vbaproject|macroenabled|activex|oleobject/i', $contents) !== 1;
    }

    private function startsWith(string $path, string $signature): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            return fread($handle, strlen($signature)) === $signature;
        } finally {
            fclose($handle);
        }
    }
}
