<?php

namespace App\Models;

use App\Enums\RoleName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['name'];

    protected function casts(): array
    {
        return [
            'name' => RoleName::class,
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
