<?php

namespace App\Models;

use App\Models\Concerns\RecordsOperations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use RecordsOperations;

    protected $fillable = [
        'name',
        'slug',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'role_user'
        );
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'permission_role'
        );
    }
}
