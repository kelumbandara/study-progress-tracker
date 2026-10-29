<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyllabusTopic extends Model
{
    protected $guarded = [];

    protected $casts = ["theory_completed_on" => "date"];

    public const STATUSES = [
        "not_started" => "Not Started",
        "in_progress" => "In Progress",
        "theory_completed" => "Theory Completed",
        "questions_practised" => "Questions Practised",
        "revision_required" => "Revision Required",
        "mastered" => "Mastered",
    ];

    public function unit() { return $this->belongsTo(SyllabusUnit::class, "unit_id"); }

    public function parent() { return $this->belongsTo(self::class, "parent_id"); }

    public function children() { return $this->hasMany(self::class, "parent_id")->orderBy("sort_order")->orderBy("id"); }

    public function questions() { return $this->belongsToMany(ExamQuestion::class, "question_topic_links", "topic_id", "question_id"); }

    public function history() { return $this->hasMany(TopicStatusHistory::class, "topic_id")->latest("changed_at")->latest("id"); }

    public function getFullNameAttribute(): string
    {
        return ($this->parent ? $this->parent->name . " › " : "") . $this->name;
    }
}
