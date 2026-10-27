# Fika for Laravel

Send existing Laravel mailables and mail notifications through your Fika workspace. Built for Laravel 12 and 13; dependency constraints let Composer select the compatible Symfony version. The package includes transport and Laravel integration tests. CI covers Laravel 12 on PHP 8.2 and Laravel 13 on PHP 8.3/8.5, with lowest and highest compatible dependency jobs.

## One-time Fika setup

1. Use an internal Centipid organization with an active Marketing Email subscription.
2. Verify your sending domain using the DNS records Fika provides.
3. Create a verified sender identity matching the address you will use as `MAIL_FROM_ADDRESS`.
4. Create a workspace API credential with the explicit `transactional-emails:send` scope. Add `transactional-emails:read` if your integration will poll delivery status.

Each product should have its own credential so it can be revoked independently. Store the secret in that application's environment, never in source control or browser code.

## Install

```bash
composer require fikamessaging/email
```

For local development, point a Composer path repository at your checkout:

```bash
composer config repositories.fika '{"type":"path","url":"../fika-laravel","options":{"symlink":true}}'
composer require fikamessaging/email:@dev
```

Laravel 12 requires PHP 8.2 or later; Laravel 13 requires PHP 8.3 or later. Composer selects compatible framework and Symfony versions.

## Configure

Laravel discovers the service provider automatically, and the provider registers the `fika` mailer. No edits to `config/mail.php` are required unless package discovery is disabled or you want multiple Fika mailers.

```dotenv
MAIL_MAILER=fika
FIKA_API_KEY=your_workspace_api_secret
MAIL_FROM_ADDRESS=noreply@yourproduct.com
MAIL_FROM_NAME="Your Product"
```

Optional configuration:

```dotenv
FIKA_API_URL=https://api.fikamessaging.com/api/v1
FIKA_TIMEOUT=45
FIKA_CONNECT_TIMEOUT=10
```

The URL includes `/api/v1`; the driver appends `/transactional-emails`. The default read timeout is longer than Fika's provider submission timeout. Configure your queue worker timeout to exceed the HTTP timeout and the queue connection's `retry_after` to exceed the worker timeout.

After changing configuration in a deployed product:

```bash
php artisan config:cache
php artisan queue:restart
# If using Horizon, use php artisan horizon:terminate instead of queue:restart.
```

If discovery is disabled, register `Fika\Laravel\FikaServiceProvider::class` in the consuming application's `bootstrap/providers.php`. To customize settings in a file:

```bash
php artisan vendor:publish --tag=fika-config
```

Existing mailables and notifications keep working when they use the application's default mailer:

```php
Mail::to($customer)->send(new PaymentReceipt($payment));
Mail::to($customer)->queue(new PaymentReceipt($payment));
$user->notify(new OrderShipped($order));
```

Search your product for explicit `mailer('resend')` calls or notification mailer overrides and change them to `fika` or remove the override. Changing `MAIL_MAILER` does not override an explicitly selected transport. Keep your Resend integration available until you have verified Fika delivery for the product's important flows.

Do not configure Fika's server application to call its own transactional API as its platform mailer. This package is for consuming products; Fika's own platform mail remains on its existing transport.

## Supported email features

- Existing Laravel mailables and mail-channel notifications, including verification, password resets, invitations, and queued sends.
- One recipient per message, HTML and/or plain text, the verified From address and its display name, and one Reply-To address.
- Up to 10 regular attachments, at most 10 MiB total decoded content. File data is base64-encoded over the API and decoded before SES submission. Deploy the Fika API with an HTTP request-body limit large enough for this overhead (at least 15 MiB).
- The Fika ID becomes the transport message ID and is available to Laravel's sent-mail events.

Multiple recipients, CC/BCC, a different envelope sender, and inline/CID attachments are explicitly rejected before sending. Arbitrary MIME headers are not forwarded; the `Fika-Idempotency-Key` header is consumed by the driver. Use a separate email per recipient and hosted image URLs for now. This package is not yet a complete replacement for every Resend feature.

Transactional mail does not need a marketing contact or marketing opt-in. Suppression, verified-sender, subscription-status, and tenant reputation checks still apply. Marketing campaigns continue through Fika's campaign features. Click tracking uses workspace preferences and defaults off; delivery/bounce/complaint events remain enabled.

## Retries and idempotency

The driver sends a required idempotency key on every API request. A queued job uses its stable Laravel job UUID plus its send sequence, so rerunning the same job does not create another send. Context is reset between jobs, including after exceptions. This assumes the job sends the same emails in the same order on every attempt; prefer one email per queued job.

For business-level deduplication, set a persistent key yourself. This also protects against dispatching a new job for the same receipt:

```php
// In the mailable's build() method:
$this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message) use ($order) {
    $message->getHeaders()->addTextHeader('Fika-Idempotency-Key', 'order-receipt:'.$order->id);
});
```

Use a different key for each intended send. The same key with different content is rejected by Fika. Dynamic signed URLs or timestamps can change content when a job is retried; preserve the original content when that matters. Synchronous sends without an explicit key get a new random key each time, so separate `Mail::send()` calls are separate sends.

