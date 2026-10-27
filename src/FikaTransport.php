<?php

namespace Fika\Laravel;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

class FikaTransport extends AbstractTransport
{
    public function __construct(
        private readonly Factory $http,
        private readonly SendContext $context,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout = 45,
        private readonly int $connectTimeout = 10,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if (trim($this->apiKey) === '' || trim($this->baseUrl) === '') {
            throw new TransportException('Set FIKA_API_KEY and a valid FIKA_API_URL before sending email.');
        }

        $original = $message->getOriginalMessage();
        if (! $original instanceof Message) {
            throw new TransportException('Fika requires a structured Symfony email message.');
        }
        $email = MessageConverter::toEmail($original);
        $envelope = $message->getEnvelope();
        if (count($envelope->getRecipients()) !== 1 || $email->getCc() !== [] || $email->getBcc() !== []) {
            throw new TransportException('Fika currently supports one recipient per email. Send separate messages instead of using multiple To, CC, or BCC addresses.');
        }
        if (count($email->getFrom()) !== 1 || count($email->getReplyTo()) > 1) {
            throw new TransportException('Fika requires one From address and at most one Reply-To address.');
        }

        $from = $email->getFrom()[0];
        if (strcasecmp($envelope->getSender()->getAddress(), $from->getAddress()) !== 0) {
            throw new TransportException('Fika requires the envelope sender to match the From address.');
        }

        $payload = [
            'to' => $envelope->getRecipients()[0]->getAddress(),
            'from' => $from->getAddress(),
            'from_name' => $from->getName(),
            'subject' => $email->getSubject(),
        ];
        if ($email->getHtmlBody() !== null) {
            $payload['html'] = $email->getHtmlBody();
        }
        if ($email->getTextBody() !== null) {
            $payload['text'] = $email->getTextBody();
        }
        if ($email->getReplyTo() !== []) {
            $payload['reply_to'] = $email->getReplyTo()[0]->getAddress();
        }
        if (count($email->getAttachments()) > 10) {
            throw new TransportException('Fika supports at most 10 attachments per email.');
        }
        if ($email->getAttachments() !== []) {
            $totalBytes = 0;
            $payload['attachments'] = [];
            foreach ($email->getAttachments() as $attachment) {
                if ($attachment->getDisposition() === 'inline') {
                    throw new TransportException('Fika does not yet support inline attachments. Use hosted images in HTML emails.');
                }
                $content = $attachment->getBody();
                $totalBytes += strlen($content);
                if ($totalBytes > 10 * 1024 * 1024) {
                    throw new TransportException('Fika attachments may total at most 10 MiB per email.');
                }
                $payload['attachments'][] = [
                    'filename' => $attachment->getFilename() ?? 'attachment',
                    'content_type' => $attachment->getContentType(),
                    'content' => base64_encode($content),
                ];
            }
        }

        $headers = $email->getHeaders();
        $key = $headers->get('Fika-Idempotency-Key')?->getBodyAsString();
        $key ??= $this->context->nextKey();
        $key ??= 'fika-'.bin2hex(random_bytes(24));
        if (preg_match('/^[A-Za-z0-9_.:-]{1,255}$/', $key) !== 1) {
            throw new TransportException('Fika-Idempotency-Key must be 1-255 letters, digits, dots, colons, dashes or underscores.');
        }
        if (! $headers->has('Fika-Idempotency-Key')) {
            $headers->addTextHeader('Fika-Idempotency-Key', $key);
        }

        try {
            // Do not use another mailer as automatic failover after an ambiguous send.
            $response = $this->http->withToken($this->apiKey)->acceptJson()->asJson()
                ->timeout($this->timeout)->connectTimeout($this->connectTimeout)
                ->withHeaders(['Idempotency-Key' => $key])
                ->post(rtrim($this->baseUrl, '/').'/transactional-emails', $payload);
        } catch (ConnectionException $exception) {
            throw new TransportException('Fika could not confirm submission. Retry with the same idempotency key: '.$key, 0, $exception);
        }

        $data = $response->json('data');
        if (! $response->successful() || ! is_array($data) || ($data['status'] ?? null) !== 'submitted'
            || ! is_string($data['id'] ?? null) || $data['id'] === '') {
            $status = is_array($data) && is_string($data['status'] ?? null) ? $data['status'] : 'rejected';
            // Do not include response bodies: they may contain recipient data or request details.
            throw new TransportException(sprintf('Fika submission %s (HTTP %d). Idempotency key: %s.', $status, $response->status(), $key));
        }

        $message->setMessageId($data['id']);
        $headers->addTextHeader('X-Fika-Email-ID', $data['id']);
    }

    public function __toString(): string
    {
        return 'fika';
    }
}
