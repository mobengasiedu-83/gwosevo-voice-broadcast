
<?php

use App\Http\Controllers\BillingDisputeController;
use App\Http\Controllers\BroadcastController;
use App\Http\Controllers\CallRatingController;
use App\Http\Controllers\ClientDisputeController;
use App\Http\Controllers\ClientWalletStatementController;
use App\Http\Controllers\FinanceReportController;
use App\Http\Controllers\RatePlanController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ClientAccountController;
use App\Http\Controllers\ClientAdminController;
use App\Http\Controllers\ClientNoticeController;
use App\Http\Controllers\ClientWalletController;
use App\Http\Controllers\ContactWorkspaceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FoundationController;
use App\Http\Controllers\IdentityController;
use App\Http\Controllers\InteractiveController;
use App\Http\Controllers\InternalTestMediaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PbxMediaController;
use App\Http\Controllers\PaymentAdminController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\SessionController;
use App\Http\Middleware\VerifyTurnstile;
use Illuminate\Support\Facades\Route;

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
| Payment Provider Callbacks
|--------------------------------------------------------------------------
| No session or CSRF token: callers are authenticated by an HMAC signature and
| replay window. Every payload is re-verified before any state changes.
*/

Route::post('/payments/webhook/{provider}', PaymentWebhookController::class)
    ->where('provider', '[a-z0-9_-]{1,32}')
    ->middleware('throttle:120,1')
    ->name('payments.webhook');

/*
|--------------------------------------------------------------------------
| Public and Authentication Routes
|--------------------------------------------------------------------------
*/

Route::get('/', fn () => view('welcome'));
Route::get('/registration-notice', fn () => view('auth.terms'))->name('terms');

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])
        ->name('login');

    Route::post('/login', [SessionController::class, 'store'])
        ->middleware(['throttle:5,1', VerifyTurnstile::class.':login'])
        ->name('login.store');

    Route::get('/register', [IdentityController::class, 'register'])->name('register');
    Route::post('/register', [IdentityController::class, 'startRegistration'])->middleware(['throttle:5,1', VerifyTurnstile::class.':register'])->name('register.store');
    Route::get('/forgot-password', [IdentityController::class, 'recovery'])->name('password.request');
    Route::post('/forgot-password', [IdentityController::class, 'startRecovery'])->middleware(['throttle:5,1', VerifyTurnstile::class.':recovery'])->name('password.email');
});

Route::get('/verify-contact', [IdentityController::class, 'verification'])->name('identity.verify');
Route::post('/verify-contact', [IdentityController::class, 'verify'])->middleware(['throttle:10,1', VerifyTurnstile::class.':verify'])->name('identity.verify.store');
Route::post('/verify-contact/resend', [IdentityController::class, 'resend'])->middleware(['throttle:3,1', VerifyTurnstile::class.':resend'])->name('identity.resend');
Route::get('/set-password', [IdentityController::class, 'password'])->name('identity.password');
Route::post('/set-password', [IdentityController::class, 'setPassword'])->middleware(['throttle:5,1', VerifyTurnstile::class.':password'])->name('identity.password.store');

