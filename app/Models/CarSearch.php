<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarSearch extends Model
{
    use HasFactory;

    protected $fillable = [
        'make',
        'model',
        'from_year',
        'to_year',
        'color',
        'transparent_background',
        'images_per_year',
        'status',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'from_year' => 'integer',
            'to_year' => 'integer',
            'transparent_background' => 'boolean',
            'images_per_year' => 'integer',
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(CarImage::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
