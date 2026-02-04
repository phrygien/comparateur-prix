<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;

class ProductSearchService
{
    public function extractProductInfo(string $searchTerm): array
    {
        $prompt = "Extrais les informations suivantes du nom de produit cosmétique/parfum ci-dessous.

Règles IMPORTANTES:
- vendor: la marque du produit
- name: le nom de la gamme/ligne du produit (sans la marque, sans le type, sans la variation)
- type: le type de produit (Eau de Toilette, Eau de Parfum, Crème, Sérum, Lotion, Gel, etc.)
- variation: les détails comme le volume, la cible (Homme/Femme), les attributs

Exemples:
- \"Calvin Klein - Eternity For Men - Eau de Toilette Vaporisateur 200 ml\"
  → vendor: \"Calvin Klein\", name: \"Eternity For Men\", type: \"Eau de Toilette\", variation: \"Vaporisateur 200 ml\"

- \"Payot - Source Nutrition - Crème Nourrissante 50ml\"
  → vendor: \"Payot\", name: \"Source Nutrition\", type: \"Crème\", variation: \"Nourrissante 50ml\"

- \"Coach Green Homme\"
  → vendor: \"Coach\", name: \"Green\", type: null, variation: \"Homme\"

- \"Dior - Sauvage Eau de Parfum 100ml\"
  → vendor: \"Dior\", name: \"Sauvage\", type: \"Eau de Parfum\", variation: \"100ml\"

Produit: {$searchTerm}

Réponds UNIQUEMENT en JSON avec cette structure exacte:
{
    \"vendor\": \"...\",
    \"name\": \"...\",
    \"type\": \"...\" ou null,
    \"variation\": \"...\" ou null
}";

        try {
            $result = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'Tu es un expert en extraction de noms de produits cosmétiques et parfums. Sois précis dans l\'extraction de chaque composant. Réponds uniquement en JSON valide.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = $result->choices[0]->message->content;
            $data = json_decode($content, true);

            return [
                'vendor' => $data['vendor'] ?? null,
                'name' => $data['name'] ?? null,
                'type' => $data['type'] ?? null,
                'variation' => $data['variation'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'vendor' => null,
                'name' => null,
                'type' => null,
                'variation' => null,
            ];
        }
    }

    /**
     * Normalise une chaîne pour la recherche SQL
     * Enlève les tirets, espaces, accents, met en minuscule
     */
    public function normalizeForSearch(?string $text): ?string
    {
        if (empty($text)) {
            return null;
        }

        // Mettre en minuscule
        $normalized = mb_strtolower($text, 'UTF-8');

        // Enlever les accents
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);

        // Enlever tous les caractères non-alphanumériques sauf les espaces
        $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized);

        // Remplacer les espaces multiples par un seul
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        // Trim
        $normalized = trim($normalized);

        // Enlever tous les espaces pour la comparaison finale
        $normalized = str_replace(' ', '', $normalized);

        return $normalized;
    }
}
