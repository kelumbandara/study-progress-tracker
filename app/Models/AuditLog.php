<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];

    public static function record(string $entity, ?int $id, string $action, ?array $old = null, ?array $new = null): self
    {
        return static::create([
            'student_id' => StudentProfile::query()->value('id'),
            'user_id' => Auth::id(),
            'entity_type' => $entity,
            'entity_id' => $id,
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }
}
