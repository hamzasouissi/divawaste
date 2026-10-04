<?php

namespace App\Modules\Reporting\DTOs;

final readonly class CreateGeneratedReportData
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $reportType,
        public string $format,
        public array $parameters = [],
        public ?int $storedFileId = null,
        public ?string $status = 'queued',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            reportType: (string) ($data['report_type'] ?? $data['type'] ?? 'summary'),
            format: (string) ($data['format'] ?? 'pdf'),
            parameters: is_array($data['parameters'] ?? null) ? $data['parameters'] : [],
            storedFileId: isset($data['stored_file_id']) ? (int) $data['stored_file_id'] : null,
            status: isset($data['status']) ? (string) $data['status'] : 'queued',
        );
    }
}
