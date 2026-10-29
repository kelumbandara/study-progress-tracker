<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyllabusUnit extends Model
{
    protected $guarded = [];

    public function subject() { return $this->belongsTo(Subject::class); }

    public function topics() { return $this->hasMany(SyllabusTopic::class, "unit_id")->orderBy("sort_order")->orderBy("id"); }
}
