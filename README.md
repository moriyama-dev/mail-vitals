# Mail Vitals

Finds out why email leaving WordPress does not arrive, and keeps a record of
what happened to every message the site sends.

## Why this exists

When a WordPress site stops delivering email, the usual advice is to install an
SMTP plugin and point it at a mail provider. That often works. When it does not,
the site owner is left with no information at all: `wp_mail()` returns `false`
and throws the reason away.

Mail Vitals is built around that missing information. It checks the things that
actually decide whether mail arrives — the sending domain's SPF, DKIM and DMARC
records, conflicting plugins, the server's own mail configuration — and it keeps
the provider's verbatim error for every message that fails.

## Status

Early development. Not yet usable.

## Requirements

- WordPress 6.9 or later
- PHP 7.4 or later

## License

GPL-2.0-or-later
