<?php

namespace App\Console\Commands;

use App\Models\SyllabusTopic;
use App\Services\ImportService;
use Illuminate\Console\Command;

/** One-time (and safe to repeat) setup of a fresh server: the NIE syllabus, the past-paper list and the 2024 questions. */
class LoadLibrary extends Command
{
    protected $signature = 'tracker:load-library {--if-empty : Do nothing when the syllabus already has topics (used for the automatic first-start load)}';

    protected $description = 'Load the NIE syllabus, the 2016-2025 past-paper list and the verified 2024 questions (existing rows are skipped)';

    private const STEPS = [
        'syllabus' => ['nie_syllabus_2017.csv', 'NIE syllabus topics'],
        'papers' => ['past_papers_2016_2025.csv', 'Past papers (Paper I and II, 2016-2025)'],
        'questions' => ['questions_2024.csv', '2024 questions and marks'],
    ];

    public function handle(ImportService $import): int
    {
        if ($this->option('if-empty') && SyllabusTopic::query()->exists()) {
            $this->line('The library is already loaded; nothing to do.');

            return self::SUCCESS;
        }

        foreach (self::STEPS as $type => [$file, $label]) {
            $path = database_path('data/' . $file);
            if (! is_file($path)) {
                $this->error("Missing file: database/data/{$file}");

                return self::FAILURE;
            }
            $log = $import->importFile($type, $path);
            $this->line(sprintf('%-45s added %d, errors %d', $label, $log->rows_ok, $log->rows_failed));
            foreach (array_slice((array) $log->errors, 0, 5) as $e) {
                $this->warn('  ' . $e);
            }
        }
        $this->info('Done. Running this again adds nothing that already exists.');

        return self::SUCCESS;
    }
}
