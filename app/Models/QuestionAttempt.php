<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionAttempt extends Model
{
    protected $guarded = [];

    protected $casts = ["attempt_date" => "date", "independent" => "boolean", "checked_with_scheme" => "boolean"];

    public const RESULTS = ["correct" => "Correct", "partial" => "Partially Correct", "wrong" => "Wrong", "not_completed" => "Not Completed"];

    public const MISTAKES = [
        "conceptual" => "Conceptual misunderstanding", "formula_method" => "Formula or method error",
        "calculation" => "Calculation error", "interpretation" => "Question interpretation error",
        "time_management" => "Time-management problem", "incomplete" => "Incomplete answer", "other" => "Other",
    ];

    public function question() { return $this->belongsTo(ExamQuestion::class, "question_id"); }

    public function getPercentAttribute(): float
    {
        return $this->max_marks > 0 ? round($this->marks_earned / $this->max_marks * 100, 1) : 0.0;
    }
}
