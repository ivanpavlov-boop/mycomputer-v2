<?php

namespace App\Services\Csv;

use App\Models\CsvMappingPreset;
use App\Support\Catalog\ProductCsvSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CsvMappingService
{
    public const IMPORT_DIR = 'imports';

    public const EXPORT_DIR = 'exports';

    public function storeUploadedFile(UploadedFile $file): array
    {
        abort_unless(in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true), 422, 'Invalid CSV file extension.');
        abort_if($file->getSize() > 10 * 1024 * 1024, 422, 'CSV file is larger than 10MB.');

        File::ensureDirectoryExists(storage_path('app/'.self::IMPORT_DIR));

        $filename = now()->format('YmdHis').'-'.Str::random(12).'.csv';
        $file->move(storage_path('app/'.self::IMPORT_DIR), $filename);

        return [
            'file_path' => self::IMPORT_DIR.'/'.$filename,
            'original_filename' => $file->getClientOriginalName(),
        ];
    }

    public function absoluteImportPath(string $filePath): string
    {
        return $this->safePath($filePath, self::IMPORT_DIR);
    }

    public function absoluteExportPath(string $filePath): string
    {
        return $this->safePath($filePath, self::EXPORT_DIR);
    }

    public function detectDelimiter(string $absolutePath): string
    {
        $sample = (string) file_get_contents($absolutePath, false, null, 0, 8192);
        $commaCount = substr_count($sample, ',');
        $semicolonCount = substr_count($sample, ';');

        return $semicolonCount > $commaCount ? ';' : ',';
    }

    public function readRows(string $filePath, ?int $limit = null): array
    {
        $absolutePath = $this->absoluteImportPath($filePath);
        $delimiter = $this->detectDelimiter($absolutePath);
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV file.');
        }

        $headers = null;
        $rows = [];
        $rowNumber = 0;

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;
            $data = $this->normalizeEncoding($data);

            if ($rowNumber === 1) {
                $headers = $this->normalizeHeaders($data);

                continue;
            }

            if ($headers === null || $this->isEmptyRow($data)) {
                continue;
            }

            $rows[] = [
                'row_number' => $rowNumber,
                'data' => array_combine($headers, array_pad($data, count($headers), null)),
            ];

            if ($limit !== null && count($rows) >= $limit) {
                break;
            }
        }

        fclose($handle);

        return $rows;
    }

    public function defaultMapping(array $headers, string $type): array
    {
        $mapping = [];

        foreach ($headers as $header) {
            $normalized = $this->normalizeKey($header);
            foreach (ProductCsvSchema::columnsFor($type) as $column) {
                $aliases = ProductCsvSchema::ALIASES[$column] ?? [$column];
                if (in_array($normalized, array_map($this->normalizeKey(...), $aliases), true)) {
                    $mapping[$header] = $column;
                    break;
                }
            }
        }

        return $mapping;
    }

    public function mapRow(array $rawRow, ?array $mapping, string $type): array
    {
        $mapping = filled($mapping) ? $mapping : $this->defaultMapping(array_keys($rawRow), $type);
        $mapped = [];

        foreach ($rawRow as $source => $value) {
            $target = $mapping[$source] ?? null;
            if ($target && in_array($target, ProductCsvSchema::columnsFor($type), true)) {
                $mapped[$target] = is_string($value) ? trim($value) : $value;
            }
        }

        return $mapped;
    }

    public function savePreset(string $name, string $type, array $mapping, ?int $createdBy = null): CsvMappingPreset
    {
        return CsvMappingPreset::query()->updateOrCreate(
            ['name' => $name, 'type' => $type],
            ['mapping' => $mapping, 'created_by' => $createdBy],
        );
    }

    private function safePath(string $filePath, string $directory): string
    {
        abort_if(str_contains($filePath, '..') || str_starts_with($filePath, '/'), 403, 'Invalid file path.');
        abort_unless(str_starts_with($filePath, $directory.'/'), 403, 'Invalid file directory.');

        $base = storage_path('app/'.$directory);
        File::ensureDirectoryExists($base);

        $path = storage_path('app/'.$filePath);
        $normalized = str_replace('\\', '/', $path);
        $normalizedBase = str_replace('\\', '/', $base);

        abort_unless(str_starts_with($normalized, $normalizedBase), 403, 'Invalid file path.');

        return $path;
    }

    private function normalizeHeaders(array $headers): array
    {
        return array_map(fn ($header): string => $this->normalizeKey((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $header)), $headers);
    }

    private function normalizeEncoding(array $row): array
    {
        return array_map(function ($value) {
            if (! is_string($value)) {
                return $value;
            }

            return mb_convert_encoding($value, 'UTF-8', 'UTF-8, Windows-1251, ISO-8859-1');
        }, $row);
    }

    private function normalizeKey(string $value): string
    {
        return Str::of($value)->lower()->replace([' ', '-'], '_')->squish()->toString();
    }

    private function isEmptyRow(array $row): bool
    {
        return collect($row)->filter(fn ($value): bool => filled($value))->isEmpty();
    }
}
