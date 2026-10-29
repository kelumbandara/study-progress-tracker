<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentProfile extends Model
{
    protected $guarded = [];

    /** Version one has a single student; the schema keeps student_id for later growth. */
    public static function current(): self
    {
        return static::query()->orderBy('id')->firstOrFail();
    }
}
