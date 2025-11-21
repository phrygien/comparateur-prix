<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div>
    <x-header title="Nos produits" subtitle="Les produits de notre boutique à comparer" no-separator>
        <x-slot:middle class="!justify-end">
            <x-input icon="o-bolt" placeholder="Search..." />
        </x-slot:middle>
        <x-slot:actions>
            <div class="drawer">
                <input id="my-drawer-1" type="checkbox" class="drawer-toggle" />


                <div class="drawer-content">
                    <!-- Page content here -->
                    <label for="my-drawer-1" class="btn drawer-button btn-primary">Filtre avancé</label>
                </div>


<div class="drawer-side">
    <label for="my-drawer-1" aria-label="close sidebar" class="drawer-overlay"></label>
    <div class="bg-base-200 min-h-full w-[500px] p-8">
        <h2 class="text-2xl font-bold mb-8">Filtres</h2>
        
        <form method="GET" class="space-y-6">
            <!-- Filtre par nom -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-semibold">Nom du produit</span>
                </label>
                <input 
                    type="text" 
                    name="name" 
                    placeholder="Rechercher un parfum..." 
                    class="input input-bordered w-full"
                />
            </div>

            <!-- Filtre par marque -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-semibold">Marque</span>
                </label>
                <select name="marque" class="select select-bordered w-full">
                    <option value="">Toutes les marques</option>
                    <option value="chanel">Chanel</option>
                    <option value="dior">Dior</option>
                    <option value="gucci">Gucci</option>
                    <option value="hermes">Hermès</option>
                    <option value="ysl">Yves Saint Laurent</option>
                    <option value="prada">Prada</option>
                    <option value="versace">Versace</option>
                    <option value="armani">Armani</option>
                </select>
            </div>

            <!-- Filtre par type de parfum -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-semibold">Type de parfum</span>
                </label>
                <select name="type" class="select select-bordered w-full">
                    <option value="">Tous les types</option>
                    <option value="eau_de_parfum">Eau de Parfum</option>
                    <option value="eau_de_toilette">Eau de Toilette</option>
                    <option value="eau_de_cologne">Eau de Cologne</option>
                    <option value="parfum">Parfum</option>
                </select>
            </div>

            <!-- Filtre par capacité -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-semibold">Capacité (ML)</span>
                </label>
                <select name="capacity" class="select select-bordered w-full">
                    <option value="">Toutes les capacités</option>
                    <option value="30">30 ML</option>
                    <option value="50">50 ML</option>
                    <option value="75">75 ML</option>
                    <option value="100">100 ML</option>
                    <option value="150">150 ML</option>
                </select>
            </div>

            <!-- Boutons d'action -->
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary flex-1">
                    Appliquer
                </button>
                <button type="reset" class="btn btn-ghost flex-1">
                    Réinitialiser
                </button>
            </div>
        </form>
    </div>
</div>


            </div>
        </x-slot:actions>
    </x-header>

    <div class="mx-auto overflow-hidden">
        <div class="grid grid-cols-1 gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-4 lg:gap-x-8">
            <a href="{{ route('article.comparate-prix', 1) }}" class="group text-sm">
                <img src="https://images.unsplash.com/photo-1541643600914-78b084683601?w=800&q=80"
                    alt="Elegant perfume bottle with golden cap"
                    class="aspect-square w-full rounded-lg bg-gray-100 object-cover group-hover:opacity-75">
                <h3 class="mt-4 font-medium text-gray-900">Eau de Parfum Luxe</h3>
                <p class="text-gray-500 italic">Rose & Jasmine</p>
                <p class="mt-2 font-medium text-gray-900">$120</p>
            </a>

            <a href="#" class="group text-sm">
                <img src="https://images.unsplash.com/photo-1556228720-195a672e8a03?w=800&q=80"
                    alt="Luxury moisturizing face cream in elegant jar"
                    class="aspect-square w-full rounded-lg bg-gray-100 object-cover group-hover:opacity-75">
                <h3 class="mt-4 font-medium text-gray-900">Crème Visage Premium</h3>
                <p class="text-gray-500 italic">Hydratation Intense</p>
                <p class="mt-2 font-medium text-gray-900">$85</p>
            </a>

            <a href="#" class="group text-sm">
                <img src="https://images.unsplash.com/photo-1615634260167-c8cdede054de?w=800&q=80"
                    alt="Vitamin C serum bottle with dropper"
                    class="aspect-square w-full rounded-lg bg-gray-100 object-cover group-hover:opacity-75">
                <h3 class="mt-4 font-medium text-gray-900">Sérum Vitamine C</h3>
                <p class="text-gray-500 italic">Anti-âge & Éclat</p>
                <p class="mt-2 font-medium text-gray-900">$65</p>
            </a>

            <a href="#" class="group text-sm">
                <img src="https://images.unsplash.com/photo-1585386959984-a4155224a1ad?w=800&q=80"
                    alt="Luxury matte lipstick in elegant case"
                    class="aspect-square w-full rounded-lg bg-gray-100 object-cover group-hover:opacity-75">
                <h3 class="mt-4 font-medium text-gray-900">Rouge à Lèvres Mat</h3>
                <p class="text-gray-500 italic">Rouge Passion</p>
                <p class="mt-2 font-medium text-gray-900">$35</p>
            </a>

            <a href="#" class="group text-sm">
                <img src="https://images.unsplash.com/photo-1596462502278-27bfdc403348?w=800&q=80"
                    alt="Eye shadow palette with multiple shades"
                    class="aspect-square w-full rounded-lg bg-gray-100 object-cover group-hover:opacity-75">
                <h3 class="mt-4 font-medium text-gray-900">Palette Fards à Paupières</h3>
                <p class="text-gray-500 italic">Nude Collection</p>
                <p class="mt-2 font-medium text-gray-900">$48</p>
            </a>

            <a href="#" class="group text-sm">
                <img src="https://images.unsplash.com/photo-1571875257727-256c39da42af?w=800&q=80"
                    alt="Skincare set with multiple bottles and jars"
                    class="aspect-square w-full rounded-lg bg-gray-100 object-cover group-hover:opacity-75">
                <h3 class="mt-4 font-medium text-gray-900">Coffret Soin Complet</h3>
                <p class="text-gray-500 italic">Routine Beauté</p>
                <p class="mt-2 font-medium text-gray-900">$195</p>
            </a>

        </div>
    </div>
</div>
