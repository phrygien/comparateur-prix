<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;

class ProductSearchService
{
    public function extractProductInfo(string $searchTerm): array
    {
        $prompt = "Extrais les informations suivantes du nom de produit cosmétique ci-dessous.

Règles IMPORTANTES:
- vendor: la marque du produit (généralement le premier mot avant le tiret)
- name: UNIQUEMENT le nom de la gamme/ligne du produit (PAS le type de produit)
- Le type de produit (Crème, Sérum, Lotion, Gel, Huile, Masque, etc.) ne doit JAMAIS être dans le name
- Les détails comme le volume (ml, g), les attributs (Nourrissante, Hydratante) ne doivent PAS être dans le name
- Si tu ne trouves pas le vendor, retourne null
- Si tu ne trouves pas le name, retourne null

Exemples:
- \"Payot - Source Nutrition - Crème Nourrissante 50ml\" → vendor: \"Payot\", name: \"Source Nutrition\"
- \"Clarins - Multi-Active - Sérum Anti-Âge\" → vendor: \"Clarins\", name: \"Multi-Active\"
- \"La Roche-Posay - Effaclar - Gel Purifiant\" → vendor: \"La Roche-Posay\", name: \"Effaclar\"

Produit: {$searchTerm}

Réponds UNIQUEMENT en JSON avec cette structure exacte:
{
    \"vendor\": \"...\",
    \"name\": \"...\"
}";

        try {
            $result = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'Tu es un expert en extraction de noms de produits cosmétiques. Tu extrais UNIQUEMENT la marque (vendor) et le nom de la gamme (name), JAMAIS le type de produit. Réponds uniquement en JSON valide.'],
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
            ];
        } catch (\Exception $e) {
            return [
                'vendor' => null,
                'name' => null,
            ];
        }
    }
}