/*
|--------------------------------------------------------------------------
| Authenticated Workspace Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::get('/account/guide', \App\Http\Controllers\ClientGuideController::class)->name('account.guide');
    Route::get('/account/security', [IdentityController::class, 'security'])->name('account.security');
    Route::post('/account/security/contact', [IdentityController::class, 'changeContact'])->middleware(['throttle:5,1', VerifyTurnstile::class.':identity'])->name('account.contact');
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

        Route::get('/campaigns/{campaign}/report.csv', [$c, 'exportReport'])
            ->name('report.export');

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

Route::get('/wallet', ClientWalletController::class)
    ->middleware('auth')
    ->name('wallet.index');
Route::get('/wallet/statement', ClientWalletStatementController::class)
    ->middleware('auth')
    ->name('wallet.statement');
Route::middleware('auth')->prefix('wallet/disputes')->name('wallet.disputes.')->group(function () {
    Route::get('/', [ClientDisputeController::class, 'index'])->name('index');
    Route::post('/', [ClientDisputeController::class, 'store'])->middleware('throttle:5,1')->name('store');
});

Route::middleware('auth')->prefix('wallet/payments')->name('wallet.payments.')->group(function () {
    $payments = PaymentController::class;
    Route::get('/', [$payments, 'index'])->name('index');
    Route::post('/', [$payments, 'store'])->middleware('throttle:10,1')->name('store');
    Route::get('/{payment}', [$payments, 'show'])->whereUuid('payment')->name('show');
    Route::get('/{payment}/receipt', [$payments, 'receipt'])->whereUuid('payment')->name('receipt');
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

        Route::get('/estimate', [$c, 'estimate'])
            ->name('estimate');

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

Route::middleware('auth')->prefix('clients')->name('clients.')->group(function () {
    $c = ClientAdminController::class;
    Route::get('/', [$c, 'index'])->name('index');
    Route::post('/', [$c, 'store'])->name('store');
    Route::get('/{client}', [$c, 'show'])->whereNumber('client')->name('show');
    Route::post('/{client}/profile', [$c, 'profile'])->name('profile');
    Route::post('/{client}/status', [$c, 'status'])->name('status');
    Route::post('/{client}/users', [$c, 'createUser'])->name('users.store');
    Route::post('/{client}/assign', [$c, 'assign'])->name('assign');
    Route::post('/{client}/memberships/{membership}', [$c, 'membership'])->name('membership');
});

Route::middleware('auth')->prefix('account')->name('account.')->group(function () {
    $account = ClientAccountController::class;
    Route::get('/profile', [$account, 'profile'])->name('profile');
    Route::post('/profile', [$account, 'saveProfile'])->name('profile.save');
    Route::post('/preferences', [$account, 'preferences'])->name('preferences');
    Route::post('/security', [$account, 'security'])->middleware(['throttle:5,1', VerifyTurnstile::class.':identity'])->name('security.save');
    Route::get('/notifications', [ClientNoticeController::class, 'index'])->name('notifications');
    Route::post('/notifications/read', [ClientNoticeController::class, 'read'])->name('notifications.read');
    Route::post('/notifications/alerts/read', [ClientNoticeController::class, 'readAlerts'])->name('notifications.alerts.read');
});

Route::middleware('auth')->prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::post('/read', [NotificationController::class, 'read'])->name('read');
});

Route::get('/billing/reconciliation', [CallRatingController::class, 'index'])->middleware('auth')->name('billing.exceptions');

Route::middleware('auth')->prefix('billing/wallets')->name('billing.')->group(function () {
    $wallets = WalletController::class;
    Route::get('/', [$wallets, 'index'])->name('wallets');
    Route::get('/{client}', [$wallets, 'show'])->whereNumber('client')->name('wallets.show');
    Route::get('/{client}/statement', [$wallets, 'statement'])->whereNumber('client')->name('wallets.statement');
    Route::post('/{client}/open', [$wallets, 'open'])->whereNumber('client')->name('wallets.open');
    Route::post('/{client}/credit', [$wallets, 'credit'])->whereNumber('client')->middleware('throttle:20,1')->name('wallets.credit');
    Route::post('/{client}/entries', [$wallets, 'post'])->whereNumber('client')->name('wallets.post');
    Route::post('/{client}/entries/{entry}/reverse', [$wallets, 'reverse'])->whereNumber(['client', 'entry'])->name('wallets.reverse');
});

Route::middleware('auth')->prefix('billing/rates')->name('billing.')->group(function () {
    $rates = RatePlanController::class;
    Route::get('/', [$rates, 'index'])->name('rates');
    Route::post('/', [$rates, 'store'])->name('rates.store');
    Route::post('/assign', [$rates, 'assign'])->name('rates.assign');
});

Route::middleware('auth')->prefix('billing/finance')->name('billing.')->group(function () {
    $finance = FinanceReportController::class;
    Route::get('/', [$finance, 'index'])->name('finance');
    Route::get('/export', [$finance, 'export'])->middleware('throttle:20,1')->name('finance.export');
});

Route::middleware('auth')->prefix('billing/disputes')->name('billing.')->group(function () {
    $disputes = BillingDisputeController::class;
    Route::get('/', [$disputes, 'index'])->name('disputes');
    Route::post('/', [$disputes, 'store'])->middleware('throttle:10,1')->name('disputes.store');
    Route::post('/{dispute}/resolve', [$disputes, 'resolve'])->whereUuid('dispute')->middleware('throttle:10,1')->name('disputes.resolve');
});

Route::middleware('auth')->prefix('billing/payments')->name('billing.')->group(function () {
    $payments = PaymentAdminController::class;
    Route::get('/', [$payments, 'index'])->name('payments');
    Route::post('/reconcile', [$payments, 'reconcile'])->middleware('throttle:10,1')->name('payments.reconcile');
    Route::get('/{payment}', [$payments, 'show'])->whereUuid('payment')->name('payments.show');
    Route::post('/{payment}/refund', [$payments, 'refund'])->middleware('throttle:10,1')->name('payments.refund');
});


Route::middleware('auth')->prefix('administration/staff')->name('staff.')->group(function () {
    Route::get('/', [\App\Http\Controllers\StaffUserController::class, 'index'])->name('index');
    Route::post('/', [\App\Http\Controllers\StaffUserController::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::post('/{user}/role', [\App\Http\Controllers\StaffUserController::class, 'updateRole'])->whereNumber('user')->middleware('throttle:20,1')->name('role');
});

Route::middleware('auth')->prefix('administration/roles')->name('roles.')->group(function () {
    Route::get('/', [\App\Http\Controllers\RoleManagementController::class, 'index'])->name('index');
    Route::post('/', [\App\Http\Controllers\RoleManagementController::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::put('/{role}', [\App\Http\Controllers\RoleManagementController::class, 'update'])->whereNumber('role')->middleware('throttle:20,1')->name('update');
    Route::delete('/{role}', [\App\Http\Controllers\RoleManagementController::class, 'destroy'])->whereNumber('role')->middleware('throttle:20,1')->name('destroy');
});
