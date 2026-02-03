<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

class Product extends Model
{
    use HasFactory, Searchable;

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

    /**
     * Get the indexable data array for the model.
     * Cette méthode définit les données qui seront envoyées à Typesense
     */
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

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return 'products';
    }

    /**
     * Determine if the model should be searchable.
     * Vous pouvez ajouter des conditions ici
     */
    public function shouldBeSearchable(): bool
    {
        // Indexer seulement si le produit a un nom et un vendor
        return !empty($this->name) && !empty($this->vendor);
    }

    /**
     * Get the value used to index the model.
     * Optionnel : utilisé pour définir une clé unique
     */
    public function getScoutKey(): mixed
    {
        return $this->id;
    }

    /**
     * Get the key name used to index the model.
     */
    public function getScoutKeyName(): string
    {
        return 'id';
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
     * Scopes pour des recherches spécifiques
     */
    public function scopeByVendor($query, string $vendor)
    {
        return $query->where('vendor', $vendor);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByWebsite($query, int $websiteId)
    {
        return $query->where('web_site_id', $websiteId);
    }

    /**
     * Méthodes helper pour la recherche
     */

    /**
     * Recherche stricte sur vendor, name et type
     */
    public static function searchStrict(string $vendor, string $name, ?string $type = null, ?string $variation = null)
    {
        return static::search($name, function ($typesense, $query, $options) use ($vendor, $name, $type, $variation) {
            // Strict sur tous les champs
            $options['num_typos'] = '0,0,0,0';
            $options['drop_tokens_threshold'] = 0;

            $filters = ["vendor:= `{$vendor}`"];

            if (!empty($type)) {
                $filters[] = "type:= `{$type}`";
            }

            $options['filter_by'] = implode(' && ', $filters);

            // Boost pour variation exacte
            if (!empty($variation)) {
                $options['sort_by'] = "_eval([(variation:={$variation}):10]):desc,_text_match:desc,created_at:desc";
            }

            return $options;
        });
    }

    /**
     * Recherche par vendor seulement
     */
    public static function searchByVendor(string $vendor, string $searchTerm)
    {
        return static::search($searchTerm, function ($typesense, $query, $options) use ($vendor) {
            $options['filter_by'] = "vendor:= `{$vendor}`";
            return $options;
        });
    }

    /**
     * Recherche avec fallback progressif
     */
    public static function searchWithFallback(array $parsed)
    {
        // Niveau 1: Ultra strict
        $results = static::searchStrict(
            $parsed['vendor'],
            $parsed['name'],
            $parsed['type'] ?? null,
            $parsed['variation'] ?? null
        )->get();

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
            })->get();
        }

        // Niveau 3: Seulement vendor
        if ($results->isEmpty() && !empty($parsed['vendor'])) {
            $results = static::searchByVendor(
                $parsed['vendor'],
                $parsed['name']
            )->get();
        }

        return $results;
    }

    /**
     * Casts
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
