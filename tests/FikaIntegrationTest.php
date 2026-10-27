<?php

namespace Fika\Laravel\Tests;

use Fika\Laravel\FikaServiceProvider;
use Fika\Laravel\FikaTransport;
use Fika\Laravel\SendContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Factory;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\MailManager;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as Notifications;
use Mockery;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class FikaIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FikaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.default', 'fika');
        $app['config']->set('mail.from', ['address' => 'sender@example.com', 'name' => 'Fika Test']);
        $app['config']->set('fika.api_key', 'test-key');
        $app['config']->set('fika.base_url', 'https://fika.test/api/v1');
        $app['config']->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $http = $this->app->make(Factory::class);
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['data' => ['id' => 'email-integration', 'status' => 'submitted']])]);
    }

    public function test_provider_registers_the_default_mailer_and_publishable_configuration(): void
    {
        $this->assertInstanceOf(FikaTransport::class, Mail::mailer()->getSymfonyTransport());
        $this->assertSame('fika', config('mail.mailers.fika.transport'));
        $this->assertSame(45, config('fika.timeout'));
        $this->assertArrayHasKey(dirname(__DIR__).'/src/../config/fika.php', FikaServiceProvider::pathsToPublish(FikaServiceProvider::class, 'fika-config'));
    }

    public function test_mailable_sends_attachments_and_exposes_the_fika_id_in_sent_events(): void
    {
        $id = null;
        $this->app['events']->listen(MessageSent::class, function (MessageSent $event) use (&$id): void {
            $id = $event->sent->getMessageId();
        });
        Mail::to('customer@example.com')->send(new ReceiptMail);
        $this->assertSame('email-integration', $id);
        $this->app->make(Factory::class)->assertSent(fn ($request) => $request['to'] === 'customer@example.com'
            && $request['from'] === 'sender@example.com'
            && $request['subject'] === 'Receipt'
            && $request['attachments'][0]['content'] === base64_encode('receipt data'));
    }

    public function test_mail_notification_uses_the_registered_transport(): void
    {
        Notifications::route('mail', 'customer@example.com')->notify(new ReceiptNotification);
        $this->app->make(Factory::class)->assertSent(fn ($request) => $request['to'] === 'customer@example.com'
            && $request['subject'] === 'Order shipped'
            && str_contains($request['html'], 'Your order is on its way.'));
    }

    public function test_queued_mailable_uses_a_job_key_and_clears_context_afterwards(): void
    {
        Mail::to('customer@example.com')->queue(new ReceiptMail);
        $this->app->make(Factory::class)->assertSent(fn ($request) => str_starts_with($request->header('Idempotency-Key')[0], 'fika-job-'));
        $this->assertNull($this->app->make(SendContext::class)->nextKey());
    }

    public function test_queue_event_hooks_reuse_retry_keys_and_clear_context_on_success_and_failure(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('uuid')->andReturn('stable-job-uuid');
        $job->shouldReceive('payload')->andReturn([]);
        $events = $this->app['events'];
        $context = $this->app->make(SendContext::class);
        $events->dispatch(new JobProcessing('test', $job));
        $first = $context->nextKey();
        $second = $context->nextKey();
        $this->assertNotSame($first, $second);
        $events->dispatch(new JobExceptionOccurred('test', $job, new RuntimeException('retry')));
        $this->assertNull($context->nextKey());
        $events->dispatch(new JobProcessing('test', $job));
        $this->assertSame($first, $context->nextKey());
        $this->assertSame($second, $context->nextKey());
        $events->dispatch(new JobProcessed('test', $job));
        $this->assertNull($context->nextKey());
    }

    public function test_named_mailer_overrides_package_settings(): void
    {
        config(['mail.mailers.custom' => ['transport' => 'fika', 'api_key' => 'custom-key', 'base_url' => 'https://custom.test/api/v1/']]);
        Mail::mailer('custom')->to('customer@example.com')->send(new ReceiptMail);
        $this->app->make(Factory::class)->assertSent(fn ($request) => $request->url() === 'https://custom.test/api/v1/transactional-emails'
            && $request->hasHeader('Authorization', 'Bearer custom-key'));
    }

    public function test_provider_preserves_an_existing_fika_mailer_configuration(): void
    {
        config(['mail.mailers.fika' => ['transport' => 'fika', 'api_key' => 'existing-key']]);
        $this->app->getProvider(FikaServiceProvider::class)->boot();
        $this->app->make(MailManager::class)->purge('fika');
        Mail::to('customer@example.com')->send(new ReceiptMail);
        $this->app->make(Factory::class)->assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer existing-key'));
    }
}

class ReceiptMail extends Mailable
{
    public function build(): static
    {
        return $this->subject('Receipt')->html('<p>Your receipt</p>')->attachData('receipt data', 'receipt.txt', ['mime' => 'text/plain']);
    }
}

class ReceiptNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Order shipped')->line('Your order is on its way.');
    }
}
