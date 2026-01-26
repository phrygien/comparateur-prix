<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public $productText = "Yves Saint Laurent - Loveshine Gloss soin effet repulpant - 10 Stardust Love";
    
    public $vendor = '';
    public $name = '';
    public $variation = '';
    public $type = '';
    
    public $searchResults = [];
    public $searchMessage = '';
    
    public function mount()
    {
        $this->parseProductInfo();
    }
    
    public function parseProductInfo()
    {
        // Diviser le texte en parties
        $parts = explode(' - ', $this->productText);
        
        if (count($parts) >= 3) {
            $this->vendor = $parts[0]; // Yves Saint Laurent
            $this->name = $parts[1]; // Loveshine Gloss soin effet repulpant
            
            // Analyser la dernière partie pour type et variation
            $lastPart = $parts[2];
            
            // Chercher la taille/variation (comme 10 Stardust Love)
            // Supposons que le début numérique est la variation
            if (preg_match('/^(\d+[\s\w]+)/i', $lastPart, $matches)) {
                $this->variation = trim($matches[1]);
                // Pour le type, nous pourrions prendre le reste ou un autre format
                // Dans ce cas, le nom contient déjà le type "Gloss"
                $this->type = 'Gloss'; // Vous pouvez ajuster cette logique
            } else {
                $this->variation = $lastPart;
                $this->type = $this->extractTypeFromName();
            }
        }
        
        // Rechercher automatiquement après l'analyse
        $this->searchProduct();
    }
    
    private function extractTypeFromName()
    {
        // Liste de types communs de produits cosmétiques
        $types = [
            'gloss', 'lipstick', 'mascara', 'foundation', 'concealer',
            'eyeshadow', 'blush', 'bronzer', 'highlighter', 'primer',
            'serum', 'moisturizer', 'cleanser', 'toner', 'perfume',
            'eau de parfum', 'eau de toilette', 'eau de cologne',
            'nail polish', 'eyeliner', 'brow pencil'
        ];
        
        $nameLower = strtolower($this->name);
        
        foreach ($types as $type) {
            if (strpos($nameLower, $type) !== false) {
                return ucfirst($type);
            }
        }
        
        return null;
    }
    
    public function searchProduct()
    {
        $this->searchResults = [];
        $this->searchMessage = '';
        
        if (empty($this->vendor) && empty($this->name)) {
            $this->searchMessage = 'Veuillez fournir au moins un vendeur ou un nom pour rechercher.';
            return;
        }
        
        $query = Product::query();
        
        // Recherche par vendor (marque)
        if (!empty($this->vendor)) {
            // Recherche exacte ou partielle selon votre besoin
            $query->where('vendor', 'like', '%' . $this->vendor . '%');
        }
        
        // Recherche par nom
        if (!empty($this->name)) {
            // Vous pouvez ajuster la logique de recherche ici
            // Option 1: Recherche par mots clés
            $nameKeywords = explode(' ', $this->name);
            foreach ($nameKeywords as $keyword) {
                if (strlen($keyword) > 2) { // Éviter les mots trop courts
                    $query->where('name', 'like', '%' . $keyword . '%');
                }
            }
            
            // Option 2: Recherche exacte
            // $query->where('name', 'like', '%' . $this->name . '%');
        }
        
        // Recherche par type si disponible
        if (!empty($this->type)) {
            $query->where('type', 'like', '%' . $this->type . '%');
        }
        
        // Recherche par variation si disponible
        if (!empty($this->variation)) {
            $query->where('variation', 'like', '%' . $this->variation . '%');
        }
        
        // Trier par pertinence (plus récent d'abord)
        $query->orderBy('created_at', 'desc');
        
        // Limiter les résultats
        $this->searchResults = $query->limit(10)->get();
        
        if ($this->searchResults->isEmpty()) {
            $this->searchMessage = 'Aucun produit trouvé avec ces critères.';
        } else {
            $this->searchMessage = count($this->searchResults) . ' produit(s) trouvé(s).';
        }
    }
    
    public function updateProductText()
    {
        $this->parseProductInfo();
    }
}; ?>

<div>
    <div class="p-4">
        <h3 class="font-bold mb-4 text-lg">Recherche de produit</h3>
        
        <!-- Champ pour modifier le texte du produit -->
        <div class="mb-6">
            <label class="block text-sm font-medium mb-1">Texte du produit:</label>
            <input 
                type="text" 
                wire:model.live="productText"
                wire:change="updateProductText"
                class="w-full p-2 border rounded"
                placeholder="Entrez le texte du produit (format: Vendor - Name - Variation/Type)"
            />
            <button 
                wire:click="updateProductText"
                class="mt-2 px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
            >
                Analyser
            </button>
        </div>
        
        <!-- Informations extraites -->
        <div class="mb-6 p-4 border rounded">
            <h4 class="font-bold mb-2">Informations extraites:</h4>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <p><strong>Vendor/Marque:</strong> {{ $vendor }}</p>
                    <p><strong>Name:</strong> {{ $name }}</p>
                </div>
                <div>
                    <p><strong>Variation:</strong> {{ $variation }}</p>
                    <p><strong>Type:</strong> {{ $type }}</p>
                </div>
            </div>
        </div>
        
        <!-- Bouton de recherche -->
        <div class="mb-6">
            <button 
                wire:click="searchProduct"
                class="px-6 py-2 bg-green-500 text-white rounded hover:bg-green-600"
            >
                🔍 Rechercher dans la base de données
            </button>
        </div>
        
        <!-- Message de statut -->
        @if($searchMessage)
            <div class="mb-4 p-3 bg-gray-100 rounded">
                <p class="text-sm">{{ $searchMessage }}</p>
            </div>
        @endif
        
        <!-- Résultats de la recherche -->
        @if(count($searchResults) > 0)
            <div class="mt-6">
                <h4 class="font-bold mb-3">Résultats de la recherche:</h4>
                <div class="space-y-3">
                    @foreach($searchResults as $product)
                        <div class="p-3 border rounded hover:bg-gray-50">
                            <div class="flex justify-between">
                                <div>
                                    <p class="font-semibold">{{ $product->vendor }} - {{ $product->name }}</p>
                                    <p class="text-sm text-gray-600">
                                        Type: {{ $product->type ?? 'N/A' }} | 
                                        Variation: {{ $product->variation ?? 'N/A' }}
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold">{{ $product->prix_ht }} {{ $product->currency }}</p>
                                    <p class="text-xs text-gray-500">
                                        {{ $product->created_at->format('d/m/Y') }}
                                    </p>
                                </div>
                            </div>
                            @if($product->url)
                                <div class="mt-2">
                                    <a 
                                        href="{{ $product->url }}" 
                                        target="_blank"
                                        class="text-xs text-blue-500 hover:underline"
                                    >
                                        Voir sur le site source
                                    </a>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        
        <!-- Affichage du texte original -->
        <div class="mt-6 p-3 bg-gray-100 rounded">
            <p class="text-sm font-semibold">Texte original analysé:</p>
            <p class="text-sm">"{{ $productText }}"</p>
        </div>
    </div>
</div>