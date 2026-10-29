<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $guarded = [];

    public const PRIORITIES = [
        'high' => 'High',
        'medium' => 'Medium',
        'maintain' => 'Maintain / Improve',
    ];
}
