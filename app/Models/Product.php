<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;
use Illuminate\Support\Collection as SupportCollection;

class Product extends Model
{
    use HasFactory, Searchable;

    protected $table = 'scraped_product';

    protected $fillable = [
        'web_site_id',
        'vendor',
        'image_url',
        'name',
        'type',
        'variation',
        'prix_ht',
        'currency',
        'url',
        'scrap_reference_id',
    ];

    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'web_site_id' => (int) $this->web_site_id,
            'vendor' => $this->vendor ?? '',
            'image_url' => $this->image_url ?? '',
            'name' => $this->name ?? '',
            'type' => $this->type ?? '',
            'variation' => $this->variation ?? '',
            'prix_ht' => $this->prix_ht ?? '',
            'currency' => $this->currency ?? '',
            'url' => $this->url ?? '',
            'scrap_reference_id' => (int) $this->scrap_reference_id,
            'created_at' => $this->created_at?->timestamp ?? 0,
            'updated_at' => $this->updated_at?->timestamp ?? 0,
        ];
    }

    public function searchableAs(): string
    {
        return 'products';
    }

    public function shouldBeSearchable(): bool
    {
        return !empty($this->name) && !empty($this->vendor);
    }

    /**
     * Relationships
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'web_site_id');
    }

    public function scrapReference(): BelongsTo
    {
        return $this->belongsTo(ScrapReference::class, 'scrap_reference_id');
    }

    /**
     * Recherche stricte sur vendor, name et type
     */
    public static function searchStrict(string $vendor, string $name, ?string $type = null, ?string $variation = null): SupportCollection
    {
        return static::search($name, function ($typesense, $query, $options) use ($vendor, $type, $variation) {
            $options['num_typos'] = '0,0,0,0';
            $options['drop_tokens_threshold'] = 0;

            $filters = ["vendor:= `{$vendor}`"];

            if (!empty($type)) {
                $filters[] = "type:= `{$type}`";
            }

            $options['filter_by'] = implode(' && ', $filters);

            if (!empty($variation)) {
                $options['sort_by'] = "_eval([(variation:={$variation}):10]):desc,_text_match:desc,created_at:desc";
            }

            return $options;
        })
            ->query(fn($query) => $query->with('website'))
            ->get(); // ✅ Retourne une Collection
    }

    /**
     * Recherche par vendor seulement
     */
    public static function searchByVendor(string $vendor, string $searchTerm): SupportCollection
    {
        return static::search($searchTerm, function ($typesense, $query, $options) use ($vendor) {
            $options['filter_by'] = "vendor:= `{$vendor}`";
            return $options;
        })
            ->query(fn($query) => $query->with('website'))
            ->get(); // ✅ Retourne une Collection
    }

    /**
     * Recherche avec fallback progressif
     */
    public static function searchWithFallback(array $parsed): SupportCollection
    {
        // Niveau 1: Ultra strict
        $results = static::searchStrict(
            $parsed['vendor'],
            $parsed['name'],
            $parsed['type'] ?? null,
            $parsed['variation'] ?? null
        );

        // Niveau 2: Assouplir le name (1 typo)
        if ($results->isEmpty() && !empty($parsed['vendor']) && !empty($parsed['name'])) {
            $results = static::search($parsed['name'], function ($typesense, $query, $options) use ($parsed) {
                $options['num_typos'] = '1,0,0,0';

                $filters = ["vendor:= `{$parsed['vendor']}`"];

                if (!empty($parsed['type'])) {
                    $filters[] = "type:= `{$parsed['type']}`";
                }

                $options['filter_by'] = implode(' && ', $filters);

                return $options;
            })
                ->query(fn($query) => $query->with('website'))
                ->get();
        }

        // Niveau 3: Seulement vendor
        if ($results->isEmpty() && !empty($parsed['vendor'])) {
            $results = static::searchByVendor($parsed['vendor'], $parsed['name']);
        }

        return $results;
    }

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
