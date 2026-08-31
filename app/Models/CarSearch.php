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
        'commons_category',
        'from_year',
        'to_year',
        'color',
        'transmission',
        'transparent_background',
        'images_per_year',
        'status',
        'requested_by',
        'csv_import_id',
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

    public function csvImport(): BelongsTo
    {
        return $this->belongsTo(CsvImport::class);
    }

    public function blockEvents(): HasMany
    {
        return $this->hasMany(WikimediaBlockEvent::class);
    }
}
