<?php

use Livewire\Volt\Component;
use App\Models\Product;
use App\Services\ProductSearchParser;
use Illuminate\Support\Collection;

new class extends Component {
    public string $name = '';
    public string $id = '';
    public string $price = '';
    public Collection $products;
    
    public array $parsedResult = [];
    public bool $loading = false;
    public ?string $error = null;

    public function mount($name = '', $id = '', $price = ''): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;
        $this->products = collect();
    }
    
    public function parseProduct(): void
    {
        $this->loading = true;
        $this->error = null;
        $this->parsedResult = [];
        
        try {
            if (empty($this->name)) {
                $this->error = 'Veuillez entrer un nom de produit';
                return;
            }
            
            $parser = new ProductSearchParser();
            $this->parsedResult = $parser->parseProductName($this->name);
            
        } catch (\Exception $e) {
            $this->error = 'Erreur: ' . $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }
    
    public function testWithExamples(): void
    {
        $this->loading = true;
        $this->error = null;
        
        try {
            $parser = new ProductSearchParser();
            
            $examples = [
                'Cacharel - Ella Ella Flora Azura - Eau de Parfum Vaporisateur 30ml',
                'Dior - J\'adore - Eau de Parfum 50ml',
                'Chanel - N°5 - Eau de Toilette Spray 100ml',
            ];
            
            $this->products = collect($parser->parseMultipleProducts($examples));
            
        } catch (\Exception $e) {
            $this->error = 'Erreur: ' . $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }
    
    public function clear(): void
    {
        $this->name = '';
        $this->parsedResult = [];
        $this->products = collect();
        $this->error = null;
    }
}; ?>

<div class="max-w-4xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow-lg p-6">
        <h2 class="text-2xl font-bold mb-6 text-gray-800">🧪 Test Product Search Parser</h2>
        
        {{-- Formulaire de test --}}
        <div class="mb-6">
            <label for="product-name" class="block text-sm font-medium text-gray-700 mb-2">
                Nom du produit
            </label>
            <input 
                type="text" 
                id="product-name"
                wire:model="name"
                placeholder="Ex: Cacharel - Ella Ella Flora Azura - Eau de Parfum Vaporisateur 30ml"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
        </div>
        
        {{-- Boutons d'action --}}
        <div class="flex gap-3 mb-6">
            <button 
                wire:click="parseProduct"
                wire:loading.attr="disabled"
                class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
            >
                <span wire:loading.remove wire:target="parseProduct">Analyser</span>
                <span wire:loading wire:target="parseProduct">Analyse en cours...</span>
            </button>
            
            <button 
                wire:click="testWithExamples"
                wire:loading.attr="disabled"
                class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
            >
                <span wire:loading.remove wire:target="testWithExamples">Tester avec exemples</span>
                <span wire:loading wire:target="testWithExamples">Chargement...</span>
            </button>
            
            <button 
                wire:click="clear"
                class="px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition"
            >
                🗑️ Effacer
            </button>
        </div>
        
        {{-- Message d'erreur --}}
        @if($error)
            <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 text-red-700 rounded">
                <p class="font-medium">❌ {{ $error }}</p>
            </div>
        @endif
        
        {{-- Résultat unique --}}
        @if(!empty($parsedResult))
            <div class="mb-6">
                <h3 class="text-lg font-semibold mb-3 text-gray-800">📊 Résultat de l'analyse</h3>
                <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <span class="font-semibold text-gray-700">Vendor:</span>
                            <span class="ml-2 text-gray-900">{{ $parsedResult['vendor'] ?? 'N/A' }}</span>
                        </div>
                        <div>
                            <span class="font-semibold text-gray-700">Name:</span>
                            <span class="ml-2 text-gray-900">{{ $parsedResult['name'] ?? 'N/A' }}</span>
                        </div>
                        <div>
                            <span class="font-semibold text-gray-700">Type:</span>
                            <span class="ml-2 text-gray-900">{{ $parsedResult['type'] ?? 'N/A' }}</span>
                        </div>
                        <div>
                            <span class="font-semibold text-gray-700">Variation:</span>
                            <span class="ml-2 text-gray-900">{{ $parsedResult['variation'] ?? 'N/A' }}</span>
                        </div>
                    </div>
                </div>
            </div>
        @endif
        
        {{-- Résultats multiples --}}
        @if($products->isNotEmpty())
            <div>
                <h3 class="text-lg font-semibold mb-3 text-gray-800">📋 Résultats des exemples</h3>
                <div class="space-y-4">
                    @foreach($products as $product)
                        <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                            <div class="mb-3">
                                <span class="text-sm font-medium text-gray-500">Original:</span>
                                <p class="text-gray-900 font-medium">{{ $product['original'] }}</p>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-2 pt-3 border-t border-gray-300">
                                <div>
                                    <span class="text-sm font-semibold text-gray-700">Vendor:</span>
                                    <span class="ml-2 text-sm text-gray-900">{{ $product['parsed']['vendor'] ?? 'N/A' }}</span>
                                </div>
                                <div>
                                    <span class="text-sm font-semibold text-gray-700">Name:</span>
                                    <span class="ml-2 text-sm text-gray-900">{{ $product['parsed']['name'] ?? 'N/A' }}</span>
                                </div>
                                <div>
                                    <span class="text-sm font-semibold text-gray-700">Type:</span>
                                    <span class="ml-2 text-sm text-gray-900">{{ $product['parsed']['type'] ?? 'N/A' }}</span>
                                </div>
                                <div>
                                    <span class="text-sm font-semibold text-gray-700">Variation:</span>
                                    <span class="ml-2 text-sm text-gray-900">{{ $product['parsed']['variation'] ?? 'N/A' }}</span>
                                </div>
                            </div>
                        </div>