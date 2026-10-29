<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ReportExport implements FromArray, ShouldAutoSize
{
    public function __construct(private array $rows)
    {
    }

    public function array(): array
    {
        // Prefix risky cell starts so spreadsheet apps never treat user text as a formula.
        return array_map(fn ($row) => array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $row), $this->rows);
    }
}
