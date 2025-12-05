# Vider tout le cache
php artisan cache:clear

# Vider le cache de fichiers
php artisan cache:forget 'products:*'

# En production, optimiser le cache
php artisan config:cache
php artisan view:cache