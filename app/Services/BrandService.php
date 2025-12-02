<?php

namespace App\Services;

class BrandService
{
    private array $abbreviations;
    private array $reverseMapping;

    public function __construct()
    {
        $this->abbreviations = config('brand_abbreviations', []);
        $this->reverseMapping = array_flip($this->abbreviations);
    }

    public function normalize(string $vendor): string
    {
        $vendorUpper = strtoupper(trim($vendor));
        
        // Si c'est une abréviation connue
        if (array_key_exists($vendorUpper, $this->abbreviations)) {
            return $this->abbreviations[$vendorUpper];
        }
        
        // Si c'est un nom complet qui a une abréviation
        if (array_key_exists($vendor, $this->reverseMapping)) {
            return $vendor; // Déjà normalisé
        }
        
        // Chercher des correspondances partielles
        foreach ($this->abbreviations as $abbreviation => $fullName) {
            if (str_contains($vendorUpper, $abbreviation) || 
                str_contains(strtoupper($fullName), $vendorUpper)) {
                return $fullName;
            }
        }
        
        return trim($vendor);
    }

    public function getAbbreviation(string $fullName): ?string
    {
        return $this->reverseMapping[$fullName] ?? null;
    }

    public function getAllVariations(string $brand): array
    {
        $variations = [$brand];
        
        $normalized = $this->normalize($brand);
        if ($normalized !== $brand) {
            $variations[] = $normalized;
        }
        
        $abbreviation = $this->getAbbreviation($normalized);
        if ($abbreviation) {
            $variations[] = $abbreviation;
        }
        
        return array_unique($variations);
    }

    public function getSearchPattern(string $brand): string
    {
        $variations = $this->getAllVariations($brand);
        return implode('|', array_map(function($variation) {
            return preg_quote($variation, '/');
        }, $variations));
    }
}