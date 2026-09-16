<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';
    public $timestamps = false;

    protected $fillable = [
        'action',
        'actor_id',
        'actor_type',
        'auditable_id',
        'auditable_type',
        'payload',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor()
    {
        return $this->morphTo('actor');
    }

    public function auditable()
    {
        return $this->morphTo('auditable');
    }
}
