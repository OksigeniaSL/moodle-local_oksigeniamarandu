# Avisos de Moodle por WhatsApp y Telegram (Oksigenia Marandu)

[![Licencia: GPLv3](https://img.shields.io/badge/licencia-GPLv3+-blue.svg)](LICENSE)
![Moodle](https://img.shields.io/badge/Moodle-4.5%E2%80%935.3-orange)
![Estado](https://img.shields.io/badge/estado-alfa-orange)
[![Patrocinio](https://img.shields.io/badge/patrocinio-Oksigenia-00d4ff)](https://oksigenia.com/en/open-source#sponsor)

**Oksigenia Marandu** lleva los avisos de Moodle™ y de sus plugins a WhatsApp y Telegram, desde la cuenta oficial de
WhatsApp Business del propio sitio y su propio bot: solo a quien ha dado su consentimiento, con tope de gasto y horas de
silencio.
*Marandu* es «noticia» o «aviso» en guaraní.

[Read in English](README.md)

## Estado

**Alfa.** Los avisos del sitio y de sus plugins por WhatsApp y Telegram, cada persona por los canales que elige; el
canal de la organización en Telegram y, con lo mismo, su canal de WhatsApp, que publica una persona con la publicación
ya preparada (WhatsApp no deja publicar en sus canales desde fuera); preguntas con botones (autorizaciones,
aprobaciones, la aprobación de un acceso);
envíos masivos con su aprobación y el gasto del mes; y las conversaciones del equipo con las familias y con quien
escriba, encima de Chatwoot. Cada versión es pequeña y se prueba antes de publicarla.

## Lo que hace falta

- Moodle de la 4.5 a la 5.3 (la 6.0, en pruebas), con el cron cada minuto, y el sitio en una dirección pública con
  HTTPS: Meta, Telegram y Chatwoot llaman a sus webhooks.
- PHP con GD y FreeType para las tarjetas; ffmpeg para las animadas (opcional).
- **WhatsApp:** una cuenta de la WhatsApp Business Platform (Cloud API) con una app en Meta for Developers. La cuenta y
  el número son del sitio; el plugin nunca usa un cliente no oficial. Meta cobra cada mensaje, según la categoría y el
  país; el plugin no añade ninguna tarifa. El webhook de la app (o del número, con un override) apunta a
  `/local/oksigeniamarandu/webhook.php`, con los campos `messages`, `message_template_status_update`,
  `template_category_update` y, para el historial de un número que pasa desde la app de WhatsApp Business, `history` y
  `smb_app_state_sync`.
- **Telegram:** un bot de la organización, hecho con @BotFather.
- **Correo (opcional):** los correos de las herramientas del sitio van a las direcciones de casa (los dominios que digas)
  por el correo del sitio, y a las demás también por el correo del sitio o por Mailjet, desde un remitente de un dominio
  de la organización firmado con DKIM, con sus imágenes dentro, su versión de texto y sin rastreo. Mailjet cuenta sus
  rebotes, bloqueos y spam a `/local/oksigeniamarandu/mailjet.php`. Los demás plugins mandan con `api::mail()`, y sus
  avisos de Moodle van por el mismo camino con `api::mail_from()` como remitente (Moodle 5.3 y posteriores).
- **Las conversaciones del equipo (opcional):** una cuenta de Chatwoot (instalado en un servidor propio) cuya bandeja
  recibe el mismo WhatsApp. Cada departamento es un equipo de Chatwoot; cada persona del equipo, un agente de Chatwoot
  con el mismo correo que su cuenta del sitio (una cuenta por correo); el horario del equipo, el de la bandeja, o una
  semana propia para el departamento que tiene otro horario (`cli/chat.php --team-hours`). Una app de plataforma de
  Chatwoot (se crea en su superadministración) deja que cada persona conteste a su nombre.

## Instalación

1. Copia el plugin en `local/oksigeniamarandu` de tu Moodle (`public/local/oksigeniamarandu` desde Moodle 5.1).
2. Entra en *Administración del sitio > Notificaciones* para acabar la instalación.
3. Abre *Oksigenia Marandu* (en *Administración del sitio > Extensiones > Extensiones locales*): su asistente conecta
   WhatsApp y te manda un mensaje de prueba; su página de Telegram conecta el bot.
4. Da la capacidad `local/oksigeniamarandu:chat` al rol del personal que atiende las conversaciones, y
   `local/oksigeniamarandu:chatall` a quien las ve todas.

Quien aloja muchos sitios puede hacerlo desde la línea de órdenes, con los secretos en un fichero o por la entrada
estándar, nunca en los argumentos (desde Moodle 5.1, con `public/` delante de `local/`):

```
sudo -u www-data php local/oksigeniamarandu/cli/setup.php --telegram-token-file=- --notices=local_example < token
sudo -u www-data php local/oksigeniamarandu/cli/chat.php --env=- --webhook --sync --status < chatwoot.env
```

`setup.php` conecta el bot y enciende los avisos de unos plugins (`--notices`, o `all`) en `--channels`; `chat.php`
conecta Chatwoot (`CHATWOOT_URL`, `CHATWOOT_ACCOUNT`, `CHATWOOT_TOKEN` de un administrador de la cuenta,
`CHATWOOT_PLATFORM_TOKEN` y `CHATWOOT_INBOX`). El departamento que no abre cuando la bandeja tiene su propia semana:

```
sudo -u www-data php local/oksigeniamarandu/cli/chat.php \
  --team-hours="Comedor=lun-vie 7:30-15:30; Admisiones=lun-vie 9:00-14:00,16:00-18:00; Secretaría=lun-jue 8:00-14:00, vie 8:00-13:00"
```

Los días, por su nombre en español, inglés, portugués o árabe, entero o corto, solos o en un tramo; después, las horas
de esos días, un tramo o varios. En el calendario de días cerrados (`--calendar`), «Comedor: cerrado» cierra solo ese
departamento ese día, y «Secretaría: 8:00-12:00» le da otro horario ese día. `--status` dice cómo está el sitio, y
`--help` trae el resto.

El correo, con las claves de Mailjet por la entrada estándar (una por línea), su remitente, el cupo de su plan y una
prueba:

```
printf '%s\n%s\n' "$KEY" "$SECRET" | sudo -u www-data php local/oksigeniamarandu/cli/mail.php --keys-file=- \
  --from=avisos@example.org --replyto=secretaria@example.org --quota=15000 --check
sudo -u www-data php local/oksigeniamarandu/cli/mail.php --test=tu@example.com --way=mailjet --events --status
```

## Desarrollo

Código, comentarios y commits en inglés; los textos, en los paquetes de idioma (`lang/en` y `lang/es`, y lo que leen las familias, también en `lang/pt`,
`lang/pt_br` y `lang/ar`). Las pruebas
nunca llaman a la API de Meta: responde por ella una imitación. Se pasan con `moodle-plugin-ci` en GitHub Actions en
Moodle 4.5 y 5.3 (PostgreSQL) y en 5.3 con MariaDB 11.4 en cada envío; la 5.2 y `main`, a mano o con una etiqueta. Más en
[CONTRIBUTING.md](CONTRIBUTING.md).

## Licencia

GNU GPL v3 o posterior. Ver [LICENSE](LICENSE).

© 2026 Oksigenia SL — dev@oksigenia.cc

Moodle y sus logotipos son marcas comerciales o registradas de Moodle Pty Ltd o de sus filiales. Oksigenia no está
vinculada a Moodle HQ ni es Moodle Certified Partner. WhatsApp es una marca de Meta Platforms, Inc.; este plugin no
está hecho, respaldado ni patrocinado por Meta.
