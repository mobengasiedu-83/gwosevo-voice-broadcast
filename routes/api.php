<?php

use App\Http\Controllers\Api\ClientApiController as Api;
use App\Http\Middleware\ApiCredential;
use App\Http\Middleware\ApiIdempotency;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['throttle:120,1', ApiCredential::class, ApiIdempotency::class])->name('api.v1.')->group(function () {
    foreach ([['get', 'contacts', 'contacts', 'contacts.read'], ['get', 'groups', 'groups', 'contacts.read'], ['post', 'contact-imports', 'import', 'contacts.write'], ['get', 'contact-imports/{id}', 'importStatus', 'contacts.read'], ['post', 'contact-imports/{id}/confirm', 'confirmImport', 'contacts.write'], ['get', 'campaigns', 'campaigns', 'campaigns.read'], ['get', 'campaigns/{id}', 'campaign', 'campaigns.read'], ['post', 'campaigns', 'draft', 'campaigns.write'], ['get', 'reports', 'reports', 'reports.read']] as [$method, $path, $action, $scope]) {
        Route::$method($path, [Api::class, $action])->defaults('scope', $scope)->name($action);
    }
});
