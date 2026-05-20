# Proyecto Intermodular ASIR - Grupo 1

El proyecto consiste en un entorno multisede de pruebas automatizadas de Integración Continuaa (CI/CD) con Jenkins, ubicado en una red local y conectado a dos sedes en la nube de AWS mediante un túnel VPN. El objetivo es poder actualizar la aplicación web desde Github (commits) y encargar a Jenkins la automatización de revisión de sintaxis, seguridad del código y seguridad de la aplicación (via pipeline); una vez el código actualizado pasa las pruebas de Jenkins, éste lo empaqueta y lo sube por SSH a la Sede Blanca (AWS2 - Producción). Este repositorio contiene los archivos de configuración de la BBDD, lógica de contenedores y el código de la aplicación web del Proyecto Intermodular de Final de Grado del Grupo 1 de ASIR.

## Diagrama de red
<img width="889" height="505" alt="{14EFA43E-4D1F-4B82-BA5D-0B950FEDAE61}" src="https://github.com/user-attachments/assets/1b9c1567-a3cc-48fe-a98b-5f8d75a7b8d5" />

## Arquitectura

La arquitectura se divide en tres partes:
- ***Sede Negra (AWS1)***: Una sede en AWS que cuenta Windows Server con Active Directory para gestionar las identidades de entrada al panel de Jenkins, AD ubicado en una subred privada; en subred pública instancia EC2 con Ubuntu Server configurado como Router NAT y Servidor VPN.
  
- ***Sede Blanca (AWS2)***: Una sede en AWS que cuenta con un Ubuntu Server, que da servicio web Apache dentro de un contenedor Docker, instanciado en subred pública. Además, una base de datos MySQL aislada en una subred privada, a la cual solo se llega por Agent Forwarding haciendo salto en la instancia EC2 Web.
  
- ***Sede Roja (Local)***: Una máquina virtual local con Jenkins para pipeline y el despliegue de la aplicación web hacia Sede Blanca (AWS2)

## Integrantes

Integrantes del Grupo 1:
- Carlos Merino (Sede Negra)
- Saskia Espinoza (Sede Blanca)
- Yamil Motta (Sede Roja)

### iNFO Adicional
> El repositorio está organizado en carpetas para separar el contenido. En la carpeta app está el código de la aplicación web; en config, los archivos de configuración relevantes; en la raíz, el Jenkinsfile que declara en código las instrucciones de la pipeline.
  


