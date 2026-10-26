<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Clients\Tenancy;
use App\Services\Payments\ConfiguredPaymentProvider;
use App\Services\Payments\PaymentProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(Tenancy::class);
        $this->app->bind(PaymentProvider::class, ConfiguredPaymentProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability) {
            $tenancy = app(Tenancy::class);
            if (! $tenancy->usable($user)) {
                return false;
            }
            if ($tenancy->clientId($user) !== null && ! in_array($ability, Tenancy::CLIENT_ABILITIES, true)) {
                return false;
            }

            return $user->isSuperAdministrator() ? true : null;
        });
    }
}
