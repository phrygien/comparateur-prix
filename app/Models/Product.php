<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

class Product extends Model
{
    use Searchable;

    // scraped product table
    protected $table = 'scraped_product';

    public function website(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'web_site_id');
    }

    public function scraped_reference(): BelongsTo
    {
        return $this->belongsTo(Reference::class, 'scrap_reference_id');
    }

    // Dans le modèle Product
    public function scopeFullTextSearch($query, string $searchQuery)
    {
        return $query->whereRaw('MATCH(name, vendor, type, variation)
                                AGAINST(? IN BOOLEAN MODE)', [$searchQuery]);
    }

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray()
    {
        // Construire le champ exact_match
        $exactMatch = [];

        if (!empty($this->vendor)) {
            $exactMatch[] = $this->vendor;
        }
        if (!empty($this->name)) {
            $exactMatch[] = $this->name;
        }
        if (!empty($this->type)) {
            $exactMatch[] = $this->type;
        }
        if (!empty($this->variation)) {
            $exactMatch[] = $this->variation;
        }

        return [
            'id' => (string) $this->id,
            'web_site_id' => (int) $this->web_site_id,
            'vendor' => $this->vendor,
            'image_url' => $this->image_url,
            'name' => $this->name,
            'type' => $this->type,
            'variation' => $this->variation,
            'prix_ht' => $this->prix_ht,
            'currency' => $this->currency,
            'url' => $this->url,
            'scrap_reference_id' => (int) $this->scrap_reference_id,
            'created_at' => $this->created_at?->timestamp ?? 0,
            'updated_at' => $this->updated_at?->timestamp ?? 0,
            // Champ combiné pour la recherche exacte
            'full_name' => $this->vendor . ' - ' . $this->name . ' - ' . $this->type . ' ' . $this->variation,
        ];
    }


    /**
     * Get the Typesense collection schema.
     */
    public function getCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'vendor', 'type' => 'string', 'facet' => true],
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'type', 'type' => 'string', 'facet' => true],
                ['name' => 'variation', 'type' => 'string'],
                ['name' => 'web_site_id', 'type' => 'int32', 'facet' => true],
                ['name' => 'scrap_reference_id', 'type' => 'int32'],
                ['name' => 'price', 'type' => 'int32'],
                ['name' => 'created_at', 'type' => 'int64'],
            ],
            'default_sorting_field' => 'created_at',
        ];
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return 'products';
    }
}
