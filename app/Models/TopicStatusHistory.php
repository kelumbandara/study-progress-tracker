<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TopicStatusHistory extends Model
{
    protected $table = "topic_status_history";

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ["changed_at" => "datetime"];

    public function topic() { return $this->belongsTo(SyllabusTopic::class, "topic_id"); }
}
