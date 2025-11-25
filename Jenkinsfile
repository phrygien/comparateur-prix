pipeline {
    agent any

    environment {
        APP_ENV = 'testing'
    }

    stages {

        stage('Fix Permissions for Jenkins') {
            steps {
                sh '''
                    # Donner les droits à Jenkins AVANT d'exécuter Git
                    sudo chown -R jenkins:jenkins /var/www/comparateur
                '''
            }
        }

        // stage('Mise à jour du code') {
        //     steps {
        //         sh '''
        //             git config --global --add safe.directory /var/www/comparateur || true
        //             cd /var/www/comparateur

        //             git reset --hard
        //             git clean -fd
        //             git pull origin main
        //         '''
        //     }
        // }
        stage('Mise à jour du code') {
            steps {
                sh '''
                    git config --global --add safe.directory /var/www/comparateur || true
                    cd /var/www/comparateur

                    git fetch origin main
                    git reset --hard origin/main
                    git clean -fd
                '''
            }
        }

        stage('Composer install') {
            steps {
                sh '''
                    cd /var/www/comparateur
                    composer install --no-interaction --prefer-dist --optimize-autoloader
                '''
            }
        }

        stage('NPM build') {
            steps {
                sh '''
                    cd /var/www/comparateur
                    npm install
                    npm run build
                '''
            }
        }

        stage('Fix Permissions for Apache') {
            steps {
                sh '''
                    sudo chown -R www-data:www-data /var/www/comparateur
                    sudo chmod -R 755 /var/www/comparateur

                    sudo chown -R www-data:www-data /var/www/comparateur/storage
                    sudo chown -R www-data:www-data /var/www/comparateur/bootstrap/cache

                    sudo chmod -R 775 /var/www/comparateur/storage
                    sudo chmod -R 775 /var/www/comparateur/bootstrap/cache
                '''
            }
        }


    }

    post {
        success {
            echo "Build terminé avec succès !"
        }
        failure {
            echo "Échec du pipeline "
        }
    }
}