<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamPaper extends Model
{
    protected $guarded = [];

    public const AVAILABILITY = ["unknown" => "Unknown", "available" => "Available", "unavailable" => "Not available"];

    public function subject() { return $this->belongsTo(Subject::class); }

    public function questions() { return $this->hasMany(ExamQuestion::class, "paper_id")->orderByRaw("CAST(question_no AS UNSIGNED)")->orderBy("question_no"); }

    public function attempts() { return $this->hasMany(PaperAttempt::class, "paper_id"); }

    public function getLabelAttribute(): string
    {
        return "{$this->exam_year} {$this->paper_name}";
    }
}
