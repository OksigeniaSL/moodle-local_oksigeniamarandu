# Changelog

## 0.2.0 (2026-10-22)

- Numbers and their consent: a number receives nothing until it opts in, and every opt-in and opt-out is kept as
  proof.
- Joining and leaving by WhatsApp: writing the opt-in word (ALTA or JOIN by default), with or without a code, or the
  opt-out word (BAJA or STOP). The words can be changed; case and accents do not matter. Each one gets a reply.
- The webhook Meta calls, checked with the verify token and the signature of the app secret.
- An API for other plugins: a code and a link that opens WhatsApp with the opt-in word and the code written, so that a
  family or a user joins and gets linked to what the code stands for.
- A list of the numbers, their status and whom they stand for.
- Full privacy provider: the numbers of a user can be exported and deleted; WhatsApp is declared as an external
  location.

## 0.1.0 (2026-10-22)

- Base of the plugin for Moodle 4.5 to 5.3, with English and Spanish language packs.
- The WhatsApp account of the site: phone number ID, WhatsApp Business account ID, access token (stored encrypted)
  and Graph API version.
- A page to send a test message (an approved template) to the administrator's phone, with clear messages for the
  usual errors of Meta: expired token, phone not on the list of the test number, template that does not exist.
- Client of the WhatsApp Cloud API, tested with a fake that answers for Meta.
- Checks of every Oksigenia repository: moodle-plugin-ci on PostgreSQL and MariaDB, Gitleaks, and the commit-msg hook
  plus its CI job against AI signatures.
