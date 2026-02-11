<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Livewire\Attributes\Computed;

new class extends Component {
    public string $ean = '';
    public string $price = '';
    public string $id = '';
    public string $searchEan = '';

    public function mount($ean = '', $price = '', $id = ''): void
    {
        $this->ean = $ean;
        $this->price = $price;
        $this->id = $id;
        $this->searchEan = $ean;
    }

    #[Computed]
    public function products()
    {
        if (empty($this->searchEan)) {
            return collect([]);
        }

        // Recherche directe par EAN avec Eloquent
        return Product::with('website')
            ->where('ean', $this->searchEan)
            ->get();
    }

    public function calculatePriceDifference($comparePrice): array
    {
        if (empty($this->price) || empty($comparePrice)) {
            return [
                'percentage' => 0,
                'amount' => 0,
                'isCheaper' => false,
                'label' => ''
            ];
        }

        $ourPrice = floatval($this->price); // Notre prix
        $theirPrice = floatval($comparePrice); // Leur prix

        // Calculer la différence : (notre prix - leur prix) / leur prix * 100
        $difference = (($ourPrice - $theirPrice) / $theirPrice) * 100;
        $amountDiff = $ourPrice - $theirPrice;

        return [
            'percentage' => round(abs($difference), 2),
            'amount' => round(abs($amountDiff), 2),
            'isCheaper' => $ourPrice < $theirPrice, // Nous sommes moins cher qu'eux
            'label' => $ourPrice < $theirPrice ? 'Nous sommes moins chers' : 'Nous sommes plus chers'
        ];
    }

    public function search(): void
    {
        // Trigger la recherche
        $this->dispatch('search-updated');
    }

}; ?>

<div class="w-full max-w-7xl mx-auto p-6">
    <livewire:plateformes.detail :id="$id" />

    @if($this->products->count() > 0)
        @foreach($this->products as $product)
            <x-list-item 
                :item="$product" 
                value="name"
                sub-value="ean"
                avatar="image_url"
                :link="$product->url"
            >
                <x-slot:value>
                    <div class="flex items-center gap-2">
                        <span>{{ $product->name ?? 'Sans nom' }}</span>
                        @if($product->website)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-indigo-50 text-xs text-indigo-700 font-medium">
                                <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                                </svg>
                                {{ $product->website->name ?? $product->website->url ?? 'Site' }}
                            </span>
                        @endif
                    </div>
                </x-slot:value>

                <x-slot:sub-value>
                    <div class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-xs text-gray-500">
                        <div class="space-y-1">
                            @if($product->type)
                                <div><span class="font-medium">Type:</span> {{ $product->type }}</div>
                            @endif
                            @if($product->ean)
                                <div><span class="font-medium">EAN:</span> <span class="font-mono">{{ $product->ean }}</span></div>
                            @endif
                            @if($product->vendor)
                                <div><span class="font-medium">Vendeur:</span> {{ $product->vendor }}</div>
                            @endif
                        </div>
                        <div class="space-y-1">
                            @if($product->variation)
                                <div><span class="font-medium">Variation:</span> {{ $product->variation }}</div>
                            @endif
                        </div>
                    </div>
                </x-slot:sub-value>

                <x-slot:actions>
                    <div class="flex flex-col items-end gap-1">
                        <span class="text-sm font-bold text-gray-900">
                            {{ number_format($product->prix_ht ?? 0, 2) }} {{ $product->currency ?? '€' }}
                        </span>
                        
                        @if($this->price)
                            @php
                                $priceDiff = $this->calculatePriceDifference($product->prix_ht ?? 0);
                            @endphp
                            <div class="flex items-center gap-x-1">
                                @if($priceDiff['isCheaper'])
                                    <div class="flex-none rounded-full bg-emerald-500/20 p-1">
                                        <div class="size-1 rounded-full bg-emerald-500"></div>
                                    </div>
                                    <span class="text-xs text-emerald-600 font-medium">{{ $priceDiff['label'] }}</span>
                                @else
                                    <div class="flex-none rounded-full bg-red-500/20 p-1">
                                        <div class="size-1 rounded-full bg-red-500"></div>
                                    </div>
                                    <span class="text-xs text-red-600 font-medium">{{ $priceDiff['label'] }}</span>
                                @endif
                            </div>
                            <span class="text-xs {{ $priceDiff['isCheaper'] ? 'text-emerald-600' : 'text-red-600' }} font-bold">
                                {{ $priceDiff['percentage'] }}% ({{ number_format($priceDiff['amount'], 2) }} €)
                            </span>
                        @endif
                    </div>
                </x-slot:actions>
            </x-list-item>
        @endforeach

        <div class="mt-6 text-sm text-gray-600">
            <strong>{{ $this->products->count() }}</strong> résultat(s) trouvé(s)
        </div>
    @else
        <div class="bg-white shadow rounded-lg py-12 text-center">
            <svg class="mx-auto size-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <h3 class="mt-2 text-sm font-semibold text-gray-900">Aucun résultat</h3>
            <p class="mt-1 text-sm text-gray-500">
                {{ $searchEan ? 'Aucun produit trouvé pour cet EAN.' : 'Entrez un code EAN pour rechercher.' }}
            </p>
        </div>
    @endif
</div>
