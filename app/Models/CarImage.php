<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarImage extends Model
{
    use HasFactory;

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

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
        'make_confirmed',
        'year_confirmed',
        'review_status',
        'reviewed_by',
        'reviewed_at',
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
            'make_confirmed' => 'boolean',
            'year_confirmed' => 'boolean',
            'reviewed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function search(): BelongsTo
    {
        return $this->belongsTo(CarSearch::class, 'car_search_id');
    }

    /**
     * @return list<string>
     */
    public static function reviewStatuses(): array
    {
        return [self::REVIEW_PENDING, self::REVIEW_APPROVED, self::REVIEW_REJECTED];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
