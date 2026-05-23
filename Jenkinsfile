pipeline {
    agent none //Para los agentes efimeros en cada stage
    options {
        timeout(time: 30, unit: 'MINUTES')
        // Retención afinada: el log es barato (guardamos 10), los artefactos pesan (solo 3)
        // y caducamos por tiempo para que builds antiguos no se acumulen indefinidamente.
        buildDiscarder(logRotator(
            numToKeepStr:         '10',
            artifactNumToKeepStr: '3',
            daysToKeepStr:        '30',
            artifactDaysToKeepStr: '7'
        ))
        disableConcurrentBuilds()
        // Imprime timestamps en cada línea del log (requiere plugin Timestamper, ya viene
        // en la suggested-plugins de Jenkins). Útil para correlacionar con incidencias VPN.
        timestamps()
    }

    stages {
        stage('SAST: Lint sintáctico PHP') {
            agent { docker { image 'php:8.2-cli'; args '-u root' } }
            when {
                // Si el commit NO toca app/damas, Jenkins ignora este stage por completo
                changeset "app/damas/**"
            }
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

                    # 2. Levantamos la BD ya empaquetada
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

                    # Interceptar variables de una partida real
                    PARTIDA=$(docker run --rm --network damas-test curlimages/curl:latest \
                        -s -X POST -H "Content-Type: application/json" \
                        -d '{"apodo_rojo":"R","apodo_negro":"N"}' \
                        "http://damas-web/api.php?accion=nueva")

                    CODIGO=$(echo $PARTIDA | grep -oP '"codigo":"\\K[^"]+')
                    TOKEN_R=$(echo $PARTIDA | grep -oP '"token_rojo":"\\K[^"]+')

                    # Inyectar variables en el OpenAPI
                    sed -e "s/abc12345/$CODIGO/g" -e "s/0000000000000000000000000000000000000000000000000000000000000000/$TOKEN_R/g" app/damas/openapi.yaml > openapi-runtime.yaml

                    # 1. Crear el volumen limpio
                    docker volume rm zap-data 2>/dev/null || true
                    docker volume create zap-data

                    # 2. Usar un contenedor auxiliar (helper) para inyectar el archivo en el volumen
                    docker rm -f zap-helper 2>/dev/null || true
                    docker create --name zap-helper -v zap-data:/zap/wrk alpine tail
                    docker cp openapi-runtime.yaml zap-helper:/zap/wrk/

                    # 3. Permisos para el usuario restringido de ZAP
                    docker run --rm -v zap-data:/zap/wrk alpine chmod 777 /zap/wrk

                    # 4. Lanzar ZAP montando el volumen
                    docker run --rm --network damas-test -v zap-data:/zap/wrk \
                        ghcr.io/zaproxy/zaproxy:stable \
                        zap-api-scan.py \
                            -t /zap/wrk/openapi-runtime.yaml \
                            -f openapi \
                            -r zap-api-report.html \
                            -J zap-api-report.json \
                            -I

                    # 5. Extraer los reportes desde el volumen al workspace de Jenkins
                    docker cp zap-helper:/zap/wrk/zap-api-report.html zap-reports/ || true
                    docker cp zap-helper:/zap/wrk/zap-api-report.json zap-reports/ || true

                    # 6. Limpiar el helper y el volumen
                    docker rm -f zap-helper
                    docker volume rm zap-data
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

        stage('Pre-flight: Verificar VPN y AWS2') {
            // Verificamos que el túnel WireGuard está vivo y que AWS2 responde
            // ANTES de empezar a empaquetar y enviar bytes. Si esto falla, el error
            // es claro y se evita un fallo confuso a mitad del tar | ssh.
            agent any
            steps {
                sshagent(['aws2-deploy-ssh-key']) {
                    sh '''
                        ssh -o StrictHostKeyChecking=accept-new \
                            -o ConnectTimeout=10 \
                            -o BatchMode=yes \
                            ubuntu@10.100.0.3 \
                            'echo "VPN OK desde $(hostname). Docker: $(docker --version)"'
                    '''
                }
            }
        }

        stage('Deploy a Producción (AWS2)') {
            agent any
            steps {
                sshagent(['aws2-deploy-ssh-key']) {
                    sh '''
                        # 1. Empaquetar al vuelo (excluyendo info sensible) y desempaquetar en AWS2
                        tar -czf - --exclude='schema.sql' --exclude='openapi.yaml' --exclude='Dockerfile*' --exclude='nada.txt' -C app/damas . \
                            | ssh -o StrictHostKeyChecking=accept-new ubuntu@10.100.0.3 \
                                "tar -xzf - -C /home/ubuntu/mi_proyecto_web/html/"

                        # 2. Levantar los contenedores en producción
                        ssh -n -o StrictHostKeyChecking=accept-new ubuntu@10.100.0.3 \
                            "cd /home/ubuntu/mi_proyecto_web && sudo docker-compose up -d"
                    '''
                }
            }
        }
    }

    post {
        always {
            node('built-in') {
                sh '''
                    # --- Cleanup del entorno efímero del DAST por si algún stage abortó a mitad ---
                    docker rm -f damas-web damas-db zap-helper 2>/dev/null || true
                    docker network rm damas-test 2>/dev/null || true
                    docker volume rm zap-data 2>/dev/null || true

                    # --- Cleanup de ficheros temporales que el pipeline crea en el workspace ---
                    rm -f Dockerfile.db openapi-runtime.yaml 2>/dev/null || true

                    # --- Higiene preventiva de Docker ---
                    # IMPORTANTE: usamos prune SIN -a. No queremos borrar imágenes que el
                    # próximo build va a reutilizar (php:8.2-cli, mysql:8.0, zaproxy, etc.);
                    # solo capas dangling, contenedores parados, volúmenes y redes huérfanos.
                    docker container prune -f 2>/dev/null || true
                    docker image prune -f     2>/dev/null || true
                    docker volume prune -f    2>/dev/null || true
                    docker network prune -f   2>/dev/null || true

                    # --- Aviso si el disco se acerca al umbral (no falla el build, solo avisa) ---
                    DISK_AVAIL_GB=$(df -BG --output=avail / | tail -1 | tr -dc '0-9')
                    echo "Espacio libre en / tras cleanup: ${DISK_AVAIL_GB}G"
                    if [ "${DISK_AVAIL_GB:-0}" -lt 3 ]; then
                        echo "AVISO: quedan menos de 3GB libres en /. Conviene revisar."
                    fi
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
