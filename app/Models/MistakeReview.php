<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MistakeReview extends Model
{
    protected $guarded = [];

    protected $casts = [
        "reviewed_on" => "date", "theory_reviewed" => "boolean", "solution_understood" => "boolean",
        "reattempted" => "boolean", "mastered_independently" => "boolean",
    ];

    public function question() { return $this->belongsTo(ExamQuestion::class, "question_id"); }
}
