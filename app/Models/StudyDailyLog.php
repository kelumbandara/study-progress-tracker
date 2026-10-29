<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyDailyLog extends Model
{
    protected $guarded = [];

    protected $casts = ['log_date' => 'date', 'is_rest_day' => 'boolean', 'is_sample' => 'boolean'];
}
