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

        $ourPrice = floatval($this->price);
        $theirPrice = floatval($comparePrice);
        $difference = (($ourPrice - $theirPrice) / $theirPrice) * 100;
        $amountDiff = $ourPrice - $theirPrice;

        return [
            'percentage' => round(abs($difference), 2),
            'amount' => round(abs($amountDiff), 2),
            'isCheaper' => $ourPrice < $theirPrice,
            'label' => $ourPrice < $theirPrice ? 'Nous sommes moins chers' : 'Nous sommes plus chers'
        ];
    }

    public function search(): void
    {
        $this->dispatch('search-updated');
    }

}; ?>

<div>
    <div class="w-full max-w-5xl mx-auto p-6">
        <livewire:plateformes.detail :id="$id" />

        @if($this->products->count() > 0)
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Produit</th>
                            <th>EAN</th>
                            <th>Site</th>
                            <th>Prix</th>
                            <th>Différence</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->products as $index => $product)
                            <tr>
                                {{-- Image --}}
                                <td>
                                    @if($product->image_url)
                                        <div class="avatar">
                                            <div class="mask mask-squircle h-12 w-12">
                                                <img src="{{ $product->image_url }}" 
                                                     alt="{{ $product->name }}"
                                                     onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2248%22 height=%2248%22%3E%3Crect fill=%22%23e5e7eb%22 width=%2248%22 height=%2248%22/%3E%3C/svg%3E'">
                                            </div>
                                        </div>
                                    @else
                                        <div class="avatar placeholder">
                                            <div class="bg-neutral text-neutral-content mask mask-squircle h-12 w-12">
                                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                </svg>
                                            </div>
                                        </div>
                                    @endif
                                </td>

                                {{-- Produit --}}
                                <td>
                                    <div class="font-bold">{{ $product->name ?? 'Sans nom' }}</div>
                                    @if($product->type)
                                        <div class="text-sm opacity-50">{{ $product->type }}</div>
                                    @endif
                                    @if($product->variation)
                                        <div class="text-xs opacity-40">{{ $product->variation }}</div>
                                    @endif
                                    @if($product->vendor)
                                        <div class="text-xs opacity-50">Vendeur: {{ $product->vendor }}</div>
                                    @endif
                                </td>

                                {{-- EAN --}}
                                <td>
                                    <span class="font-mono text-xs">{{ $product->ean ?? 'N/A' }}</span>
                                </td>

                                {{-- Site --}}
                                <td>
                                    @if($product->website)
                                        <div class="badge badge-ghost badge-sm">
                                            {{ $product->website->name ?? $product->website->url ?? 'Site' }}
                                        </div>
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>

                                {{-- Prix --}}
                                <td>
                                    <span class="font-bold">{{ number_format($product->prix_ht ?? 0, 2) }} {{ $product->currency ?? '€' }}</span>
                                </td>

                                {{-- Différence --}}
                                <td>
                                    @if($this->price)
                                        @php
                                            $priceDiff = $this->calculatePriceDifference($product->prix_ht ?? 0);
                                        @endphp
                                        @if($priceDiff['percentage'] > 0)
                                            <div class="badge {{ $priceDiff['isCheaper'] ? 'badge-success' : 'badge-error' }} badge-sm">
                                                {{ $priceDiff['isCheaper'] ? '-' : '+' }}{{ $priceDiff['percentage'] }}%
                                            </div>
                                            <div class="text-xs {{ $priceDiff['isCheaper'] ? 'text-success' : 'text-error' }}">
                                                {{ $priceDiff['isCheaper'] ? '-' : '+' }}{{ number_format($priceDiff['amount'], 2) }} €
                                            </div>
                                        @endif
                                    @endif
                                </td>

                                {{-- Action --}}
                                <td>
                                    @if($product->url)
                                        <a href="{{ $product->url }}" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-xs">
                                            Voir
                                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                            </svg>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6 text-sm text-gray-600">
                <strong>{{ $this->products->count() }}</strong> résultat(s) trouvé(s)
            </div>
        @else
            <div class="bg-white shadow rounded-lg py-12 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <h3 class="mt-2 text-sm font-semibold text-gray-900">Aucun résultat</h3>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $searchEan ? 'Aucun produit trouvé pour cet EAN.' : 'Entrez un code EAN pour rechercher.' }}
                </p>
            </div>
        @endif
    </div>
</div>