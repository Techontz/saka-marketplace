<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The surveyed outline of a land parcel.
 *
 * Everything except `rings`, `survey_reference` and `notes` is derived from the
 * geometry by LandBoundaryService and is deliberately NOT fillable: the area a
 * buyer sees has to come from the coordinates a seller drew, not from a number
 * a seller typed.
 *
 * @property array<int, array<int, array{0: float, 1: float}>> $rings
 */
class ListingBoundary extends Model
{
    protected $fillable = [
        'listing_id', 'rings', 'survey_reference', 'notes',
    ];

    protected $guarded = [
        'id',
        'area_sqm', 'perimeter_m',
        'centroid_latitude', 'centroid_longitude',
        'min_latitude', 'max_latitude', 'min_longitude', 'max_longitude',
    ];

    protected function casts(): array
    {
        return [
            'rings' => 'array',
            'area_sqm' => 'float',
            'perimeter_m' => 'float',
            'centroid_latitude' => 'float',
            'centroid_longitude' => 'float',
            'min_latitude' => 'float',
            'max_latitude' => 'float',
            'min_longitude' => 'float',
            'max_longitude' => 'float',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * The outer ring — the parcel edge itself, without any holes.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    public function outerRing(): array
    {
        return $this->rings[0] ?? [];
    }

    /**
     * The geometry as a GeoJSON Polygon.
     *
     * Exposed so the shape can be handed to a GIS tool, a Google Earth import
     * or a surveyor without anyone having to know how this table stores it.
     *
     * @return array<string, mixed>
     */
    public function toGeoJson(): array
    {
        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => $this->rings,
            ],
            'properties' => array_filter([
                'area_sqm' => $this->area_sqm,
                'perimeter_m' => $this->perimeter_m,
                'survey_reference' => $this->survey_reference,
            ], static fn ($value) => $value !== null),
        ];
    }
}
