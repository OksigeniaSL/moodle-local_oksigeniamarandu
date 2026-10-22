# Changelog

## 0.3.2 (2026-10-22)

- Wizard, step 1: each detail of the second part in one row, shorter texts and a wider column for the fields, so that
  the main button stays in view on a small laptop screen; the header row breaks whenever the title does not fit beside
  the progress.

## 0.3.1 (2026-10-22)

- Wizard: the title of the step is the heading of the page and the progress sits beside it, without the admin search;
  on a narrow screen the drawing of Meta folds before the fields, and long addresses no longer push the page wider
  than the phone. The drawing shows again which token not to use.
- Family page: one centred column on a computer too, a smaller QR code without a gap below, «Save the number» on one
  line, and an emblem for a link that does not work.

## 0.3.0 (2026-10-22)

- A set-up wizard instead of the plain settings: connect the account (checked with Meta, which answers with the number
  and the name of the site), let Meta reach the site (callback URL and verify token to copy, the app secret, and a
  check that Meta has called), and send yourself a test message that shows when it is sent, delivered and answered.
- The private page where a family joins, on the phone and without a Moodle account: it opens WhatsApp with the message
  already written, shows it as a QR code on a computer, changes by itself once the message arrives, and then offers
  the number of the site as a contact and the way to leave.
- `api::signup_url()` for that page, and the callback `<component>_oksigeniamarandu_label()` so that a plugin names
  whom a code stands for.
- Spanish texts for families, addressed as «tú».

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
