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
            'exact_match' => !empty($exactMatch) ? implode(' - ', $exactMatch) : null,
        ];
    }

    // Ajouter dans votre modèle Product
    public static function searchByComponents($vendor, $name, $type, $variation = null)
    {
        $search = self::search($name, function($engine, $query, $options) use ($vendor, $type, $variation) {
            // Filtre strict sur vendor et type
            $filter = "vendor:= `{$vendor}` && type:= `{$type}`";

            // Si variation est fournie, l'ajouter à la recherche
            if ($variation) {
                $options['query_by'] = 'name,variation';
                $options['num_typos'] = '0,2'; // Strict sur name, flexible sur variation
            } else {
                $options['query_by'] = 'name';
                $options['num_typos'] = '0'; // Strict sur name
            }

            $options['filter_by'] = $filter;
            $options['prefix'] = 'false';

            return $options;
        });

        return $search->get();
    }

// Et aussi une méthode pour la recherche libre
    public static function searchFreeText($query, $filters = [])
    {
        return self::search($query, function($engine, $q, $options) use ($filters) {
            // Appliquer les filtres si fournis
            if (!empty($filters)) {
                $filterParts = [];
                foreach ($filters as $field => $value) {
                    $filterParts[] = "{$field}:= `{$value}`";
                }
                $options['filter_by'] = implode(' && ', $filterParts);
            }

            // Recherche sur exact_match principalement
            $options['query_by'] = 'exact_match,name,vendor';
            $options['num_typos'] = '2,1,0';

            return $options;
        })->get();
    }
}
