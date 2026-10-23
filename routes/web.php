
<?php

use App\Http\Controllers\BroadcastController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ContactWorkspaceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FoundationController;
use App\Http\Controllers\InteractiveController;
use App\Http\Controllers\InternalTestMediaController;
use App\Http\Controllers\PbxMediaController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/client', [\App\Http\Controllers\ClientAccountController::class, 'foundation'])->name('client.foundation');
    Route::get('/client-accounts', [\App\Http\Controllers\ClientAccountController::class, 'index'])->name('clients.accounts');
    Route::post('/client-accounts', [\App\Http\Controllers\ClientAccountController::class, 'store'])->name('clients.accounts.store');
    Route::post('/client-accounts/{account}/status', [\App\Http\Controllers\ClientAccountController::class, 'status'])->name('clients.accounts.status');
});

/*
|--------------------------------------------------------------------------
| PBX Media Delivery
|--------------------------------------------------------------------------
| Protected media endpoint for production broadcast call attempts.
| Authentication and audio authorization are enforced by the controller.
*/

Route::get(
    '/pbx-media/{attempt}/{token}/{audio}/{checksum}.wav',
    PbxMediaController::class
)
    ->whereUuid('attempt')
    ->whereUuid('audio')
    ->where('token', '[a-f0-9]{64}')
    ->where('checksum', '[a-f0-9]{64}')
    ->name('pbx.media');

/*
|--------------------------------------------------------------------------
| Internal PBX HTTP Playback Test
|--------------------------------------------------------------------------
| Temporary endpoint for testing audio delivery from Laravel to VitalPBX.
| The controller must enforce a short-lived authorization token,
| approved audio, and the authorized PBX source IP.
*/

Route::get(
    '/internal-test-media/{token}.wav',
    InternalTestMediaController::class
)
    ->where('token', '[a-f0-9]{64}')
    ->name('internal-test.media');

/*
|--------------------------------------------------------------------------
| Public and Authentication Routes
|--------------------------------------------------------------------------
*/

Route::get('/', fn() => view('welcome'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])
        ->name('login');

    Route::post('/login', [SessionController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
});

/*
|--------------------------------------------------------------------------
| Authenticated Workspace Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::post('/logout', [SessionController::class, 'destroy'])
        ->name('logout');

    Route::get('/workspace', [FoundationController::class, 'index'])
        ->name('workspace');

    Route::post('/contacts', [FoundationController::class, 'contact'])
        ->name('contacts.store');

    Route::post('/contacts/{contact}/groups', [FoundationController::class, 'group'])
        ->name('contacts.group');

    Route::post('/contacts/{contact}/consent', [FoundationController::class, 'consent'])
        ->name('contacts.consent');

    Route::post('/contacts/{contact}/suppress', [FoundationController::class, 'suppress'])
        ->name('contacts.suppress');

    Route::post('/consents/{consent}/revoke', [FoundationController::class, 'revoke'])
        ->name('consents.revoke');

    Route::post('/campaigns', [FoundationController::class, 'campaign'])
        ->name('campaigns.store');

    Route::post('/campaigns/{campaign}/edit', [FoundationController::class, 'updateCampaign'])
        ->name('campaigns.update');

    Route::post('/campaigns/{campaign}/review', [FoundationController::class, 'review'])
        ->name('campaigns.review');

    Route::get('/audio', [BroadcastController::class, 'audioLibrary'])
        ->name('audio.index');

    Route::get('/audio/{audio}/preview', [BroadcastController::class, 'previewAudio'])
        ->name('audio.preview');
});

/*
|--------------------------------------------------------------------------
| Broadcast Management Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')
    ->prefix('broadcast')
    ->name('broadcast.')
    ->group(function () {
        $c = BroadcastController::class;

        Route::get('/', [$c, 'index'])
            ->name('index');

        Route::post('/campaigns/{campaign}/configure', [$c, 'configure'])
            ->name('configure');

        Route::post('/campaigns/{campaign}/control', [$c, 'control'])
            ->name('control');

        Route::get('/campaigns/{campaign}/report', [$c, 'report'])
            ->name('report');

        Route::post('/emergency', [$c, 'emergency'])
            ->name('emergency');

        Route::post('/audio', [$c, 'upload'])
            ->name('audio.upload');

        Route::get('/audio/{audio}', [$c, 'downloadAudio'])
            ->name('audio.download');

        Route::post('/audio/{audio}/approve', [$c, 'approveAudio'])
            ->name('audio.approve');

        Route::post('/audio/{audio}/reject', [$c, 'rejectAudio'])
            ->name('audio.reject');

        Route::post('/audio/{audio}/resubmit', [$c, 'resubmitAudio'])
            ->name('audio.resubmit');

        Route::post('/audio/{audio}/deploy', [$c, 'deployAudio'])
            ->name('audio.deploy');

        Route::post('/trunks', [$c, 'trunk'])
            ->name('trunk');

        Route::post('/trunks/{trunk}/disable', [$c, 'disableTrunk'])
            ->name('trunk.disable');
    });

/*
|--------------------------------------------------------------------------
| Telephony Routes
|--------------------------------------------------------------------------
*/

Route::get('/telephony', [InteractiveController::class, 'telephony'])
    ->middleware('auth')
    ->name('telephony.index');

