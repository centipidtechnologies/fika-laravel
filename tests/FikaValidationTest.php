<?php

namespace Fika\Laravel\Tests;

use Fika\Laravel\FikaTransport;
use Fika\Laravel\SendContext;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class FikaValidationTest extends TestCase
{
    #[DataProvider('attachmentBoundaries')]
    public function test_attachment_boundaries(int $count, int $bytes, bool $accepted): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['data' => ['id' => 'email-1', 'status' => 'submitted']])]);
        $email = $this->email();
        for ($i = 0; $i < $count; $i++) {
            $email->attach(str_repeat('x', $bytes), "$i.txt", 'text/plain');
        }
        if ($accepted) {
            $this->assertSame('email-1', $this->transport($http)->send($email)->getMessageId());
            $http->assertSentCount(1);
        } else {
            try {
                $this->transport($http)->send($email);
                $this->fail('Expected attachment limit rejection.');
            } catch (TransportException) {
                $http->assertNothingSent();
            }
        }
    }

    public static function attachmentBoundaries(): array
    {
        return [
            '10 attachments' => [10, 1, true],
            '11 attachments' => [11, 1, false],
            '10 MiB' => [1, 10 * 1024 * 1024, true],
            'over 10 MiB' => [1, 10 * 1024 * 1024 + 1, false],
            'aggregate over 10 MiB' => [2, 5 * 1024 * 1024 + 1, false],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_idempotency_keys_fail_without_sending(string $key): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $email = $this->email();
        $email->getHeaders()->addTextHeader('Fika-Idempotency-Key', $key);
        try {
            $this->transport($http)->send($email);
            $this->fail('Expected invalid key rejection.');
        } catch (TransportException $exception) {
            $this->assertStringContainsString('Fika-Idempotency-Key', $exception->getMessage());
            $http->assertNothingSent();
        }
    }

    public static function invalidKeys(): array
    {
        return [[''], ['contains spaces'], [str_repeat('a', 256)]];
    }

    #[DataProvider('malformedResponses')]
    public function test_malformed_success_responses_are_rejected(mixed $body): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($body)]);
        $this->expectException(TransportException::class);
        $this->transport($http)->send($this->email());
    }

    public static function malformedResponses(): array
    {
        return [
            [[]], [['data' => null]], [['data' => ['status' => 'submitted']]],
            [['data' => ['status' => 'submitted', 'id' => '']]],
            [['data' => ['status' => 'submitted', 'id' => 123]]], ['not json'],
        ];
    }

    #[DataProvider('unsupportedSenders')]
    public function test_unsupported_sender_settings_fail_before_sending(string $scenario): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $email = $this->email();
        $envelope = null;
        if ($scenario === 'multiple-from') {
            $email->addFrom('other@example.com');
        } elseif ($scenario === 'multiple-reply-to') {
            $email->replyTo('a@example.com', 'b@example.com');
        } else {
            $envelope = new Envelope(new Address('other@example.com'), [new Address('customer@example.com')]);
        }
        try {
            $this->transport($http)->send($email, $envelope);
            $this->fail('Expected sender constraint rejection.');
        } catch (TransportException) {
            $http->assertNothingSent();
        }
    }

    public static function unsupportedSenders(): array
    {
        return [['multiple-from'], ['multiple-reply-to'], ['mismatched-envelope']];
    }

    private function email(): Email
    {
        return (new Email)->from('sender@example.com')->to('customer@example.com')->subject('Receipt')->text('Receipt');
    }

    private function transport(Factory $http): FikaTransport
    {
        return new FikaTransport($http, new SendContext, 'test-key', 'https://fika.test/api/v1');
    }
}
