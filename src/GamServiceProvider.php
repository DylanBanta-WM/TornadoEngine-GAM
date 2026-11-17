<?php

namespace TornadoEngine\Gam;

use App\Administration\Administration;
use Illuminate\Support\ServiceProvider;
use TornadoEngine\Docs\Docs;

class GamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Gam::class, fn () => new Gam('http://gam:8000', Gam::sidecarToken()));
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // With the broker: what identifies people in Google (once GAM's files are uploaded).
        $this->callAfterResolving('TornadoEngine\Broker\Broker', function ($broker) {
            $broker->source('google', fn (array $emails) => app(Gam::class)->configured() ? app(BrokerSource::class)->values($emails) : []);
        });
    }

    public function boot(Administration $administration): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'gam');
        $this->loadRoutesFrom(__DIR__.'/../routes/gam.php');

        $administration->tab('gam', 'GAM', order: 30);
        $administration->card('gam::administration-card', tab: 'gam');

        $this->callAfterResolving(Docs::class, function (Docs $docs) {
            $docs->add('gam', __('Set up GAM'), 'gam::docs.setup', order: 20);
        });
    }
}
