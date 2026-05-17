pipeline {
    agent none
    options {
        timeout(time: 30, unit: 'MINUTES')
        buildDiscarder(logRotator(numToKeepStr: '20'))
        disableConcurrentBuilds()
    }

    stages {
        stage('SAST: Lint sintáctico PHP') {
            agent { docker { image 'php:8.2-cli'; args '-u root' } }
            steps {
                sh 'php -l app/damas/api.php'
                sh 'php -l app/damas/GameLogic.php'
                sh 'php -l app/damas/DB.php'
            }
        }

        stage('SAST: PHPStan') {
            agent { docker { image 'ghcr.io/phpstan/phpstan:latest'; args '-u root --entrypoint=""' } }
            steps {
                sh 'phpstan analyse app/damas --level=5 --no-progress --error-format=table || true'
            }
        }

        stage('SAST: Semgrep (Seguridad)') {
            agent { docker { image 'returntocorp/semgrep:latest'; args '-u root --entrypoint=""' } }
            steps {
                sh 'semgrep --config=p/php --config=p/security-audit --error app/damas/ || true'
            }
        }

      stage('Levantar entorno efímero para DAST') {
            agent any
            steps {
                sh '''
                    docker network create damas-test 2>/dev/null || true
                    docker rm -f damas-db damas-web 2>/dev/null || true
                    
                    # 1. Empaquetamos la base de datos con el esquema dentro
                    echo "FROM mysql:8.0" > Dockerfile.db
                    echo "COPY app/damas/schema.sql /docker-entrypoint-initdb.d/01-schema.sql" >> Dockerfile.db
                    docker build -t damas-db:test -f Dockerfile.db .
                    
                    # 2. Levantamos la BD ya empaquetada (sin el parámetro -v)
                    docker run -d --name damas-db --network damas-test \
                        -e MYSQL_ROOT_PASSWORD=test \
                        -e MYSQL_DATABASE=damas \
                        damas-db:test
                    
                    echo "Esperando a que la base de datos arranque (Fase TCP)..."
                    for i in $(seq 1 30); do
                        if ! docker ps -q -f name=damas-db | grep -q .; then
                            echo "EL CONTENEDOR MYSQL HA MUERTO. Mostrando logs:"
                            docker logs damas-db
                            exit 1
                        fi
                        
                        if docker exec damas-db mysqladmin ping -uroot -ptest -h 127.0.0.1 --silent 2>/dev/null; then
                            echo "Base de datos lista y operativa."
                            break
                        fi
                        sleep 2
                    done
                    
                    docker build -t damas-web:test app/damas/
                    docker run -d --rm --name damas-web --network damas-test \
                        -e DB_HOST=damas-db \
                        -e DB_USER=root \
                        -e DB_PASS=test \
                        -e DB_NAME=damas \
                        damas-web:test
                    sleep 3
                '''
            }
        }

stage('DAST: ZAP API Scan') {
            agent any
            steps {
                sh '''
                    mkdir -p zap-reports
                    
                    # Interceptar variables de una partida real para el fuzzing
                    PARTIDA=$(docker run --rm --network damas-test curlimages/curl:latest \
                        -s -X POST -H "Content-Type: application/json" \
                        -d '{"apodo_rojo":"R","apodo_negro":"N"}' \
                        "http://damas-web/api.php?accion=nueva")
                    
                    CODIGO=$(echo $PARTIDA | grep -oP '"codigo":"\\K[^"]+')
                    TOKEN_R=$(echo $PARTIDA | grep -oP '"token_rojo":"\\K[^"]+')
                    
                    # Inyectar variables en el OpenAPI
                    sed -e "s/abc12345/$CODIGO/g" -e "s/0000000000000000000000000000000000000000000000000000000000000000/$TOKEN_R/g" app/damas/openapi.yaml > zap-reports/openapi-runtime.yaml
                    
                    # Ejecutar escaneo activo
                    docker run --rm --network damas-test \
                        -v $PWD/zap-reports:/zap/wrk:rw \
                        ghcr.io/zaproxy/zaproxy:stable \
                        zap-api-scan.py \
                            -t /zap/wrk/openapi-runtime.yaml \
                            -f openapi \
                            -r zap-api-report.html \
                            -J zap-api-report.json \
                            -I
                '''
            }
            post {
                always {
                    archiveArtifacts artifacts: 'zap-reports/*', allowEmptyArchive: true
                    publishHTML target: [
                        reportDir: 'zap-reports',
                        reportFiles: 'zap-api-report.html',
                        reportName: 'OWASP ZAP Report',
                        keepAll: true
                    ]
                }
            }
        }
        stage('Desmontar entorno efímero') {
            agent any
            steps {
                sh '''
                    docker rm -f damas-web damas-db 2>/dev/null || true
                    docker network rm damas-test 2>/dev/null || true
                '''
            }
        }

        stage('Deploy a Producción (AWS2)') {
            when { branch 'main' }
            agent any
            steps {
                sshagent(['aws2-deploy-ssh-key']) {
                    sh '''
                        rsync -avz --delete \
                            -e "ssh -o StrictHostKeyChecking=accept-new" \
                            app/damas/html/ \
                            ubuntu@10.100.0.3:/home/ubuntu/mi_proyecto_web/html/
                            
                        ssh -o StrictHostKeyChecking=accept-new ubuntu@10.100.0.3 \
                            'cd /home/ubuntu/mi_proyecto_web && docker compose up -d --build'
                    '''
                }
            }
        }
    }

    post {
        always {
            node('built-in') {
                sh '''
                    docker rm -f damas-web damas-db 2>/dev/null || true
                    docker network rm damas-test 2>/dev/null || true
                '''
            }
        }
        failure {
            echo 'Pipeline Fallida — El código no ha alcanzado AWS2.'
        }
        success {
            echo 'Pipeline OK — Despliegue completado.'
        }
    }
}
