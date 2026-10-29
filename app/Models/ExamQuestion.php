<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamQuestion extends Model
{
    protected $guarded = [];

    public function paper() { return $this->belongsTo(ExamPaper::class, "paper_id"); }

    public function topics() { return $this->belongsToMany(SyllabusTopic::class, "question_topic_links", "question_id", "topic_id"); }

    public function attempts() { return $this->hasMany(QuestionAttempt::class, "question_id")->orderBy("attempt_no"); }

    public function reviews() { return $this->hasMany(MistakeReview::class, "question_id")->latest("reviewed_on")->latest("id"); }

    public function getLabelAttribute(): string
    {
        return "Q{$this->question_no} (" . ($this->max_marks + 0) . " marks)";
    }
}
