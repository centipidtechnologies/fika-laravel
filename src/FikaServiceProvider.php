<?php

namespace Fika\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;

class FikaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fika.php', 'fika');
        $this->app->singleton(SendContext::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/fika.php' => $this->app->configPath('fika.php')], 'fika-config');

        // Register a named mailer automatically, so MAIL_MAILER=fika is sufficient.
        $configuration = $this->app->make(Repository::class);
        if ($configuration->get('mail.mailers.fika') === null) {
            $configuration->set('mail.mailers.fika', ['transport' => 'fika']);
        }

        $this->app->make(MailManager::class)->extend('fika', function (array $config = []) use ($configuration): FikaTransport {
            $defaults = $configuration->get('fika', []);
            if (! is_array($defaults)) {
                throw new \InvalidArgumentException('Fika mail configuration must be an array.');
            }
            $settings = array_replace($defaults, $config);

            return new FikaTransport(
                $this->app->make(Factory::class),
                $this->app->make(SendContext::class),
                (string) ($settings['api_key'] ?? ''),
                (string) ($settings['base_url'] ?? ''),
                (int) ($settings['timeout'] ?? 45),
                (int) ($settings['connect_timeout'] ?? 10),
            );
        });

        $events = $this->app->make(Dispatcher::class);
        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->app->make(SendContext::class)->begin($event->job->uuid());
        });
        $events->listen([JobProcessed::class, JobExceptionOccurred::class], function (): void {
            $this->app->make(SendContext::class)->end();
        });
    }
}
