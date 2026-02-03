<?php

namespace App\Scout;

use Laravel\Scout\Builder;
use Typesense\LaravelTypesense\Engines\TypesenseEngine;

class ExactMatchSearchRule
{
    public function __invoke(Builder $builder, $model, array $options = [])
    {
        // Si la recherche ressemble à "X - Y - Z - W", prioriser exact_match
        if (strpos($builder->query, ' - ') !== false) {
            $builder->engine()->addSearchParameters([
                'query_by' => 'exact_match',
                'num_typos' => 0,
                'prefix' => false,
            ]);
        }
        
        return $builder;
    }
}