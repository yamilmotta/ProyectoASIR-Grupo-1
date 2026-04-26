pipeline {
    agent {
        docker { 
            image 'php:8.2-cli' 
            args '-u root'
        }
    }
    stages {
        stage('Descargar Código') {
            steps {
                echo 'Clonando el repositorio ProyectoASIR-Grupo-1...'
                checkout scm
            }
        }
        stage('Análisis de Código (Linter PHP)') {
            steps {
                echo 'Ejecutando el runner efímero (Contenedor PHP)...'
                sh 'php -v'
                
                echo 'Verificando el directorio de la app de Damas:'
                sh 'ls -la app/damas/'
                
                echo 'Validando sintaxis estática de los archivos backend...'
                sh 'php -l app/damas/api.php'
                sh 'php -l app/damas/GameLogic.php'
                sh 'php -l app/damas/DB.php'
            }
        }
    }
}
