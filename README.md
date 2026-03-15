# Proyecto Intermodular ASIR - Grupo 1

Este repositorio contiene la infraestructura y el código del Trabajo de Fin de Grado del Grupo 1 de ASIR. El proyecto consiste en un entorno que conecta una red local simulada con la nube de AWS mediante un túnel VPN.

La arquitectura se divide en tres partes:
- Una sede pseudolocal con un Windows Server y Active Directory para gestionar las identidades.
- Una sede en la nube en AWS con el servidor web Apache abierto al público y una base de datos MySQL aislada en una red privada.
- Un equipo en red local que utiliza Jenkins para automatizar el despliegue de la página web hacia AWS.

El repositorio está organizado en carpetas para separar el contenido. En la carpeta app estará el código de la aplicación web, y en docs guardamos la planificación, los esquemas de red y la documentación general.

Integrantes del Grupo 1:
- Carlos Merino
- Saskia Espinoza
- Yamil Motta
