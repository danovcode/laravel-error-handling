<?php

declare(strict_types=1);

namespace BusinessErrorHandler\Laravel;

use Illuminate\Support\ServiceProvider;

final class ErrorHandlerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2) . '/config/error-handler.php', 'error-handler');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeErrorHandlerCommand::class,
                MakeErrorHandlerMiddlewareCommand::class,
            ]);
        }
    }
}
