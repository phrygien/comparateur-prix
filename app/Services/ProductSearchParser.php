<?php

namespace App\Services;

class ProductSearchParser
{
    public function parse(string $searchText): array
    {
        // Exemple: "Hermès - Un Jardin Sous la Mer - Eau de Toilette Vaporisateur 100ml"
        $parts = explode(' - ', $searchText);
        
        $vendor = null;
        $name = null;
        $type = null;
        
        if (count($parts) >= 1) {
            $vendor = trim($parts[0]);
        }
        
        if (count($parts) >= 2) {
            $name = trim($parts[1]);
        }
        
        if (count($parts) >= 3) {
            // Extraire le type (tout sauf la contenance)
            $typeRaw = trim($parts[2]);
            // Retirer les contenances comme "100ml", "50ml", etc.
            $type = preg_replace('/\s*\d+\s*(ml|g|oz|L)\s*$/i', '', $typeRaw);
            $type = trim($type);
        }
        
        return [
            'vendor' => $vendor,
            'name' => $name,
            'type' => $type,
        ];
    }
    
    /**
     * Prépare le nom pour une recherche stricte
     * Convertit "Un Jardin sous la mer" en tableau de mots obligatoires
     */
    public function prepareStrictNameSearch(string $name): string
    {
        // Diviser en mots et retirer les mots vides courts
        $words = preg_split('/\s+/', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
        
        // Mots à ignorer (articles, prépositions courtes)
        $stopWords = ['le', 'la', 'les', 'un', 'une', 'des', 'de', 'du', 'a', 'au'];
        
        // Filtrer les stop words sauf si c'est crucial pour le sens
        $importantWords = array_filter($words, function($word) use ($stopWords) {
            return strlen($word) > 2 || !in_array($word, $stopWords);
        });
        
        // Retourner tous les mots importants
        return implode(' ', $importantWords);
    }
}