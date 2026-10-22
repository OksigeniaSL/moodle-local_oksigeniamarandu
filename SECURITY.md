# Security policy

## Reporting a vulnerability

If you find a security issue in `local_oksigeniamarandu`, **do not open a public GitHub issue**. Use one of these
instead:

1. **GitHub private vulnerability reporting** (preferred):
   <https://github.com/OksigeniaSL/moodle-local_oksigeniamarandu/security/advisories/new>
2. **Email**: `dev@oksigenia.cc` (PGP key
   [fingerprint `4D0E 67BD 1935 3CE2 A8E8  267F 8290 9111 546B AD97`](https://oksigenia.com/contacto_publickey.asc)).

Please include the affected plugin version, Moodle and PHP versions, a minimal proof of concept or reproduction
steps, and your assessment of impact.

## Response timeline

| Step | Target |
|---|---|
| Acknowledgement of the report | Within 5 business days |
| Initial assessment + severity rating | Within 10 business days |
| Patched release | Depends on severity; critical issues get out-of-band releases |

## Supported versions

Security patches land on the latest released line. Older versions are not back-patched. While the plugin is in
pre-alpha, only the latest release is supported.

## Scope

This policy covers the code in this repository. It does **not** cover Moodle core; for Moodle core issues follow the
[official Moodle security process](https://docs.moodle.org/en/Reporting_security_issues).

The plugin keeps the credentials of the site's WhatsApp account and, in later versions, the phone numbers of
families and users. Reports about the access token leaking, messages reaching people who did not opt in, or phone
numbers reaching users who should not see them are in scope.

## Out of scope

- Configuration choices by the site administrator (e.g. capability overrides that grant the administration of the
  plugin to roles you did not intend).
- The security of the WhatsApp Business Platform itself: report it to Meta.
- Visual collisions with site-specific themes (file as a bug).
