<?php

namespace App\Http\Controllers;

use App\Models\ClientAccount;
use App\Models\RateVersion;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Billing\Ledger;
use App\Services\Clients\Portal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingController extends Controller
{
    public function client(Request $request, Portal $portal, Ledger $ledger)
    {
        $id = $portal->authorize($request->user());
        $wallet = Wallet::where('client_account_id', $id)->first();

        return view('clients.wallet', [
            'account' => $request->user()->clientAccount()->firstOrFail(), 'unread' => $portal->unread($request->user()),
            'wallet' => $wallet, 'rate' => $wallet ? RateVersion::find($wallet->rate_version_id) : null,
            'entries' => $wallet ? WalletLedgerEntry::where('wallet_id', $wallet->id)->latest('id')->paginate(25) : null,
            'billingEnabled' => (bool) config('billing.enabled'),
        ]);
    }

    public function index(Request $request)
    {
        Gate::authorize('billing.view');

        $selection = $request->validate(['version' => 'nullable|integer|exists:rate_versions,id']);
        $selectedRate = empty($selection['version']) ? RateVersion::latest('id')->first() : RateVersion::findOrFail($selection['version']);
        $accounts = ClientAccount::orderBy('name')->paginate(25);

        return response()->view('billing.index', [
            'selectedRate' => $selectedRate,
            'accounts' => $accounts,
            'wallets' => Wallet::whereIn('client_account_id', $accounts->pluck('id'))->get()->keyBy('client_account_id'),
            'rates' => RateVersion::latest('id')->get(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Wallet $wallet, Ledger $ledger)
    {
        Gate::authorize('billing.view');

        return response()->view('billing.wallet', [
            'wallet' => $wallet, 'account' => ClientAccount::findOrFail($wallet->client_account_id),
            'entries' => WalletLedgerEntry::where('wallet_id', $wallet->id)->latest('id')->paginate(25),
            'reservations' => DB::table('wallet_reservations')->where('wallet_id', $wallet->id)->orderByDesc('id')->paginate(25, ['*'], 'reservations_page'),
            'check' => $ledger->reconciliation($wallet), 'requestKey' => (string) Str::uuid(),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function amount(string $decimal, int $digits): int
    {
        if (! preg_match('/\A(-?)([0-9]{1,10})(?:\.([0-9]{1,6}))?\z/', $decimal, $m) || strlen($m[3] ?? '') > $digits) {
            throw ValidationException::withMessages(['amount' => 'Enter an exact decimal amount within the wallet precision.']);
        }
        $amount = ((int) $m[2] * 10 ** $digits) + (int) str_pad($m[3] ?? '', $digits, '0');

        return ($m[1] === '-' ? -1 : 1) * $amount;
    }

    public function price(Request $request, Ledger $ledger)
    {
        Gate::authorize('billing.manage');
        $data = $request->validate(['plan_code' => 'required|string|max:80', 'amount' => 'required|string|max:20', 'reason' => 'required|string|max:2000', 'currency' => 'sometimes|required|regex:/\A[A-Z]{3}\z/', 'minor_digits' => 'sometimes|required|integer|min:0|max:6', 'duration_basis' => 'sometimes|required|in:recording,answered', 'increment_seconds' => 'sometimes|required|integer|in:1,6,30,60', 'playback_failure_policy' => 'sometimes|required|in:charge,waive,review', 'reservation_seconds' => 'sometimes|required|integer|min:1|max:86400']);
        $ledger->price($request->user(), collect($data)->except(['amount', 'reason'])->all() + ['currency' => $data['currency'] ?? 'GHS', 'minor_digits' => $data['minor_digits'] ?? 3, 'rate_minor_per_minute' => $this->amount($data['amount'], (int) ($data['minor_digits'] ?? 3)), 'evidence' => $data['reason']]);

        return redirect()->route('billing.index')->with('status', 'Immutable price version created. Assign it to an account for future launches.');
    }

    public function assign(Request $request, ClientAccount $account, Ledger $ledger)
    {
        Gate::authorize('billing.manage');
        $data = $request->validate(['rate_version_id' => 'required|integer|exists:rate_versions,id', 'reason' => 'required|string|max:2000']);
        $wallet = $ledger->assign($request->user(), $account, RateVersion::findOrFail($data['rate_version_id']), $data['reason']);

        return redirect()->route('billing.wallet', $wallet)->with('status', 'Price assigned for future launches. Existing execution prices are unchanged.');
    }

    public function adjustment(Request $request, Wallet $wallet, Ledger $ledger)
    {
        Gate::authorize('billing.manage');
        $data = $request->validate(['amount' => 'required|string|max:20', 'reference' => 'required|string|max:100', 'reason' => 'required|string|max:2000', 'refund_entry_id' => 'nullable|integer']);
        $ledger->adjust($request->user(), $wallet, $this->amount($data['amount'], (int) $wallet->minor_digits), $data['reference'], $data['reason'], $data['refund_entry_id'] ?? null);

        return redirect()->route('billing.wallet', $wallet)->with('status', 'Ledger adjustment recorded.');
    }

    public function resolve(Request $request, Wallet $wallet, int $reservation, Ledger $ledger)
    {
        Gate::authorize('billing.manage');
        abort_unless(DB::table('wallet_reservations')->where('wallet_id', $wallet->id)->where('id', $reservation)->exists(), 404);
        $data = $request->validate(['decision' => 'required|in:charge,release', 'evidence' => 'required|string|max:2000', 'verified_seconds' => 'nullable|integer|min:0|max:86400']);
        $ledger->resolve($request->user(), $reservation, $data['decision'], $data['evidence'], isset($data['verified_seconds']) ? (int) $data['verified_seconds'] : null);

        return redirect()->route('billing.wallet', $wallet)->with('status', 'Billing review resolved and audited.');
    }
}
