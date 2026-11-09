<?php

namespace App\Providers;

use App\Logging\SqlQueryLogger;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;   // ← 加上这一行
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        $this->registerSqlLogging();
    }

    private function registerSqlLogging(): void
    {
        $logger = new SqlQueryLogger(config('sql_logging', []));

        if (! $logger->enabled()) {
            return;
        }

        $listener = static function (QueryExecuted $query) use ($logger): void {
            $logger->logQuery($query);
        };

        DB::listen($listener);

        $handler = $this->app->make(ExceptionHandler::class);

        if (method_exists($handler, 'reportable')) {
            $handler->reportable(static function (QueryException $exception) use ($logger): void {
                $logger->logFailure($exception);
            });
        }
    }
}
