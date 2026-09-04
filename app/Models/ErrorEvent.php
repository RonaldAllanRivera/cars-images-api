<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded failure somewhere in the CSV -> search -> download pipeline.
 *
 * Modelled on WikimediaBlockEvent: an event has a single time, the moment it
 * happened, so there is no created/updated pair to keep.
 */
class ErrorEvent extends Model
{
    use HasFactory;

    public const CONTEXT_CSV_UPLOAD = 'csv_upload';

    public const CONTEXT_CSV_ROW = 'csv_row';

    public const CONTEXT_SEARCH_RUN = 'search_run';

    public const CONTEXT_IMAGE_DOWNLOAD = 'image_download';

    public const CONTEXT_WIKIMEDIA_BLOCK = 'wikimedia_block';

    public $timestamps = false;

    protected $fillable = [
        'context',
        'severity',
        'message',
        'exception_class',
        'exception_message',
        'trace_excerpt',
        'details',
        'car_search_id',
        'csv_import_id',
        'car_image_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function contexts(): array
    {
        return [
            self::CONTEXT_CSV_UPLOAD => 'CSV upload',
            self::CONTEXT_CSV_ROW => 'CSV row',
            self::CONTEXT_SEARCH_RUN => 'Search run',
            self::CONTEXT_IMAGE_DOWNLOAD => 'Image download',
            self::CONTEXT_WIKIMEDIA_BLOCK => 'Wikimedia block',
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

    public function carImage(): BelongsTo
    {
        return $this->belongsTo(CarImage::class);
    }
}
