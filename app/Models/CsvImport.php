<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CsvImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'original_filename',
        'total_rows',
        'unique_combos',
        'duplicates_skipped',
        'imported_by',
    ];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'unique_combos' => 'integer',
            'duplicates_skipped' => 'integer',
        ];
    }

    public function searches(): HasMany
    {
        return $this->hasMany(CarSearch::class, 'csv_import_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function blockEvents(): HasMany
    {
        return $this->hasMany(WikimediaBlockEvent::class);
    }
}
