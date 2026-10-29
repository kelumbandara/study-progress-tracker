<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TheoryStudyEntry extends Model
{
    protected $guarded = [];

    protected $casts = ["study_date" => "date", "marked_complete" => "boolean"];

    public function topic() { return $this->belongsTo(SyllabusTopic::class, "topic_id"); }
}
