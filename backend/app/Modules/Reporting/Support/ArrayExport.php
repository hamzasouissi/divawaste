<?php

namespace App\Modules\Reporting\Support;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One worksheet: bold heading row, auto-sized columns, numbers kept as numbers.
 */
final class ArrayExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function __construct(private readonly string $title, private readonly array $headings, private readonly array $rows) {}

    /**
     * @return list<list<string|int|float|null>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        // Excel sheet names: 31 characters, no []:*?/\
        return mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $this->title), 0, 31);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
