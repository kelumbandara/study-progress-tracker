<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhoneAppUsage extends Model
{
    protected $table = 'phone_app_usage';

    protected $guarded = [];

    protected $casts = ['usage_date' => 'date', 'minutes' => 'integer'];
}
