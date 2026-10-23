<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function create()
    {
        return view('foundation.login');
    }

    public function store(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($data)) {
            throw ValidationException::withMessages(['email' => 'The supplied credentials are invalid.']);
        }
        if (Auth::user()->isClient() && ! Auth::user()->clientAccountActive()) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'The supplied credentials are invalid or the account is unavailable.']);
        }
        $request->session()->regenerate();
        if (Auth::user()->isClient()) {
            $request->session()->forget('url.intended');

            return redirect()->route('client.foundation');
        }

        return redirect()->intended('/dashboard');
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
