<?php

namespace App\Http\Controllers;

use App\Models\ClientAccount;
use App\Services\Clients\Accounts;
use App\Services\Clients\Management;
use Illuminate\Http\Request;

class ClientAccountController extends Controller
{
    public function index(Request $request)
    {
        app(Management::class)->staff($request->user());
        $filters = app(ClientManagementController::class)->filters($request);

        return view('clients.accounts', ['filters' => $filters, 'accounts' => app(Management::class)->directory($filters)->latest()->paginate(25)->withQueryString()]);
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
}
