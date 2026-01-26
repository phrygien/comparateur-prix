<?php

use Livewire\Volt\Component;
use App\Models\Product;

new class extends Component {
    public $productText = "Armani - My Way Sunny Vanilla - Eau de Parfum Vaporisateur 90 ml";
    
    public $vendor = '';
    public $name = '';
    public $variation = '';
    public $type = '';
    
    public $similarProducts = [];
    
    public function mount()
    {
        $this->parseProductInfo();
        $this->searchSimilarProducts();
    }
    
    public function parseProductInfo()
    {
        $parts = explode(' - ', $this->productText);
        
        if (count($parts) >= 3) {
            $this->vendor = trim($parts[0]);
            $this->name = trim($parts[1]);
            
            $lastPart = $parts[2];
            
            if (preg_match('/(\d+\s*ml)/i', $lastPart, $matches)) {
                $this->variation = trim($matches[1]);
                $this->type = trim(str_replace($this->variation, '', $lastPart));
            } else {
                $this->type = trim($lastPart);
            }
        }
    }
    
    public function searchSimilarProducts()
    {
        // Méthode 1: Recherche avec plusieurs conditions
        $this->similarProducts = Product::query()
            ->where(function($query) {
                // Recherche par vendor
                $query->where('vendor', 'like', '%' . $this->vendor . '%');
                
                // Recherche par nom (plusieurs variations possibles)
                $nameParts = explode(' ', $this->name);
                foreach ($nameParts as $part) {
                    if (strlen($part) > 2) { // Éviter les mots trop courts
                        $query->orWhere('name', 'like', '%' . $part . '%');
                    }
                }
                
                // Recherche par type
                if (!empty($this->type)) {
                    $query->orWhere('type', 'like', '%' . $this->type . '%');
                }
                
                // Recherche par variation
                if (!empty($this->variation)) {
                    $query->orWhere('variation', 'like', '%' . $this->variation . '%');
                }
            })
            ->limit(10)
            ->get();
            
        // Méthode 2: Utiliser votre scope FullTextSearch
        // $searchQuery = $this->buildFullTextQuery();
        // $this->similarProducts = Product::fullTextSearch($searchQuery)->limit(10)->get();
    }
    
    private function buildFullTextQuery()
    {
        // Construire une requête FULLTEXT optimisée
        $queryParts = [];
        
        // Ajouter le vendor avec un boost
        if (!empty($this->vendor)) {
            $queryParts[] = '+' . $this->vendor . '*';
        }
        
        // Ajouter les parties importantes du nom
        $nameParts = explode(' ', $this->name);
        foreach ($nameParts as $part) {
            if (strlen($part) > 2) {
                $queryParts[] = '+' . $part . '*';
            }
        }
        
        // Ajouter le type
        if (!empty($this->type)) {
            $typeParts = explode(' ', $this->type);
            foreach ($typeParts as $part) {
                if (strlen($part) > 2) {
                    $queryParts[] = $part . '*';
                }
            }
        }
        
        // Ajouter la variation
        if (!empty($this->variation)) {
            $queryParts[] = $this->variation . '*';
        }
        
        return implode(' ', $queryParts);
    }
    
    // Version alternative avec recherche plus précise
    public function searchWithWeights()
    {
        $searchTerm = $this->name . ' ' . $this->vendor . ' ' . $this->type . ' ' . $this->variation;
        
        return Product::query()
            ->select('*')
            ->selectRaw("
                CASE 
                    WHEN vendor LIKE ? THEN 10
                    WHEN name LIKE ? THEN 8
                    WHEN type LIKE ? THEN 6
                    WHEN variation LIKE ? THEN 4
                    ELSE 0
                END as relevance_score",
                [
                    '%' . $this->vendor . '%',
                    '%' . $this->name . '%',
                    '%' . $this->type . '%',
                    '%' . $this->variation . '%'
                ])
            ->where(function($query) use ($searchTerm) {
                $query->where('vendor', 'like', '%' . $this->vendor . '%')
                      ->orWhere('name', 'like', '%' . $this->name . '%')
                      ->orWhere('name', 'like', '%' . str_replace(' ', '%', $this->name) . '%')
                      ->orWhere('type', 'like', '%' . $this->type . '%')
                      ->orWhere('variation', 'like', '%' . $this->variation . '%');
            })
            ->orderBy('relevance_score', 'desc')
            ->limit(15)
            ->get();
    }
}; ?>

<div>
    <div class="p-6">
        <div class="mb-6 p-4 bg-blue-50 rounded-lg">
            <h3 class="font-bold text-lg mb-2">Produit analysé:</h3>
            <p><strong>Vendor:</strong> {{ $vendor }}</p>
            <p><strong>Name:</strong> {{ $name }}</p>
            <p><strong>Variation:</strong> {{ $variation }}</p>
            <p><strong>Type:</strong> {{ $type }}</p>
        </div>
        
        <h3 class="font-bold text-lg mb-4">Produits similaires trouvés ({{ count($similarProducts) }})</h3>
        
        @if(count($similarProducts) > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach($similarProducts as $product)
                    <div class="border rounded-lg p-4 shadow-sm">
                        <div class="flex justify-between items-start">
                            <div>
                                <h4 class="font-bold text-blue-600">{{ $product->vendor }}</h4>
                                <p class="text-gray-800">{{ $product->name }}</p>
                                <div class="mt-2 text-sm text-gray-600">
                                    <span class="bg-gray-100 px-2 py-1 rounded">{{ $product->type }}</span>
                                    @if($product->variation)
                                        <span class="bg-gray-100 px-2 py-1 rounded ml-2">{{ $product->variation }}</span>
                                    @endif
                                </div>
                            </div>
                            @if($product->website)
                                <span class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded">
                                    {{ $product->website->name ?? 'Site' }}
                                </span>
                            @endif
                        </div>
                        
                        @if($product->scraped_reference)
                            <div class="mt-3 pt-3 border-t text-xs text-gray-500">
                                Référence: {{ $product->scraped_reference->reference_code ?? '' }}
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center p-8 bg-gray-50 rounded-lg">
                <p class="text-gray-500">Aucun produit similaire trouvé.</p>
                <button wire:click="searchSimilarProducts" 
                        class="mt-2 px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">
                    Réessayer la recherche
                </button>
            </div>
        @endif
    </div>
</div>