<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Clients\AccountContext;
use Illuminate\Support\Facades\Gate;
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
        Gate::before(function (User $user) {
            if ($user->isClient()) {
                return false; // Client operational capabilities are introduced in later stages.
            }

            return $user->isSuperAdministrator() ? true : null;
        });
    }
}
