<?php

namespace App\Http\Controllers;

use App\Models\ClientAccount;
use App\Services\Clients\Accounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientAccountController extends Controller
{
    public function index()
    {
        Gate::authorize('clients.manage');

        return view('clients.accounts', ['accounts' => ClientAccount::withCount('users')->latest()->paginate(25)]);
    }

    public function store(Request $request, Accounts $accounts)
    {
        $data = $request->validate(['name' => 'required|string|max:255']);
        $accounts->create($request->user(), $data['name']);

        return redirect()->route('clients.accounts')->with('status', 'Client account created.');
    }

    public function status(Request $request, ClientAccount $account, Accounts $accounts)
    {
        $data = $request->validate(['status' => 'required|in:active,suspended', 'reason' => 'required|string|max:2000']);
        $accounts->setStatus($request->user(), $account, $data['status'], $data['reason']);

        return redirect()->route('clients.accounts')->with('status', 'Account status updated. Reactivation does not resume campaigns automatically.');
    }

    public function foundation(Request $request)
    {
        abort_unless($request->user()->isClient(), 403);

        return view('clients.foundation', ['account' => $request->user()->clientAccount()->firstOrFail()]);
    }
}
