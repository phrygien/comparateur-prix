# Vider tout le cache
php artisan cache:clear

# Vider le cache de fichiers
php artisan cache:forget 'products:*'

# En production, optimiser le cache
php artisan config:cache
php artisan view:cache





################## Utilisation de redis

# Voir les statistiques
php artisan cache:clear-boutique --stats

# Vider tout le cache boutique
php artisan cache:clear-boutique --all

# Vider par pattern
php artisan cache:clear-boutique --pattern="products:*"

# Menu interactif
php artisan cache:clear-boutique

# Commandes Laravel standard
php artisan cache:clear
php artisan config:cache