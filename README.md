# WhatsApp and Telegram notifications for Moodle (Oksigenia Marandu)

[![License: GPLv3](https://img.shields.io/badge/license-GPLv3+-blue.svg)](LICENSE)
![Moodle](https://img.shields.io/badge/Moodle-4.5%E2%80%935.3-orange)
![Status](https://img.shields.io/badge/status-alpha-orange)
[![Sponsor](https://img.shields.io/badge/sponsor-Oksigenia-00d4ff)](https://oksigenia.com/en/open-source#sponsor)

**Oksigenia Marandu** brings the notices of Moodle™ and of its plugins to WhatsApp and Telegram, from the site's own
official WhatsApp Business account and its own bot: only to people who opted in, with a spending cap and quiet hours.
*Marandu* means "news" or "notice" in Guarani.

[Leer en español](README.es.md)

## Status

**Alpha.** Notices of the site and of its plugins by WhatsApp and Telegram, each person on the channels they chose; the
channel of the organisation on Telegram; questions with buttons (permissions, approvals, the approval of a login);
sends to many with their approval and the spend of the month; and the conversations of the team with families and
everyone who writes, on top of Chatwoot. Each version is small and tested before it is released.

## What you need

- Moodle 4.5 to 5.3 (6.0 is being tested), with cron every minute, and the site on a public HTTPS address: Meta,
  Telegram and Chatwoot call its webhooks.
- PHP with GD and FreeType for the cards; ffmpeg for the animated ones (optional).
- **WhatsApp:** an account on the WhatsApp Business Platform (Cloud API) with an app in Meta for Developers. The account
  and the number belong to the site; the plugin never uses an unofficial client. Meta charges each message, by category
  and country; the plugin adds no fee. The webhook of the app (or of the number, with an override) points to
  `/local/oksigeniamarandu/webhook.php`, with the fields `messages`, `message_template_status_update`,
  `template_category_update` and, for the history of a number that moves from the app of WhatsApp Business,
  `history` and `smb_app_state_sync`.
- **Telegram:** a bot of the organisation, made with @BotFather.
- **The conversations of the team (optional):** an account of Chatwoot (self-hosted) whose inbox receives the same
  WhatsApp. Each department is a team of Chatwoot; each person of the team, an agent of Chatwoot with the same email as
  their account on the site (one account per email); the hours of the team, those of the inbox. A platform app of
  Chatwoot (made in its super administration) lets each person answer as themself.

## Install

1. Copy the plugin to `local/oksigeniamarandu` in your Moodle (`public/local/oksigeniamarandu` from Moodle 5.1).
2. Visit *Site administration > Notifications* to finish the install.
3. Open *Oksigenia Marandu* (in *Site administration > Plugins > Local plugins*): its wizard connects WhatsApp and
   sends you a test message; its page of Telegram connects the bot.
4. Nobody opens the conversations by default, not even knowing the address. Connecting Chatwoot gives the role
   «Conversations team» (`marandu_chat`) to its people with an account on the site, and from then on to whoever joins
   the account; whoever leaves loses it. `cli/chat.php --access-role=<short name>` uses a role of your own instead.
   Give `local/oksigeniamarandu:chatall` to whoever sees them all.
5. What Meta counts as marketing stops going by WhatsApp by itself (it costs several times a utility message) and the
   conversations do not offer those approved messages; the other channels go on. A notice switched on again by hand
   stays, and one whose text comes back as utility goes again. `cli/setup.php --marketing-off=0` leaves it as before.

Whoever hosts many sites can do it from the command line, with the secrets in a file or on the standard input, never
in the arguments (from Moodle 5.1, with `public/` before `local/`):

```
sudo -u www-data php local/oksigeniamarandu/cli/setup.php --telegram-token-file=- --notices=local_example < token
sudo -u www-data php local/oksigeniamarandu/cli/chat.php --env=- --webhook --sync --status < chatwoot.env
```

`setup.php` connects the bot and switches on the notices of some plugins (`--notices`, or `all`) for `--channels`;
`chat.php` connects Chatwoot (`CHATWOOT_URL`, `CHATWOOT_ACCOUNT`, `CHATWOOT_TOKEN` of an administrator of the account,
`CHATWOOT_PLATFORM_TOKEN` and `CHATWOOT_INBOX`). `--status` shows how the site is, and `--help` has the rest.

## Development

Code, comments and commit messages in English; texts in the language packs (`lang/en` and `lang/es`, and what families read in `lang/pt`, `lang/pt_br` and
`lang/ar`). The API of Meta is
never called from the tests: a fake answers for it. The tests run with `moodle-plugin-ci` in GitHub Actions on Moodle
4.5 and 5.3 (PostgreSQL) and on 5.3 with MariaDB 11.4 on every push; 5.2 and `main`, by hand or with a tag. See
[CONTRIBUTING.md](CONTRIBUTING.md).

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).

© 2026 Oksigenia SL — dev@oksigenia.cc

The word Moodle and associated Moodle logos are trademarks or registered trademarks of Moodle Pty Ltd or its
related affiliates. Oksigenia is not affiliated with Moodle HQ and is not a Moodle Certified Partner. WhatsApp is a
trademark of Meta Platforms, Inc.; this plugin is not made, endorsed or sponsored by Meta.
