# Proyecto Intermodular ASIR - Grupo 1

Este repositorio contiene la infraestructura y el código del Proyecto Final de Grado del Grupo 1 de ASIR. El proyecto consiste en un entorno de pruebas de Integración Continuaa (CI/CD) con Jenkins, ubicado en una red local y conectado a dos sedes en la nube de AWS mediante un túnel VPN. El objetivo es poder actualizar la aplicación web desde Github (commits) y encargar a Jenkins las fases de desarrollo (pipeline).
## Integrantes
Integrantes del Grupo 1:
- Carlos Merino
- Saskia Espinoza
- Yamil Motta

## Arquitectura
La arquitectura se divide en tres partes:
- *AWS1*: Una sede "Pseudolocal" con un Windows Server y Active Directory para gestionar las identidades en una subred privada; en subred pública instancia EC3 con Ubuntu Server configurado como Router NAT y Servidor VPN.
  
- *AWS2*: Una sede en la "Nube" con el servidor web Apache en docker en subred pública y una base de datos MySQL aislada en una subred privada.
  
- *Local*: Un equipo en red local con máquina virtual con Jenkins para pipeline y el despliegue de la aplicación web hacia AWS2.
## Diagrama de red
<img width="868" height="519" alt="{56B200FC-9639-42A3-A075-3EA357B4CA07}" src="https://github.com/user-attachments/assets/8e17d0b1-8522-4dbb-854d-97517fe5f817" />

Sujeto a cambios! 

## iNFO Adicional
El repositorio está organizado en carpetas para separar el contenido. En la carpeta app estará el código de la aplicación web, y en docs guardamos la planificación, los esquemas de red y la documentación general.

