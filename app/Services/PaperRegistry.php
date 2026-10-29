<?php

namespace App\Services;

use App\Models\ExamPaper;
use App\Models\ExamQuestion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;

/**
 * Registers papers and questions without ever creating duplicates, even when two identical
 * requests arrive at the same instant (double-click, two tabs, the phone retrying).
 * Shared by the web pages and the phone API. Returns [model, created].
 */
class PaperRegistry
{
    /** @return array{0: ExamPaper, 1: bool} */
    public function paper(array $d): array
    {
        $key = Arr::only($d, ['subject_id', 'exam_year', 'paper_name']);

        if ($existing = ExamPaper::query()->where($key)->first()) {
            return [$existing, false];
        }
        try {
            return [ExamPaper::create($d + ['availability' => 'unknown']), true];
        } catch (UniqueConstraintViolationException) {
            return [ExamPaper::query()->where($key)->firstOrFail(), false]; // lost a race with an identical request
        }
    }

    /** @return array{0: ExamQuestion, 1: bool} */
    public function question(ExamPaper $paper, array $d): array
    {
        $key = ['paper_id' => $paper->id, 'question_no' => $d['question_no']];

        if ($existing = ExamQuestion::query()->where($key)->first()) {
            return [$existing, false];
        }
        try {
            $q = ExamQuestion::create($key + Arr::only($d, ['max_marks', 'reference', 'notes']));
        } catch (UniqueConstraintViolationException) {
            return [ExamQuestion::query()->where($key)->firstOrFail(), false];
        }
        $q->topics()->sync($d['topic_ids'] ?? []);

        return [$q, true];
    }
}
