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
}