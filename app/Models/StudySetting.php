<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudySetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'study_start' => 'date',
        'study_end' => 'date',
        'exam_date' => 'date',
    ];
}
