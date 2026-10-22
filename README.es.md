# Avisos de Moodle por WhatsApp (Oksigenia Marandu)

[![Licencia: GPLv3](https://img.shields.io/badge/licencia-GPLv3+-blue.svg)](LICENSE)
![Moodle](https://img.shields.io/badge/Moodle-4.5%E2%80%935.3-orange)
![Estado](https://img.shields.io/badge/estado-pre--alfa-red)
[![Patrocinio](https://img.shields.io/badge/patrocinio-Oksigenia-00d4ff)](https://oksigenia.com/en/open-source#sponsor)

**Oksigenia Marandu para WhatsApp** lleva los avisos de Moodle™ y de sus plugins a WhatsApp, desde la cuenta oficial
de WhatsApp Business del propio sitio: solo a quien ha dado su consentimiento, con tope de gasto y horas de silencio.
*Marandu* es «noticia» o «aviso» en guaraní.

[Read in English](README.md)

## Estado

**Pre-alfa.** El sitio conecta su cuenta de WhatsApp y manda un mensaje de prueba, y las familias se dan de alta o de
baja escribiendo una palabra a su número. Los avisos llegan en las próximas versiones pequeñas, cada una probada antes
de publicarla. No es para sitios en producción.

## Lo que hace falta

- Una cuenta de WhatsApp Business en la WhatsApp Business Platform (Cloud API), con una app en Meta for Developers. La
  cuenta y el número son del sitio: el plugin usa las credenciales que pega el sitio y nunca un cliente no oficial.
- Meta cobra al sitio cada mensaje, según la categoría y el país. El plugin no añade ninguna tarifa.

## Instalación

1. Copia el plugin en `local/oksigeniamarandu` de tu Moodle (de 4.5 a 5.3).
2. Entra en *Administración del sitio > Notificaciones* para terminar la instalación.
3. En *Administración del sitio > Extensiones > Extensiones locales > Oksigenia Marandu para WhatsApp*, pega los datos
   de la cuenta y mándate un mensaje de prueba.

## Desarrollo

Código, comentarios y commits en inglés; los textos, en los paquetes de idioma (`lang/en`, `lang/es`). Las pruebas
nunca llaman a la API de Meta: responde por ella una imitación. Se pasan con `moodle-plugin-ci` en GitHub Actions en
Moodle 4.5 y 5.3 (PostgreSQL), en 5.3 también con MariaDB 11.4, y en `main` para ver lo que viene. Más en
[CONTRIBUTING.md](CONTRIBUTING.md).

## Licencia

GNU GPL v3 o posterior. Ver [LICENSE](LICENSE).

© 2026 Oksigenia SL — dev@oksigenia.cc

Moodle y sus logotipos son marcas comerciales o registradas de Moodle Pty Ltd o de sus filiales. Oksigenia no está
vinculada a Moodle HQ ni es Moodle Certified Partner. WhatsApp es una marca de Meta Platforms, Inc.; este plugin no
está hecho, respaldado ni patrocinado por Meta.
