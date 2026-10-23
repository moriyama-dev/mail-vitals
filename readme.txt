=== Mail Vitals ===
Contributors: yoshiromoriyama
Tags: email, smtp, deliverability, dmarc, mail log
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 0.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds out why email from WordPress does not arrive, and keeps a record of what happened to every message the site sends.

== Description ==

When a WordPress site stops delivering email, `wp_mail()` returns `false` and throws the reason away. Mail Vitals is built around that missing information: it checks the things that decide whether mail arrives, keeps the provider's verbatim error for every message that fails, and tells someone when mail starts failing instead of waiting for a customer to mention it.

= Checks the sending domain =

SPF, DKIM and DMARC decide whether receiving servers believe mail really comes from your domain. Mail Vitals looks them up and explains each result in plain terms: what is missing, what it means, and whether it is the reason mail is being rejected. A From address on an IP address or on a name without a dot, which can never be authenticated, is flagged first. The results appear on the settings screen and in **Tools > Site Health**.

= Checks that the server and the routes can actually send =

A setting that is filled in is not the same as one that works. Without sending a single message, Mail Vitals checks:

* whether PHP's `mail()` has been switched off, and whether the program `sendmail_path` points to exists
* for an SMTP server: whether a username is set without its password, and whether the server answers. It connects, reads the server's greeting and hangs up; logging in is not tried
* for Brevo: whether the key is an API key rather than Brevo's separate SMTP key, and whether Brevo accepts it
* a route that is selected but not filled in, and keys in `wp-config.php` for a route that is not used

= Notices other code that takes over sending =

Two plugins that both want to send mail is a common way for mail to break without any message. Mail Vitals does not keep a list of plugin names. It looks at what is in place: whether `wp_mail()` is still WordPress's own, and which callbacks other code has attached to `pre_wp_mail` and `phpmailer_init`. Each one is traced to the plugin, must-use plugin or theme it belongs to, with the hook, the priority and the file.

= Sends over SMTP or the Brevo API, with a backup =

Choose an SMTP server or Brevo. If both are configured, the one you did not choose becomes the backup: when the main route refuses a message, the same message is tried on the other route straight away. Mail Vitals does not replace `wp_mail()`; it works through `phpmailer_init` and `pre_wp_mail`. Left on "Leave it to WordPress", it does not touch sending at all and only records what happens.

= Retries what could not be sent =

A message every route refused is tried again after 5 minutes, 30 minutes, 2 hours and 6 hours, and the log says so when it is given up on. Retries run on WP-Cron. Mail Vitals does not ask you to set up a server cron job; it notices when scheduled events have stopped running and says so.

= Keeps a log, without the message body =

Every send is recorded with its time, result, route, recipients, subject and, for failures, the provider's full error text. The body is never stored: the log table has no column for it. The log is trimmed daily by age and by number of rows.

= Sends alerts to Slack, any webhook, or email =

Failures are grouped by route and kind of problem, so an outage produces a few notices rather than hundreds: at once, after 15 minutes, 1 hour and 6 hours, then once a day, and once more when it is over. Email alerts are only sent over a route other than the failing one. Notices never include the recipient or subject of the failed message, and email addresses in error text are masked.

= Reports delivery health to agents =

A read-only ability, `mail-vitals/delivery-health`, returns the same findings as Site Health through the Abilities API. It requires the `view_site_health_checks` capability.

= Credentials stay in wp-config.php =

Passwords, API keys and webhook URLs are read from constants only. There is no field for them in the settings screen because there is no code that stores one in the database.

== External services ==

Mail Vitals only talks to the services below, and only when you have configured them. With the default setting ("Leave it to WordPress") and no alert channel set, it makes no outside connections except the DNS lookups described last.

= Brevo (transactional email API) =

Used when you choose Brevo as the way mail is sent, or when Brevo is set up as the backup route.

* When a message is sent: the sender, recipients, subject, body, headers and attachments are sent to `https://api.brevo.com/v3/smtp/email`, so that Brevo can deliver it.
* When the settings screen or Site Health checks the setup (at most every ten minutes): the API key is sent to `https://api.brevo.com/v3/account` to confirm that Brevo accepts it. No message is sent.

Brevo terms of use: https://www.brevo.com/legal/termsofuse/
Brevo privacy policy: https://www.brevo.com/legal/privacypolicy/

