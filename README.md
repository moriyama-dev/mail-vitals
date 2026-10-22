# Mail Vitals

Finds out why email leaving WordPress does not arrive, and keeps a record of
what happened to every message the site sends.

<!--
SCREENSHOT: docs/images/settings-overview.png
Settings → Mail Vitals, top of the page: "How mail is sent" with SMTP selected,
and "Who mail comes from" below it. Crop to the first screen.
-->

## Why this exists

When a WordPress site stops delivering email, the usual advice is to install an
SMTP plugin and point it at a mail provider. That often works. When it does not,
the site owner is left with no information at all: `wp_mail()` returns `false`
and throws the reason away.

Mail Vitals is built around that missing information. It checks whether the
sending domain is set up so that mail from it will be accepted, keeps the
provider's verbatim error for every message that fails, and tells someone when
mail starts failing instead of waiting for a customer to mention it.

## What it does

### Checks the sending domain

SPF, DKIM and DMARC decide whether receiving servers believe mail really comes
from your domain. Mail Vitals looks them up and explains each result in plain
terms: what is missing, what it means, and whether it is the reason mail is
being rejected.

The results appear in two places: on the plugin's settings screen, and in
**Tools → Site Health**, where site owners already look for problems.

<!--
SCREENSHOT: docs/images/domain-authentication.png
Settings → Mail Vitals, "Domain authentication" table. Ideally a domain with at
least one warning (for example DMARC "p=none") so the "What it means" column
shows an explanation, not only green results.
-->

<!--
SCREENSHOT: docs/images/site-health.png
Tools → Site Health, the "Mail authentication" item expanded.
-->

### Sends over SMTP or the Brevo API, with a backup

Choose an SMTP server or Brevo as the way mail leaves the site. If both are
configured, the one you did not choose becomes the backup automatically: when
the main route refuses a message, the same message is tried on the other route
straight away, before `wp_mail()` returns. The caller gets the real outcome.

Mail Vitals does not replace `wp_mail()`. SMTP is set up through
`phpmailer_init` and Brevo through `pre_wp_mail`, so it does not fight other
plugins over who defined the function first. Left on "Leave it to WordPress",
it does not touch sending at all and only records what happens.

A route that is selected but not fully configured is not used. Half-configured
delivery fails in ways that are much harder to read than WordPress's default.

### Retries what could not be sent

A message every route refused is kept and tried again after 5 minutes,
30 minutes, 2 hours and 6 hours. This applies just as much to a site with a
single route. After the last attempt the message is given up on, and the log
says so in as many words.

The messages waiting, and when each will next be tried, are listed on the
settings screen. Their bodies are kept only while they wait, and are never
shown.

Retries run on WP-Cron. Mail Vitals does not ask you to set up a server cron
job. Instead, it notices when scheduled events have stopped running while
messages are waiting, which usually means `DISABLE_WP_CRON` was set with
nothing in its place, and reports it in Site Health and on the settings screen.

<!--
SCREENSHOT: docs/images/retry-queue.png
Settings → Mail Vitals, "Retry queue" with two or three waiting messages, one of
them showing "In 25 mins" and, ideally, one overdue. Blur real addresses.
-->

### Keeps a log, without the message body

Every send is recorded with its time, result, route, recipients, subject and,
for failures, the provider's full error text. The body of the message is never
stored: the log table has no column for it.

The log is trimmed daily by age and by number of rows. Either limit alone
leaves a gap: a busy site can pile up millions of rows within the retention
period, and a quiet site keeps years-old records.

<!--
SCREENSHOT: docs/images/send-log.png
Settings → Mail Vitals, "Send log" with at least one failed row whose "Reason"
column shows a real provider error. Blur or replace real recipient addresses.
-->

### Sends alerts to Slack, any webhook, or email

When mail starts failing, a notice goes to Slack, to a webhook of your choosing,
by email, or to any combination of them.

An outage at a mail provider fails hundreds of messages at once, so failures are
grouped by route and kind of problem. One problem produces a few notices, not
hundreds:

- at once
- again after 15 minutes, 1 hour and 6 hours if it is still happening
- then once a day for as long as it lasts
- and once more, a single notice, when it is over

A failure the backup route covered is reported as low severity. Mail is still
getting through, but the main route needs attention. A message that no route
delivered is high severity. A message the retry queue gives up on is reported
too.

An email alert is only ever sent over a route other than the one that is
failing. A message about SMTP being down, sent over SMTP, would never arrive.
So email alerts need both SMTP and Brevo set up; on a site with a single route,
use Slack or a webhook to hear about its failures. For the same reason, a
message the retry queue gave up on, which every route had just refused, is not
reported by email.

Notices never include the recipient or subject of the message that failed. Email addresses inside error
text are masked as `j***@example.com`. Masking is best effort; the full error
stays in the site's own log.

<!--
SCREENSHOT: docs/images/alerts-settings.png
Settings → Mail Vitals, "Alerts" section: the channel table (Slack set) and the
"Problems being reported" table with one open problem.
-->

<!--
SCREENSHOT: docs/images/slack-alert.png
A Slack channel showing one "mail is failing on SMTP" notice and, below it,
the "mail is getting through on SMTP again" notice for the same problem.
-->

