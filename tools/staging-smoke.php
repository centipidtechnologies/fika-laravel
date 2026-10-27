<?php

// Explicitly opt in: this script sends one real email and replays the same request.
require __DIR__.'/../vendor/autoload.php';

use Fika\Laravel\FikaTransport;
use Fika\Laravel\SendContext;
use Illuminate\Http\Client\Factory;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Exception\TransportException;

if (! in_array('--send', $argv, true)) {
    fwrite(STDERR, "Pass --send to send a real staging email. See README.md for required environment variables.\n");
    exit(1);
}
$settings = [];
foreach (['FIKA_API_URL', 'FIKA_API_KEY', 'FIKA_SMOKE_FROM', 'FIKA_SMOKE_TO', 'FIKA_SMOKE_KEY'] as $name) {
    $settings[$name] = getenv($name);
    if (! is_string($settings[$name]) || trim($settings[$name]) === '') {
        fwrite(STDERR, "Missing required environment variable: $name\n");
        exit(1);
    }
}
if (parse_url($settings['FIKA_API_URL'], PHP_URL_SCHEME) !== 'https') {
    fwrite(STDERR, "FIKA_API_URL must use HTTPS.\n");
    exit(1);
}
$email = (new Email)->from($settings['FIKA_SMOKE_FROM'])->to($settings['FIKA_SMOKE_TO'])
    ->subject('Fika Laravel staging smoke test')->text('Fika Laravel staging smoke test. Verify that exactly one copy arrives.')
    ->attach('Fika attachment smoke test', 'fika-smoke.txt', 'text/plain');
$email->getHeaders()->addTextHeader('Fika-Idempotency-Key', $settings['FIKA_SMOKE_KEY']);
$transport = new FikaTransport(new Factory, new SendContext, $settings['FIKA_API_KEY'], $settings['FIKA_API_URL']);
try {
    $first = $transport->send($email)?->getMessageId();
    $replay = $transport->send($email)?->getMessageId();
    if ($first === null || $first !== $replay) {
        throw new RuntimeException('Idempotency replay returned a different message ID.');
    }
    echo "PASS: submitted and replay returned the same Fika ID: $first\n";
    echo "Verify one email arrived and its attachment opens. Submission alone does not prove delivery.\n";
} catch (Throwable $exception) {
    // TransportException messages are sanitized by this package; never dump the HTTP exception chain.
    if ($exception instanceof TransportException) {
        fwrite(STDERR, $exception->getMessage()."\n");
    }
    fwrite(STDERR, "Smoke test failed. Reuse FIKA_SMOKE_KEY when retrying an ambiguous outcome.\n");
    exit(1);
}