= Slack (Incoming Webhooks) =

Used only when `MAIL_VITALS_SLACK_WEBHOOK_URL` is defined in `wp-config.php`. When mail starts failing, keeps failing, recovers, or when you send a test alert, a short notice is posted to that webhook: the site name, the route, the kind of problem, the error code, the number of failures, when it started, and the error text with email addresses masked. Recipients, subjects and message bodies are never sent.

Slack terms: https://slack.com/main-services-agreement
Slack privacy policy: https://slack.com/trust/privacy/privacy-policy

= A webhook URL of your choosing =

Used only when `MAIL_VITALS_WEBHOOK_URL` is defined in `wp-config.php`. The same notices as above are posted as JSON to that address, together with the site's name and home URL and the plugin version. The service, and its terms, are whatever you point it at.

= Your SMTP server =

Used when you choose an SMTP server. Messages are handed to the server you configure. When the setup is checked (at most every ten minutes), Mail Vitals opens a connection to that server, reads its greeting and closes the connection, without logging in or sending anything.

= DNS =

To check SPF, DKIM and DMARC, the domain of the From address is looked up in DNS through your server's own resolver. Only public DNS records of that domain are requested. Results are kept for an hour.

== Installation ==

1. Upload the `mail-vitals` folder to `/wp-content/plugins/`, or install it from **Plugins > Add New**.
2. Activate it under **Plugins > Installed Plugins**.
3. Go to **Settings > Mail Vitals**.
4. Choose how mail is sent and fill in the SMTP server details, if you use one.
5. Add any passwords, keys and webhook URLs to `wp-config.php`:

`define( 'MAIL_VITALS_SMTP_PASSWORD', '...' );`
`define( 'MAIL_VITALS_BREVO_API_KEY', '...' );`
`define( 'MAIL_VITALS_SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/...' );`
`define( 'MAIL_VITALS_WEBHOOK_URL', 'https://example.com/hooks/mail' );`
`define( 'MAIL_VITALS_WEBHOOK_SECRET', '...' );`

6. Send a test message from the same screen, and a test alert if you set up a channel.

== Frequently Asked Questions ==

= Does it change how my site sends mail? =

Not unless you choose a route. With "Leave it to WordPress" selected, Mail Vitals only records what happens and runs its checks.

= Why can't I enter my SMTP password on the settings screen? =

Because a field would mean storing the password in the database. Reading it from a constant in `wp-config.php` keeps it out of the database, out of backups of it, and out of reach of anything that can read options.

= Why are email alerts not sent on my site? =

Email alerts are only sent over a route other than the one that is failing: an alert about SMTP being down, sent over SMTP, would never arrive. They need both an SMTP server and Brevo set up. With a single route, use Slack or a webhook.

= Is the message body stored anywhere? =

Not in the log, which has no column for it. A message waiting to be retried keeps its body in the retry queue, and the row is removed as soon as the message is delivered or given up on.

= How can a webhook receiver check that a notice really came from my site? =

Define `MAIL_VITALS_WEBHOOK_SECRET`. Each body is then signed with HMAC-SHA256, sent in the `X-Mail-Vitals-Signature` header as `sha256=` followed by the signature of the raw body.

== Screenshots ==

1. The settings screen, with an SMTP server chosen as the way mail is sent.
2. Domain authentication results for SPF, DKIM and DMARC, each with an explanation.
3. The Mail authentication item in Site Health.
4. Server and route checks: PHP mail() not in use, and the SMTP server answering as a mail server.
5. Other code handling mail: callbacks from another plugin, with hook, priority and file.
6. The retry queue, with a warning that scheduled events are not running.
7. The send log, with a refused message and the SMTP error kept verbatim.
8. Alert channels and the problems currently being reported.

== Changelog ==

= 0.2.0 =
* Alerts to Slack, a webhook of your choosing, or email, grouped so an outage raises a few notices rather than hundreds.
* Checks that PHP's mail() and the chosen route can actually send, without sending anything.
* Notices other code attached to pre_wp_mail and phpmailer_init, and a replaced wp_mail().
* The retry queue is listed on the settings screen.
* An IP address in the From address is reported as unusable.
* A queued message that can no longer be read is logged and reported instead of being dropped.

= 0.1.0 =
* First release: SPF, DKIM and DMARC checks, SMTP and Brevo routes with a backup, retry queue and send log.
