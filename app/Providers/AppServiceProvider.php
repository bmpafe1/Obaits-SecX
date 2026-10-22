<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Support\Llm\LlmClient::class, function () {
            // Explicit tenant first — a queued job sets it, because it has no
            // session to read. Never guess: the wrong tenant means the wrong
            // customer's key and the wrong provider seeing their content.
            $tenantId = \App\Support\Llm\LlmContext::tenantId() ?? session('tenant_id');
            return \App\Support\Llm\LlmFactory::for($tenantId);
        });

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
            $event->extendSocialite('keycloak', \SocialiteProviders\Keycloak\Provider::class);
        });

    }
}
