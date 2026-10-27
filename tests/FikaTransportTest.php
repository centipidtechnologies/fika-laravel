<?php

namespace Fika\Laravel\Tests;

use Fika\Laravel\FikaTransport;
use Fika\Laravel\SendContext;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class FikaTransportTest extends TestCase
{
    public function test_it_sends_laravel_email_content_and_attachments_and_returns_the_fika_id(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response(['data' => ['id' => 'email-123', 'status' => 'submitted']])]);
        $email = $this->email()->replyTo('support@example.com')->text('Your receipt')->attach("PDF\x00bytes", 'receipt.pdf', 'application/pdf');
        $sent = $this->transport($http)->send($email);
        $this->assertSame('email-123', $sent->getMessageId());
        $http->assertSent(fn (Request $request): bool => $request->url() === 'https://fika.test/api/v1/transactional-emails'
            && $request->hasHeader('Authorization', 'Bearer test-secret')
            && $request['from'] === 'receipts@example.com' && $request['from_name'] === 'Our Product'
            && $request['to'] === 'customer@example.com' && $request['reply_to'] === 'support@example.com'
            && $request['text'] === 'Your receipt' && $request['html'] === '<p>Your receipt</p>'
            && $request['attachments'][0]['content'] === base64_encode("PDF\x00bytes")
            && $request['attachments'][0]['filename'] === 'receipt.pdf');
    }

    public function test_plain_text_email_and_explicit_business_idempotency_keys_are_supported(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response(['data' => ['id' => 'email-123', 'status' => 'submitted']])]);
        $email = $this->email()->html(null)->text('A plain-text notification');
        $email->getHeaders()->addTextHeader('Fika-Idempotency-Key', 'receipt:order-123');
        $this->transport($http)->send($email);
        $http->assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'receipt:order-123')
            && ! isset($request['html']) && $request['text'] === 'A plain-text notification');
    }

    public function test_queue_retries_reuse_keys_and_different_messages_and_jobs_get_different_keys(): void
    {
        $http = new Factory;
        $keys = [];
        $http->fake(function (Request $request) use ($http, &$keys) {
            $keys[] = $request->header('Idempotency-Key')[0];

            return $http->response(['data' => ['id' => 'email-123', 'status' => 'submitted']]);
        });
        $context = new SendContext;
        $transport = $this->transport($http, $context);
        $context->begin('job-1');
        $transport->send($this->email());
        $transport->send($this->email());
        $context->end();
        $context->begin('job-1');
        $transport->send($this->email());
        $transport->send($this->email());
        $context->begin('job-2');
        $transport->send($this->email());
        $this->assertSame($keys[0], $keys[2]);
        $this->assertSame($keys[1], $keys[3]);
        $this->assertNotSame($keys[0], $keys[1]);
        $this->assertNotSame($keys[0], $keys[4]);
    }

    public function test_uncertain_and_failed_submissions_and_http_failures_throw_instead_of_reporting_success(): void
    {
        foreach ([['status' => 'uncertain', 'http' => 202], ['status' => 'submitting', 'http' => 202], ['status' => 'failed', 'http' => 200], ['status' => 'submitted', 'http' => 403]] as $scenario) {
            $http = new Factory;
            $http->fake(['*' => $http->response(['data' => ['id' => 'email-123', 'status' => $scenario['status']], 'secret' => 'must-not-leak'], $scenario['http'])]);
            try {
                $this->transport($http)->send($this->email());
                $this->fail('Expected a transport exception.');
            } catch (TransportException $exception) {
                $this->assertStringContainsString('HTTP '.$scenario['http'], $exception->getMessage());
                $this->assertStringNotContainsString('must-not-leak', $exception->getMessage());
            }
        }
    }

    public function test_network_failure_is_not_automatically_retried(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->failedConnection()]);
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('same idempotency key');
        $this->transport($http)->send($this->email());
    }

    public function test_unsupported_recipient_and_inline_attachment_features_fail_before_any_request(): void
    {
        $http = new Factory;
        $http->fake();
        foreach ([$this->email()->cc('copy@example.com'), $this->email()->bcc('private@example.com'), $this->email()->addTo('second@example.com'), $this->email()->embed('image-data', 'logo.png', 'image/png')] as $email) {
            try {
                $this->transport($http)->send($email);
                $this->fail('Expected unsupported mail features to fail.');
            } catch (TransportException) {
                $http->assertNothingSent();
            }
        }
    }

    public function test_missing_api_key_fails_before_any_request(): void
    {
        $http = new Factory;
        $http->fake();
        $transport = new FikaTransport($http, new SendContext, '', 'https://fika.test/api/v1');
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('FIKA_API_KEY');
        $transport->send($this->email());
    }

    private function email(): Email
    {
        return (new Email)->from(new Address('receipts@example.com', 'Our Product'))->to('customer@example.com')->subject('Receipt')->html('<p>Your receipt</p>');
    }

    private function transport(Factory $http, ?SendContext $context = null): FikaTransport
    {
        return new FikaTransport($http, $context ?? new SendContext, 'test-secret', 'https://fika.test/api/v1');
    }
}