/*
|--------------------------------------------------------------------------
| Interactive Voice / IVR Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')
    ->prefix('interactive')
    ->name('interactive.')
    ->group(function () {
        $c = InteractiveController::class;

        Route::get('/', [$c, 'index'])
            ->name('index');

        Route::post('/caller-ids/{trunk}', [$c, 'callerIds'])
            ->name('caller_ids');

        Route::post('/capacity/{trunk}', [$c, 'capacity'])
            ->name('capacity');

        Route::post('/flows', [$c, 'save'])
            ->name('save');

        Route::post('/flows/{flow}/approve', [$c, 'approve'])
            ->name('approve');

        Route::get('/flows/{flow}/dialplan', [$c, 'download'])
            ->name('download');

        Route::post('/flows/{flow}/deploy', [$c, 'deployed'])
            ->name('deploy');

        Route::post('/routes', [$c, 'route'])
            ->name('routes');

        Route::post('/routes/{route}/disable', [$c, 'disableRoute'])
            ->name('routes.disable');
    });

/*
|--------------------------------------------------------------------------
| Dashboard and Call Monitoring Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')
    ->prefix('dashboard')
    ->name('dashboard.')
    ->group(function () {
        $c = DashboardController::class;

        Route::get('/', [$c, 'show'])
            ->defaults('tab', 'overview')
            ->name('overview');

        Route::get('/live', [$c, 'show'])
            ->defaults('tab', 'live')
            ->name('live');

        Route::get('/wallboard', [$c, 'show'])
            ->defaults('tab', 'wallboard')
            ->name('wallboard');

        Route::get('/health', [$c, 'show'])
            ->defaults('tab', 'health')
            ->name('health');

        Route::get('/calls/{attempt}', [$c, 'detail'])
            ->name('call');

        Route::post('/calls/{attempt}/stop', [$c, 'stop'])
            ->name('stop');
    });

/*
|--------------------------------------------------------------------------
| Campaign Management Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')
    ->prefix('campaigns')
    ->name('campaigns.')
    ->group(function () {
        $c = CampaignController::class;

        Route::get('/', [$c, 'index'])
            ->name('index');

        Route::get('/create', [$c, 'edit'])
            ->name('create');

        Route::post('/create', [$c, 'save'])
            ->name('save');

        Route::get('/templates', [$c, 'templates'])
            ->name('templates');

        Route::post('/templates/{template}/instantiate', [$c, 'instantiate'])
            ->name('instantiate');

        Route::get('/runs', [$c, 'runs'])
            ->name('runs');

        Route::get('/{campaign}', [$c, 'show'])
            ->whereNumber('campaign')
            ->name('show');

        Route::get('/{campaign}/edit', [$c, 'edit'])
            ->name('edit');

        Route::post('/{campaign}/save', [$c, 'save'])
            ->name('save_edit');

        Route::post('/{campaign}/submit-review', [$c, 'review'])
            ->name('module_review');

        Route::post('/{campaign}/duplicate', [$c, 'duplicate'])
            ->name('duplicate');

        Route::post('/{campaign}/archive', [$c, 'archive'])
            ->name('archive');

        Route::post('/{campaign}/control', [$c, 'control'])
            ->name('control');

        Route::post('/{campaign}/template', [$c, 'template'])
            ->name('template');
    });

/*
|--------------------------------------------------------------------------
| Contact Management Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')
    ->prefix('contacts')
    ->name('contacts.')
    ->group(function () {
        $c = ContactWorkspaceController::class;

        Route::get('/', [$c, 'directory'])
            ->name('index');

        Route::get('/groups', [$c, 'groups'])
            ->name('groups');

        Route::post('/groups', [$c, 'saveGroup'])
            ->name('groups.store');

        Route::get('/groups/{group}', [$c, 'group'])
            ->name('groups.show');

        Route::post('/groups/{group}/save', [$c, 'saveGroup'])
            ->name('groups.save');

        Route::post('/groups/{group}/members', [$c, 'membership'])
            ->name('groups.members');

        Route::get('/imports', [$c, 'imports'])
            ->name('imports');

        Route::post('/imports', [$c, 'upload'])
            ->name('imports.upload');

        Route::get('/import-template', [$c, 'template'])
            ->name('import_template');

        Route::get('/imports/{batch}', [$c, 'batch'])
            ->name('import');

        Route::post('/imports/{batch}/map', [$c, 'map'])
            ->name('import.map');

        Route::post('/imports/{batch}/confirm', [$c, 'confirm'])
            ->name('import.confirm');

        Route::get('/imports/{batch}/errors', [$c, 'errors'])
            ->name('import.errors');

        Route::get('/suppressions', [$c, 'suppressions'])
            ->name('suppressions');

        Route::post('/suppressions', [$c, 'suppress'])
            ->name('suppressions.store');

        Route::get('/consents', [$c, 'consents'])
            ->name('consents');

        Route::get('/{contact}', [$c, 'contact'])
            ->whereNumber('contact')
            ->name('show');

        Route::post('/{contact}/save', [$c, 'updateContact'])
            ->name('save');

        Route::post('/{contact}/link-group', [$c, 'linkGroup'])
            ->name('link_group');
    });