Only `submitted` is reported to Laravel as success; it means Fika's provider accepted the message, not that the recipient received it. `failed`, `submitting`, `uncertain`, network errors, malformed responses, and HTTP errors raise a mail transport exception. Error messages include the idempotency key and omit the API body and secret. The driver performs no automatic HTTP retries.

An uncertain result may already have been sent. Retry using the same key; do not create another key or use automatic transport failover after an uncertain outcome. A definitive `failed` response stays associated with its key; correct the underlying problem and deliberately use a new key to send again. Fika records each submission durably. The initial version does not automatically reconcile an uncertain send with provider records.

## Development and tests

```bash
composer install
composer validate --strict
composer check-platform-reqs
composer test
```

All automated tests fake HTTP and prevent real email sends. Testbench boots Laravel with this package's provider to exercise mailables, attachments, mail notifications, queued mail, sent-message events, configuration overrides, and queue context reset. Transport tests also check rejection paths and attachment boundaries.

The GitHub Actions workflow tests Laravel 12 and 13 independently, including lowest compatible dependencies. A local run only verifies the installed versions; check all CI jobs before releasing. `vendor/` and PHPUnit caches are ignored. The lock file pins the default development environment; CI resolves dependencies separately for each matrix job.

### Live smoke test (production or staging)

This test sends one real email with a text attachment, then repeats the same request to check idempotency. You can use production if you do not have a staging deployment. The script is named `staging-smoke.php`, but it uses whichever API URL and workspace credential you provide.

Before running it:

- Enable API access for the workspace and create a credential under **Settings → API credentials**. Under **Transactional emails**, enable **Send** (`transactional-emails:send`). **Read** (`transactional-emails:read`) is optional for checking delivery status separately; this script does not need it. Campaign Send permission does not grant transactional Send permission.
- Use a verified sender identity belonging to the same workspace as the credential, and a recipient inbox you control.
- The workspace needs an active Marketing Email subscription. The transactional endpoint currently also requires an internal Centipid organization: at least one workspace in the organization must have an active, unsuspended Workspace Administrator with an active account and a verified `@centipidtechnologies.com` email. The administrator's account email determines this eligibility; verifying a sender address alone does not satisfy it.

**1. Set the test values in Bash.** Use the same terminal for all commands below. Replace `/path/to/fika-laravel` with your checkout location, and replace both example email addresses with your verified sender and test inbox.

```bash
cd /path/to/fika-laravel

export FIKA_API_URL='https://api.fikamessaging.com/api/v1'
export FIKA_SMOKE_FROM='verified-sender@example.com'
export FIKA_SMOKE_TO='your-test-inbox@example.com'
export FIKA_SMOKE_KEY="fika-smoke-$(date -u +%Y%m%dT%H%M%SZ)"
```

`https://api.fikamessaging.com/api/v1` is the production API base URL. `app.fikamessaging.com` is the web dashboard. If you have a staging API, substitute its versioned base URL and use a credential from that environment. Do not append `/transactional-emails`; the driver adds it.

`export` makes each value available to the PHP script. Generate the smoke key once per intended test email, and preserve it if you need to retry that test.

**2. Enter the API key without displaying it or placing its value in shell history.** Paste these commands exactly as written:

```bash
read -rsp 'Fika API key: ' FIKA_API_KEY
echo
export FIKA_API_KEY
```

When the terminal prompts **Fika API key:**, paste your actual workspace API key and press Enter. Nothing appears while you paste or type; that is expected. `FIKA_API_KEY` is the variable name: do not replace it with your key in the commands above. Keep the key out of source files, screenshots, and chat messages. These commands set the environment for this terminal session; they do not write a `.env` file.

**3. Run the smoke test.** This sends a real email, including when you use production:

```bash
php tools/staging-smoke.php --send
```

Success means both requests report `submitted` and return the same Fika ID. Check the recipient inbox: exactly one copy should arrive, and its attachment should open. API acceptance alone does not prove delivery. The email's subject still says “staging smoke test” even when using production.

If the result is ambiguous, rerun only the PHP command with the same key, sender, recipient, and content. Do not regenerate `FIKA_SMOKE_KEY` when retrying; a new key represents a new intended email.

An HTTP **403** can mean the credential lacks `transactional-emails:send` or the organization does not meet the internal-organization requirement. Check both even if the sender is verified. An HTTP **402** indicates the workspace lacks an active Marketing Email subscription.

When finished, remove the key from this terminal's environment:

```bash
unset FIKA_API_KEY
```

### Releases

Keep the existing v1 tag unchanged. Ship follow-up fixes and packaging changes as a new patch release (for example `v1.0.1` if the existing release is `v1.0.0`). Before tagging, require passing CI, run the staging smoke test, and verify installation into a fresh consuming Laravel application with the intended release constraint. Package archives exclude tests and CI configuration.

## Other languages

Focus on this Laravel driver while most internal products use Laravel. The authenticated JSON HTTP API is language-independent; Node.js, Python, Go, and other clients can call it directly. Add another SDK only when a real product needs it. An SDK is a convenience layer over the same API, not a requirement for language support.

## References

- [Laravel custom mail transports](https://laravel.com/docs/12.x/mail#custom-transports)
- [Composer path and VCS repositories](https://getcomposer.org/doc/05-repositories.md)

## License

Licensed under the [MIT License](LICENSE).
