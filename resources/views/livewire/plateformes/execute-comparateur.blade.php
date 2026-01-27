<div class="p-6 bg-white rounded-lg shadow">
    <div class="mb-4">
        <h2 class="text-xl font-bold mb-2">Extraction et recherche de produit</h2>
        <p class="text-gray-600">Produit: {{ $productName }}</p>
        
        <div class="mt-2 flex items-center gap-2">
            <input type="checkbox" id="useAIMatching" wire:model="useAIMatching" class="rounded">
            <label for="useAIMatching" class="text-sm">Utiliser le matching IA avancé</label>
        </div>
    </div>

    <button 
        wire:click="extractSearchTerme"
        wire:loading.attr="disabled"
        class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50 flex items-center gap-2"
    >
        <span wire:loading.remove>🔍 Extraire et rechercher</span>
        <span wire:loading>
            <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Extraction en cours...
        </span>
    </button>

    @if(session('error'))
        <div class="mt-4 p-4 bg-red-100 text-red-700 rounded">
            {{ session('error') }}
        </div>
    @endif

    @if(session('success'))
        <div class="mt-4 p-4 bg-green-100 text-green-700 rounded">
            {{ session('success') }}
        </div>
    @endif

    @if($extractedData)
        <div class="mt-6 p-4 bg-gray-50 rounded">
            <h3 class="font-bold mb-3">Critères extraits :</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="font-semibold">Vendor:</span> 
                    <span class="bg-blue-100 px-2 py-1 rounded text-sm">{{ $extractedData['vendor'] ?? 'N/A' }}</span>
                </div>
                <div>
                    <span class="font-semibold">Name:</span>
                    <span class="bg-green-100 px-2 py-1 rounded text-sm">{{ $extractedData['name'] ?? 'N/A' }}</span>
                </div>
                <div>
                    <span class="font-semibold">Variation:</span>
                    <span class="bg-yellow-100 px-2 py-1 rounded text-sm">{{ $extractedData['variation'] ?? 'N/A' }}</span>
                </div>
                <div>
                    <span class="font-semibold">Type:</span>
                    <span class="bg-purple-100 px-2 py-1 rounded text-sm">{{ $extractedData['type'] ?? 'N/A' }}</span>
                </div>
            </div>
            
            @php
                $nameWords = $this->extractKeywords($extractedData['name'] ?? '');
                $typeWords = $this->extractKeywords($extractedData['type'] ?? '');
            @endphp
            
            @if(!empty($nameWords) || !empty($typeWords))
                <div class="mt-3 pt-3 border-t">
                    <h4 class="font-semibold text-sm mb-2">Mots-clés extraits:</h4>
                    <div class="flex flex-wrap gap-1">
                        @foreach($nameWords as $word)
                            <span class="px-2 py-1 bg-blue-200 text-blue-800 rounded text-xs">{{ $word }}</span>
                        @endforeach
                        @foreach($typeWords as $word)
                            <span class="px-2 py-1 bg-purple-200 text-purple-800 rounded text-xs">{{ $word }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded">
            <div class="flex justify-between items-start mb-3">
                <h3 class="font-bold text-green-700">✓ Meilleur résultat :</h3>
                @if(isset($bestMatch->ai_score))
                    <span class="px-2 py-1 bg-green-200 text-green-800 rounded text-sm">
                        Score IA: {{ $bestMatch->ai_score }}/100
                    </span>
                @endif
            </div>
            <div class="flex items-start gap-4">
                @if($bestMatch->image_url)
                    <img src="{{ $bestMatch->image_url }}" alt="{{ $bestMatch->name }}" class="w-20 h-20 object-cover rounded">
                @endif
                <div class="flex-1">
                    <p class="font-semibold">{{ $bestMatch->vendor }} - {{ $bestMatch->name }}</p>
                    <p class="text-sm text-gray-600">{{ $bestMatch->type }} | {{ $bestMatch->variation }}</p>
                    <p class="text-sm font-bold text-green-600 mt-1">{{ $bestMatch->prix_ht }} {{ $bestMatch->currency }}</p>
                    <a href="{{ $bestMatch->url }}" target="_blank" class="text-xs text-blue-500 hover:underline">Voir le produit</a>
                    
                    @if(isset($bestMatch->ai_reasoning))
                        <div class="mt-2 p-2 bg-green-100 rounded text-xs text-green-800">
                            <span class="font-semibold">Analyse IA:</span> {{ $bestMatch->ai_reasoning }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts) && count($matchingProducts) > 1)
        <div class="mt-6">
            <h3 class="font-bold mb-3">Résultats trouvés ({{ count($matchingProducts) }}) :</h3>
            <div class="space-y-2 max-h-96 overflow-y-auto">
                @foreach($matchingProducts as $product)
                    <div 
                        wire:click="selectProduct({{ $product['id'] }})"
                        class="p-3 border rounded hover:bg-blue-50 cursor-pointer transition {{ $bestMatch && $bestMatch->id === $product['id'] ? 'bg-blue-100 border-blue-500' : 'bg-white' }}"
                    >
                        <div class="flex items-center gap-3">
                            @if($product['image_url'])
                                <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" class="w-12 h-12 object-cover rounded">
                            @endif
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="font-medium text-sm">{{ $product['vendor'] }} - {{ $product['name'] }}</p>
                                    @if(isset($product['ai_score']))
                                        <span class="px-1 py-0.5 bg-green-100 text-green-700 text-xs rounded">
                                            {{ $product['ai_score'] }}
                                        </span>
                                    @endif
                                </div>
                                <p class="text-xs text-gray-500">{{ $product['type'] }} | {{ $product['variation'] }}</p>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-sm">{{ $product['prix_ht'] }} {{ $product['currency'] }}</p>
                                <p class="text-xs text-gray-500">ID: {{ $product['id'] }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if($extractedData && empty($matchingProducts))
        <div class="mt-6 p-4 bg-yellow-50 border border-yellow-300 rounded">
            <p class="text-yellow-800">❌ Aucun produit trouvé avec ces critères</p>
            <p class="text-sm mt-2">Essayez de modifier les termes de recherche ou activez le matching IA avancé.</p>
        </div>
    @endif
</div>