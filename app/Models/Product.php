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
        ];
    }

}
