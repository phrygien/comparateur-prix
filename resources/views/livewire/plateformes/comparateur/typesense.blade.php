<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Collection;

new class extends Component {
    public string $name;
    public string $id;
    public string $price;
    public Collection $productsBySite;

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;

        $searchTerm = html_entity_decode($this->name);
        $parsed = $this->parseProductName($searchTerm);

        // Recherche avec text_match optimisé
        $products = Product::search($parsed['name'], function ($typesense, $query, $options) use ($parsed) {
            // Query sur les champs pertinents avec pondération
            $options['query_by'] = 'vendor,name,type,variation';
            $options['query_by_weights'] = '4,10,3,1'; // Name a le poids le plus élevé

            // Utiliser max_weight pour prioriser les champs avec poids élevé
            $options['text_match_type'] = 'max_weight';

            // Construction de la requête complète
            $queryParts = array_filter([
                $parsed['vendor'],
                $parsed['name'],
                $parsed['type'],
                $parsed['variation']
            ]);
            $options['q'] = implode(' ', $queryParts);

            // Filtres stricts OPTIONNELS via _eval pour le ranking
            $evalConditions = [];

            if (!empty($parsed['vendor'])) {
                $evalConditions[] = "(vendor:={$parsed['vendor']}):10";
            }

            if (!empty($parsed['type'])) {
                $evalConditions[] = "(type:={$parsed['type']}):5";
            }

            if (!empty($parsed['variation'])) {
                $evalConditions[] = "(variation:{$parsed['variation']}):3";
            }

            // Construire le sort_by avec _eval pour booster les correspondances exactes
            $sortBy = ['_text_match:desc'];

            if (!empty($evalConditions)) {
                array_unshift($sortBy, '_eval([' . implode(',', $evalConditions) . ']):desc');
            }

            $sortBy[] = 'created_at:desc';
            $options['sort_by'] = implode(',', $sortBy);

            // Paramètres de recherche stricte
            $options['prefix'] = false; // Pas de prefix matching
            $options['num_typos'] = '1,0,1,0'; // vendor:1, name:0, type:1, variation:0
            $options['typo_tokens_threshold'] = 1;
            $options['drop_tokens_threshold'] = 1; // Garder au moins 1 résultat
            $options['min_len_1typo'] = 5;
            $options['min_len_2typo'] = 8;
            $options['prioritize_exact_match'] = true; // Prioriser les correspondances exactes

            $options['per_page'] = 250;

            return $options;
        })
            ->query(fn($query) => $query->with('website'))
            ->get();

        $this->productsBySite = $products
            ->groupBy('web_site_id')
            ->map(function ($siteProducts) {
                return $siteProducts
                    ->groupBy('scrap_reference_id')
                    ->map(function ($refProducts) {
                        return $refProducts->sortByDesc('created_at')->first();
                    })
                    ->values();
            });
    }

    private function parseProductName(string $productName): array
    {
        $parts = array_map('trim', explode(' - ', $productName));

        $result = [
            'vendor' => $parts[0] ?? '',
            'name' => $parts[1] ?? '',
            'type' => '',
            'variation' => ''
        ];

        if (isset($parts[2])) {
            $lastPart = $parts[2];

            if (preg_match('/\b(\d+\s?(ml|g|oz|cl|l|mg))\b/i', $lastPart, $matches)) {
                $result['variation'] = trim($matches[1]);
                $result['type'] = trim(str_replace($matches[0], '', $lastPart));
            } else {
                $result['type'] = $lastPart;
            }
        }

        return $result;
    }
}; ?>

<div class="bg-white">

    <livewire:plateformes.detail :id="$id" />

    <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
        <h2 class="text-2xl font-bold text-gray-900 px-4 sm:px-0 py-6">
            Résultats pour : {{ $name }}
        </h2>

        @if($productsBySite->count() > 0)
            @foreach($productsBySite as $siteId => $siteProducts)
                @php
                    $site = $siteProducts->first()->website ?? null;
                @endphp

                <div class="mb-8">
                    <!-- En-tête du site -->
                    <div class="bg-gray-50 px-4 sm:px-6 py-4 border-b-2 border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">
                            {{ $site?->name ?? 'Site inconnu' }}
                        </h3>
                        @if($site?->url)
                            <a href="{{ $site->url }}" target="_blank" class="text-sm text-blue-600 hover:text-blue-800">
                                {{ $site->url }}
                            </a>
                        @endif
                        <p class="text-sm text-gray-500 mt-1">
                            {{ $siteProducts->count() }} {{ $siteProducts->count() > 1 ? 'produits' : 'produit' }}
                        </p>
                    </div>

                    <!-- Grille des produits du site -->
                    <div class="-mx-px grid grid-cols-2 border-l border-gray-200 sm:mx-0 md:grid-cols-3 lg:grid-cols-4">
                        @foreach($siteProducts as $product)
                            <div class="group relative border-r border-b border-gray-200 p-4 sm:p-6">
                                <div class="aspect-square rounded-lg bg-gray-200 overflow-hidden">
                                    <img
                                        src="{{ $product->image_url }}"
                                        alt="{{ $product->vendor }} - {{ $product->name }}"
                                        class="h-full w-full object-cover group-hover:opacity-75"
                                    >
                                </div>
                                <div class="pt-10 pb-4 text-center">
                                    <h3 class="text-sm font-medium text-gray-900">
                                        <a href="{{ $product->url }}" target="_blank">
                                            <span aria-hidden="true" class="absolute inset-0"></span>
                                            {{ $product->vendor }} - {{ $product->name }}
                                        </a>
                                    </h3>
                                    <div class="mt-3 flex flex-col items-center">
                                        <p class="text-xs text-gray-600">{{ $product->type }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ $product->variation }}</p>
                                        @if($product->scrap_reference_id)
                                            <p class="mt-1 text-xs text-gray-400">Réf: {{ $product->scrap_reference_id }}</p>
                                        @endif
                                        @if($product->created_at)
                                            <p class="mt-1 text-xs text-gray-400">
                                                Scrapé le {{ $product->created_at->format('d/m/Y') }}
                                            </p>
                                        @endif
                                    </div>
                                    <p class="mt-4 text-base font-medium text-gray-900">
                                        {{ $product->prix_ht }} {{ $product->currency }}
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @else
            <div class="text-center py-12">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-semibold text-gray-900">Aucun produit trouvé</h3>
                <p class="mt-1 text-sm text-gray-500">Aucun résultat pour "{{ $name }}"</p>
            </div>
        @endif
    </div>
</div>