### Reports delivery health to agents

On WordPress 6.9 and later, Mail Vitals registers a read-only ability,
`mail-vitals/delivery-health`, through the Abilities API. It returns the same
findings as Site Health, the domain checks and the state of the retry queue,
and requires the `view_site_health_checks` capability.

## Setup

1. Install and activate the plugin.
2. Go to **Settings → Mail Vitals**.
3. Choose how mail is sent and fill in the SMTP server details, if you use one.
4. Add any passwords, keys and webhook URLs to `wp-config.php` (below).
5. Send a test message from the same screen, and a test alert if you set up a
   channel.

## Credentials live in wp-config.php

Passwords, API keys, webhook URLs and the alert address are read from
constants only. There is no
field for them in the settings screen because there is no code that stores one
in the database. The screen shows whether each one is set, never its value.

```php
// SMTP password, when sending over SMTP.
define( 'MAIL_VITALS_SMTP_PASSWORD', '...' );

// Brevo API key, when sending through Brevo.
define( 'MAIL_VITALS_BREVO_API_KEY', '...' );

// Slack Incoming Webhook. https only.
define( 'MAIL_VITALS_SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/...' );

// Any endpoint that accepts a JSON POST.
define( 'MAIL_VITALS_WEBHOOK_URL', 'https://example.com/hooks/mail' );

// Optional. When set, webhook bodies are signed.
define( 'MAIL_VITALS_WEBHOOK_SECRET', '...' );

// Where email alerts go. Several addresses can be separated by commas.
define( 'MAIL_VITALS_ALERT_EMAIL', 'ops@example.com' );
```

A webhook URL is itself a credential: anyone who has it can post to the
channel.

## Webhook format

Each notice is a JSON `POST`. The `schema` number changes only if a field
changes meaning or is removed; new fields can appear without it.

```json
{
    "schema": 1,
    "event": "failing",
    "severity": "high",
    "site": {
        "name": "Example Shop",
        "url": "https://shop.example.com/"
    },
    "route": "smtp",
    "cause": "auth",
    "code": 535,
    "failures": 1,
    "failures_total": 1,
    "first_seen": "2026-10-22T16:20:00+00:00",
    "last_seen": "2026-10-22T16:20:00+00:00",
    "error": "SMTP Error: Could not authenticate.",
    "title": "Example Shop: mail is failing on SMTP",
    "text": "The mail server refused the login or API key.\n...",
    "plugin_version": "0.1.0"
}
```

| Field | Values |
|---|---|
| `event` | `failing`, `still_failing`, `recovered`, `test` |
| `severity` | `high` (mail is not being delivered), `low` (the backup route delivered it), `info` |
| `route` | `smtp`, `brevo`, `default` (WordPress's own mail function), `queue` (the retry queue) |
| `cause` | `connection`, `auth`, `sender`, `recipient`, `rate_limit`, `provider`, `abandoned`, `unknown` |

The event is also sent in the `X-Mail-Vitals-Event` header. With a secret set,
`X-Mail-Vitals-Signature` carries `sha256=` followed by the HMAC-SHA256 of the
raw body. Check it against the body exactly as received:

```php
$body      = file_get_contents( 'php://input' );
$expected  = 'sha256=' . hash_hmac( 'sha256', $body, getenv( 'MAIL_VITALS_WEBHOOK_SECRET' ) );
$signature = isset( $_SERVER['HTTP_X_MAIL_VITALS_SIGNATURE'] ) ? $_SERVER['HTTP_X_MAIL_VITALS_SIGNATURE'] : '';

if ( ! hash_equals( $expected, $signature ) ) {
	http_response_code( 401 );
	exit;
}
```

## What is stored

| Where | What | Removed |
|---|---|---|
| `{prefix}mail_vitals_log` | One row per send: time, result, route, recipients, subject, error. No body. | By age and row count, daily |
| `{prefix}mail_vitals_queue` | Messages waiting for a retry, including the body | As soon as the message is delivered or given up on |
| `mail_vitals_settings` option | Settings that are not secret | On uninstall |
| `mail_vitals_alert_state` option | Open problems and the last error from each alert channel | On uninstall |

Deactivating the plugin keeps all of it. Only deleting the plugin removes the
tables and options.

## Status

In development, version 0.1.0. Not yet on WordPress.org.

Planned before the first release:

- Detection of other plugins that also take over sending
- Checks of the server's own mail setup and of the configured credentials

## Requirements

- WordPress 6.9 or later
- PHP 7.4 or later

## Development

```bash
composer install
composer lint                 # WordPress Coding Standards and PHP 7.4 compatibility
bin/install-wp-tests.sh       # once; prepares the WordPress test suite
composer test
```

`bin/install-wp-tests.sh` reads the test database credentials from
`MAIL_VITALS_TEST_DB_NAME`, `MAIL_VITALS_TEST_DB_USER`,
`MAIL_VITALS_TEST_DB_PASSWORD` and `MAIL_VITALS_TEST_DB_HOST`. Use a database and
a user that exist only for the tests: the suite drops and recreates its tables.

## License

GPL-2.0-or-later
