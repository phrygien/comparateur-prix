# Voir combien de doublons existent (sans supprimer)
php artisan scraped-products:clean-duplicates --dry-run

# Supprimer les doublons (garde les 2 plus récents)
php artisan scraped-products:clean-duplicates

# Garder seulement le plus récent
php artisan scraped-products:clean-duplicates --keep=1

# Garder les 3 plus récents
php artisan scraped-products:clean-duplicates --keep=3