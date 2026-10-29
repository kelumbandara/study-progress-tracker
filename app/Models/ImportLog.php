<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportLog extends Model
{
    protected $table = "imports";

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ["errors" => "array", "created_at" => "datetime"];
}
