<?php

namespace App\Support;

/** The four tracked time categories and their daily-log columns. */
class Category
{
    public const LABELS = [
        'school' => 'School',
        'tuition' => 'Tuition',
        'theory' => 'Study Theory',
        'papers' => 'Write Exam Papers',
    ];

    /** Categories that count as independent study (school and tuition do not). */
    public const INDEPENDENT = ['theory', 'papers'];

    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public static function column(string $key): string
    {
        return $key . '_minutes';
    }
}
