<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Clients\AccountContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(AccountContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('client-audio', function (Request $request) {
            return Limit::perMinute($request->isMethod('GET') ? 120 : 10)->by($request->user()?->id.'|'.$request->ip());
        });
        RateLimiter::for('client-contacts', function (Request $request) {
            return Limit::perMinute($request->isMethod('GET') ? 120 : 30)->by($request->user()?->id.'|'.$request->ip());
        });
        RateLimiter::for('client-auth', function (Request $request) {
            $route = $request->route()?->getName() ?? 'unknown';
            $limit = $request->isMethod('GET') ? 30 : (in_array($route, ['client.register.start', 'client.register.resend', 'client.password.email'], true) ? 3 : 5);

            return Limit::perMinute($limit)->by($route.'|'.$request->ip());
        });
        Gate::before(function (User $user) {
            if ($user->isClient()) {
                return false; // Client operational capabilities are introduced in later stages.
            }

            return $user->isSuperAdministrator() ? true : null;
        });
    }
}
