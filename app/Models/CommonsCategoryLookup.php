<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommonsCategoryLookup extends Model
{
    protected $fillable = [
        'make',
        'model',
        'category',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }
}
