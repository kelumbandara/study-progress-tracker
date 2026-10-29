<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyTargetHistory extends Model
{
    protected $table = 'study_targets_history';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['changed_at' => 'datetime'];
}
