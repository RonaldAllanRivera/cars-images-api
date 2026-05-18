<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WikimediaBlockEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'car_search_id',
        'csv_import_id',
        'status_code',
        'retry_after_seconds',
        'response_excerpt',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'retry_after_seconds' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function carSearch(): BelongsTo
    {
        return $this->belongsTo(CarSearch::class);
    }

    public function csvImport(): BelongsTo
    {
        return $this->belongsTo(CsvImport::class);
    }
}
