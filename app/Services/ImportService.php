<?php

namespace App\Services;

use App\Models\ExamPaper;
use App\Models\ExamQuestion;
use App\Models\ImportLog;
use App\Models\Subject;
use App\Models\SyllabusTopic;
use App\Models\SyllabusUnit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * CSV/Excel imports. Valid rows are saved, bad rows are reported with their row number,
 * and rows that already exist are skipped (duplicate detection).
 */
class ImportService
{
    /** @return array<int,array<string,string>> header-keyed rows (header is row 1) */
    public function read(string $path): array
    {
        $sheet = Excel::toArray(null, $path)[0] ?? [];
        if (count($sheet) < 2) {
            return [];
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($sheet));
        $rows = [];
        foreach ($sheet as $i => $r) {
            $row = [];
            foreach ($header as $c => $h) {
                if ($h !== '') {
                    $row[$h] = trim((string) ($r[$c] ?? ''));
                }
            }
            if (array_filter($row, fn ($v) => $v !== '')) {
                $rows[$i + 2] = $row; // spreadsheet row number
            }
        }

        return $rows;
    }

    public function importFile(string $type, UploadedFile|string $file, bool $sample = false): ImportLog
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $name = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);

        return $this->run($type, $this->read($path), $name, $sample);
    }

    public function run(string $type, array $rows, string $filename, bool $sample = false): ImportLog
    {
        $ok = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $n => $row) {
            try {
                $result = DB::transaction(fn () => match ($type) {
                    'syllabus' => $this->syllabusRow($row, $sample),
                    'papers' => $this->paperRow($row, $sample),
                    'questions' => $this->questionRow($row, $sample),
                });
                $result === 'duplicate' ? $skipped++ : $ok++;
            } catch (\InvalidArgumentException $e) {
                $errors[] = "Row {$n}: " . $e->getMessage();
            }
        }
        if ($skipped) {
            $errors[] = "{$skipped} row(s) already existed and were skipped.";
        }

        return ImportLog::create(['type' => $type, 'filename' => $filename, 'rows_ok' => $ok, 'rows_failed' => count($errors) - ($skipped ? 1 : 0), 'errors' => $errors]);
    }

    private function need(array $row, array $cols): void
    {
        foreach ($cols as $c) {
            if (($row[$c] ?? '') === '') {
                throw new \InvalidArgumentException("missing required column value '{$c}'.");
            }
        }
    }

    private function subject(string $name): Subject
    {
        return Subject::query()->whereRaw('LOWER(name) = ?', [strtolower($name)])->first()
            ?? throw new \InvalidArgumentException("unknown subject '{$name}'.");
    }

    private function syllabusRow(array $r, bool $sample): string
    {
        $this->need($r, ['subject', 'unit', 'topic']);
        $subject = $this->subject($r['subject']);

        $unit = SyllabusUnit::query()->firstOrCreate(
            ['subject_id' => $subject->id, 'name' => $r['unit']],
            ['reference' => $r['unit_reference'] ?? null, 'is_sample' => $sample,
             'sort_order' => (int) SyllabusUnit::query()->where('subject_id', $subject->id)->max('sort_order') + 1]
        );

        $nextOrder = fn ($parent) => (int) SyllabusTopic::query()->where(['unit_id' => $unit->id, 'parent_id' => $parent])->max('sort_order') + 1;
        $extra = fn () => [
            'is_sample' => $sample,
            'estimated_minutes' => ($r['estimated_hours'] ?? '') !== '' ? (int) round(((float) $r['estimated_hours']) * 60) : null,
        ];

        $topic = SyllabusTopic::query()->where(['unit_id' => $unit->id, 'parent_id' => null, 'name' => $r['topic']])->first();
        $created = false;
        if (! $topic) {
            $topic = SyllabusTopic::create(['unit_id' => $unit->id, 'name' => $r['topic'], 'reference' => $r['topic_reference'] ?? null, 'sort_order' => $nextOrder(null)] + $extra());
            $created = true;
        }

        if (($r['subtopic'] ?? '') !== '') {
            $sub = SyllabusTopic::query()->where(['unit_id' => $unit->id, 'parent_id' => $topic->id, 'name' => $r['subtopic']])->first();
            if ($sub) {
                return $created ? 'ok' : 'duplicate';
            }
            SyllabusTopic::create(['unit_id' => $unit->id, 'parent_id' => $topic->id, 'name' => $r['subtopic'], 'sort_order' => $nextOrder($topic->id)] + $extra());

            return 'ok';
        }

        return $created ? 'ok' : 'duplicate';
    }

    private function paperRow(array $r, bool $sample): string
    {
        $this->need($r, ['subject', 'year', 'paper']);
        $subject = $this->subject($r['subject']);
        if (! ctype_digit($r['year']) || $r['year'] < 1990 || $r['year'] > 2100) {
            throw new \InvalidArgumentException("invalid year '{$r['year']}'.");
        }
        foreach (['max_marks', 'duration_minutes'] as $c) {
            if (($r[$c] ?? '') !== '' && ! ctype_digit($r[$c])) {
                throw new \InvalidArgumentException("'{$c}' must be a whole number.");
            }
        }
        $availability = strtolower($r['availability'] ?? '') ?: 'unknown';
        if (! array_key_exists($availability, ExamPaper::AVAILABILITY)) {
            throw new \InvalidArgumentException("availability must be available, unavailable or unknown.");
        }

        $paper = ExamPaper::query()->firstOrCreate(
            ['subject_id' => $subject->id, 'exam_year' => (int) $r['year'], 'paper_name' => $r['paper']],
            [
                'exam_type' => $r['exam_type'] ?? null,
                'max_marks' => ($r['max_marks'] ?? '') !== '' ? (int) $r['max_marks'] : null,
                'duration_minutes' => ($r['duration_minutes'] ?? '') !== '' ? (int) $r['duration_minutes'] : null,
                'paper_url' => ($r['paper_url'] ?? '') ?: null,
                'marking_scheme_url' => ($r['marking_scheme_url'] ?? '') ?: null,
                'availability' => $availability, 'is_sample' => $sample,
            ]
        );

        return $paper->wasRecentlyCreated ? 'ok' : 'duplicate';
    }

    private function questionRow(array $r, bool $sample): string
    {
        $this->need($r, ['subject', 'year', 'paper', 'question_no', 'max_marks']);
        $subject = $this->subject($r['subject']);
        if (! is_numeric($r['max_marks']) || $r['max_marks'] <= 0) {
            throw new \InvalidArgumentException("max_marks must be a positive number.");
        }
        $paper = ExamPaper::query()->where(['subject_id' => $subject->id, 'exam_year' => (int) $r['year'], 'paper_name' => $r['paper']])->first()
            ?? throw new \InvalidArgumentException("paper {$r['year']} {$r['paper']} is not registered; import papers first.");

        $topicIds = [];
        foreach (array_filter(array_map('trim', explode('|', $r['topics'] ?? ''))) as $ref) {
            $t = SyllabusTopic::query()
                ->whereHas('unit', fn ($q) => $q->where('subject_id', $subject->id))
                ->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [strtolower($ref)])->orWhere('reference', $ref))
                ->first() ?? throw new \InvalidArgumentException("topic '{$ref}' was not found in the {$subject->name} syllabus.");
            $topicIds[] = $t->id;
        }

        $q = ExamQuestion::query()->firstOrCreate(
            ['paper_id' => $paper->id, 'question_no' => $r['question_no']],
            ['max_marks' => $r['max_marks'], 'reference' => ($r['reference'] ?? '') ?: null, 'notes' => ($r['notes'] ?? '') ?: null, 'is_sample' => $sample]
        );
        if (! $q->wasRecentlyCreated) {
            return 'duplicate';
        }
        $q->topics()->sync($topicIds);

        return 'ok';
    }
}
