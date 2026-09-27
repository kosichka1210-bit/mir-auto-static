# Stage 1 contact form (not yet production-verified)

Do not merge this branch until Telegram delivery from the hosting server is verified.
Pushing this branch does not deploy Pages; deployment is restricted to main.

- Deploy `public/api/contact.php` to `public_html/api/contact.php`.
- Keep the existing private bootstrap and token configuration outside public_html.
  The repository bootstrap uses `private/config/config.php`; the existing shared-host
  bootstrap uses `private/config.php`. Do not replace the production bootstrap blindly.
- Copy `private/contact-config.example.php` to `private/contact-config.php` on the
  server and set exactly one administrator chat ID. No fallback to bot whitelist.
- PHP needs curl and mbstring. `private/storage` must be writable by PHP and must
  not be publicly served. Rate state contains hashes and timestamps, not lead text.
- Allow the site's exact HTTPS origin in the existing private app configuration.
- Compare and back up the deployed handler before replacing it: the earlier
  IDE-deployed version is not byte-identical to this reviewed branch version.

The handler limits input size, validates fields, escapes Telegram HTML, uses a
honeypot, an exclusive per-IP lock and a 60-second throttle. Identical successful
submissions from the same IP are suppressed for ten minutes. This is basic spam
protection, not a distributed rate limiter. Telegram receives one attempt with a
3-second connect / 10-second total timeout; failures never report success.
Network timeouts after Telegram accepted a request remain ambiguous and cannot
provide exactly-once delivery across a crash or a later retry.

Before merging: test required fields, invalid origin, parallel submissions,
duplicate suppression, Telegram failure, successful delivery and actual receipt.
Then test the published form and reload it. Stage 1 is complete only after the
owner submits their own form and confirms receiving it in Telegram.
