# WhatsApp notifications for Moodle (Oksigenia Marandu)

[![License: GPLv3](https://img.shields.io/badge/license-GPLv3+-blue.svg)](LICENSE)
![Moodle](https://img.shields.io/badge/Moodle-4.5%E2%80%935.3-orange)
![Status](https://img.shields.io/badge/status-pre--alpha-red)
[![Sponsor](https://img.shields.io/badge/sponsor-Oksigenia-00d4ff)](https://oksigenia.com/en/open-source#sponsor)

**Oksigenia Marandu for WhatsApp** brings the notices of Moodle™ and of its plugins to WhatsApp, sent from the site's
own official WhatsApp Business account: only to people who opted in, with a spending cap and quiet hours.
*Marandu* means "news" or "notice" in Guarani.

[Leer en español](README.es.md)

## Status

**Pre-alpha.** This version connects the WhatsApp account of the site and sends a test message. The notices, the
contacts and their consent come in small versions, each one tested before it is released. Not for production sites.

## What you need

- A WhatsApp Business account on the WhatsApp Business Platform (Cloud API), with an app in Meta for Developers. The
  account and the number belong to the site: the plugin uses the credentials the site pastes and never an
  unofficial client.
- Meta charges the site for each message, by category and country. The plugin does not add any fee.

## Install

1. Copy the plugin to `local/oksigeniamarandu` in your Moodle (4.5 to 5.3).
2. Visit *Site administration > Notifications* to finish the install.
3. In *Site administration > Plugins > Local plugins > Oksigenia Marandu for WhatsApp*, paste the details of the
   account and send yourself a test message.

## Development

Code, comments and commit messages in English; texts in the language packs (`lang/en`, `lang/es`). The API of Meta is
never called from the tests: a fake answers for it. The tests run with `moodle-plugin-ci` in GitHub Actions on Moodle
4.5 and 5.3 (PostgreSQL), on 5.3 with MariaDB 11.4 too, and on `main` to see what is coming. See
[CONTRIBUTING.md](CONTRIBUTING.md).

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).

© 2026 Oksigenia SL — dev@oksigenia.cc

The word Moodle and associated Moodle logos are trademarks or registered trademarks of Moodle Pty Ltd or its
related affiliates. Oksigenia is not affiliated with Moodle HQ and is not a Moodle Certified Partner. WhatsApp is a
trademark of Meta Platforms, Inc.; this plugin is not made, endorsed or sponsored by Meta.
