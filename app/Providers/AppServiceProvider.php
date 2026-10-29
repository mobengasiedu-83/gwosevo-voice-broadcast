<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Clients\AdminIdentity;
use App\Services\Clients\Tenancy;
use App\Services\Payments\ConfiguredPaymentProvider;
use App\Services\Payments\PaymentProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(Tenancy::class);
        $this->app->scoped(AdminIdentity::class);
        $this->app->bind(PaymentProvider::class, ConfiguredPaymentProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer(
            ['campaigns.*', 'broadcast.*', 'contacts.*', 'billing.*', 'clients.show', 'dashboard.*', 'foundation.index'],
            function ($view) {
                if (! auth()->check() || app(Tenancy::class)->clientId(auth()->user()) !== null) {
                    return;
                }
                $identities = app(AdminIdentity::class);
                $identities->prepare($view->getData());
                $view->with('adminIdentities', $identities);
            }
        );

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
