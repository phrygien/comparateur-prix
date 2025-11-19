<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="mt-5">
    <div class="overflow-x-auto rounded-box border border-base-content/5 bg-base-100">
        <table class="table">
            <!-- head -->
            <thead>
                <tr>
                    <th>#</th>
                    <th>Image</th>
                    <th>Nom du Produit</th>
                    <th>Catégorie</th>
                    <th>Description</th>
                    <th>Boutique</th>
                    <th>Prix</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <!-- row 1 -->
                <tr>
                    <th>1</th>
                    <td>
                        <img src="https://images.unsplash.com/photo-1541643600914-78b084683601?w=400&q=80" alt="Parfum"
                            class="w-16 h-16 object-cover rounded-lg">
                    </td>
                    <td>Eau de Parfum Luxe</td>
                    <td>Parfum</td>
                    <td>Rose & Jasmine</td>
                    <td>
                        <span class="badge badge-primary">Sephora</span>
                    </td>
                    <td class="font-semibold">$120</td>
                    <td>
                        <a href="#" class="btn btn-primary">Voir produit</a>
                    </td>
                </tr>
                <!-- row 2 -->
                <tr>
                    <th>2</th>
                    <td>
                        <img src="https://images.unsplash.com/photo-1556228720-195a672e8a03?w=400&q=80"
                            alt="Crème visage" class="w-16 h-16 object-cover rounded-lg">
                    </td>
                    <td>Crème Visage Premium</td>
                    <td>Soin Visage</td>
                    <td>Hydratation Intense</td>
                    <td>
                        <span class="badge badge-secondary">Marionnaud</span>
                    </td>
                    <td class="font-semibold">$85</td>
                    <td>
                        <a href="#" class="btn btn-primary">Voir produit</a>
                    </td>
                </tr>
                <!-- row 3 -->
                <tr>
                    <th>3</th>
                    <td>
                        <img src="https://images.unsplash.com/photo-1615634260167-c8cdede054de?w=400&q=80" alt="Sérum"
                            class="w-16 h-16 object-cover rounded-lg">
                    </td>
                    <td>Sérum Vitamine C</td>
                    <td>Soin Visage</td>
                    <td>Anti-âge & Éclat</td>
                    <td>
                        <span class="badge badge-accent">Douglas</span>
                    </td>
                    <td class="font-semibold">$65</td>
                    <td>
                        <a href="#" class="btn btn-primary">Voir produit</a>
                    </td>
                </tr>
                <!-- row 4 -->
                <tr>
                    <th>4</th>
                    <td>
                        <img src="https://images.unsplash.com/photo-1585386959984-a4155224a1ad?w=400&q=80"
                            alt="Rouge à lèvres" class="w-16 h-16 object-cover rounded-lg">
                    </td>
                    <td>Rouge à Lèvres Mat</td>
                    <td>Maquillage</td>
                    <td>Rouge Passion</td>
                    <td>
                        <span class="badge badge-primary">Sephora</span>
                    </td>
                    <td class="font-semibold">$35</td>
                    <td>
                        <a href="#" class="btn btn-primary">Voir produit</a>
                    </td>
                </tr>
                <!-- row 5 -->
                <tr>
                    <th>5</th>
                    <td>
                        <img src="https://images.unsplash.com/photo-1596462502278-27bfdc403348?w=400&q=80" alt="Palette"
                            class="w-16 h-16 object-cover rounded-lg">
                    </td>
                    <td>Palette Fards à Paupières</td>
                    <td>Maquillage</td>
                    <td>Nude Collection</td>
                    <td>
                        <span class="badge badge-info">Nocibé</span>
                    </td>
                    <td class="font-semibold">$48</td>
                    <td>
                        <a href="#" class="btn btn-primary">Voir produit</a>
                    </td>
                </tr>
                <!-- row 6 -->
                <tr>
                    <th>6</th>
                    <td>
                        <img src="https://images.unsplash.com/photo-1571875257727-256c39da42af?w=400&q=80" alt="Coffret"
                            class="w-16 h-16 object-cover rounded-lg">
                    </td>
                    <td>Coffret Soin Complet</td>
                    <td>Kit Beauté</td>
                    <td>Routine Beauté</td>
                    <td>
                        <span class="badge badge-secondary">Marionnaud</span>
                    </td>
                    <td class="font-semibold">$195</td>
                    <td>
                        <a href="#" class="btn btn-primary">Voir produit</a>
                    </td>
                </tr>
                <!-- row 7 -->
                <tr>
                    <th>7</th>
                    <td>
                        <img src="https://images.unsplash.com/photo-1608248543803-ba4f8c70ae0b?w=400&q=80" alt="Mascara"
                            class="w-16 h-16 object-cover rounded-lg">
                    </td>
                    <td>Mascara Volume Intense</td>
                    <td>Maquillage</td>
                    <td>Noir Profond</td>
                    <td>
                        <span class="badge badge-accent">Douglas</span>
                    </td>
                    <td class="font-semibold">$32</td>
                    <td>
                        <a href="#" class="btn btn-primary">Voir produit</a>
                    </td>
                </tr>
                <!-- row 8 -->
                <tr>
                    <th>8</th>
                    <td>
                        <img src="https://images.unsplash.com/photo-1612817288484-6f916006741a?w=400&q=80"
                            alt="Fond de teint" class="w-16 h-16 object-cover rounded-lg">
                    </td>
                    <td>Fond de Teint Liquide</td>
                    <td>Maquillage</td>
                    <td>Couvrance Naturelle</td>
                    <td>
                        <span class="badge badge-primary">Sephora</span>
                    </td>
                    <td class="font-semibold">$42</td>
                    <td>
                        <a href="#" class="btn btn-primary">Voir produit</a>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
