<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'car_search_id',
        'make',
        'model',
        'year',
        'color',
        'transparent_background',
        'provider',
        'provider_image_id',
        'title',
        'description',
        'source_url',
        'thumbnail_url',
        'width',
        'height',
        'license',
        'attribution',
        'download_status',
        'download_path',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'transparent_background' => 'boolean',
            'width' => 'integer',
            'height' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function search(): BelongsTo
    {
        return $this->belongsTo(CarSearch::class, 'car_search_id');
    }
}
