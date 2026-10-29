<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperAttempt extends Model
{
    protected $guarded = [];

    protected $casts = ["attempt_date" => "date", "independent" => "boolean", "timed" => "boolean"];

    public function paper() { return $this->belongsTo(ExamPaper::class, "paper_id"); }

    public function questionAttempts() { return $this->hasMany(QuestionAttempt::class, "paper_attempt_id"); }

    /**
     * Total marks without double counting: when question attempts are linked to this
     * paper attempt the total is their sum; the manual total is used only otherwise.
     */
    public function totals(): array
    {
        $rows = $this->relationLoaded("questionAttempts") ? $this->questionAttempts : $this->questionAttempts()->get();
        if ($rows->isNotEmpty()) {
            return [
                "earned" => (float) $rows->sum("marks_earned"),
                "max" => $this->max_marks !== null ? (float) $this->max_marks : (float) $rows->sum("max_marks"),
                "source" => "questions",
            ];
        }

        return ["earned" => $this->marks_earned !== null ? (float) $this->marks_earned : null, "max" => $this->max_marks !== null ? (float) $this->max_marks : null, "source" => "manual"];
    }
}
