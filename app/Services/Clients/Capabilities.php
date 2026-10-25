<?php

namespace App\Services\Clients;

use App\Models\User;
use Illuminate\Http\Request;

class Capabilities
{
    public const ROLES = ['owner', 'administrator', 'campaign_manager', 'operator', 'finance', 'viewer'];

    public static function allows(User $user, string $ability): bool
    {
        if (! $user->isClient() || ! $user->clientAccountActive()) {
            return false;
        }
        $role = $user->client_role;
        // Null retains pre-migration operational behavior, but never grants new user administration.
        if ($role === null) {
            return ! in_array($ability, ['users.manage', 'profile.manage'], true);
        }
        if (in_array($role, ['owner', 'administrator'], true)) {
            return true;
        }
        $read = ['contacts.read', 'audio.read', 'campaigns.read', 'reports.read'];
        $abilities = match ($role) {
            'campaign_manager' => [...$read, 'contacts.write', 'audio.write', 'campaigns.write', 'campaigns.operate'],
            'operator' => [...$read, 'campaigns.operate'],
            'finance' => ['reports.read', 'billing.read'],
            'viewer' => $read,
            default => [],
        };

        return in_array($ability, $abilities, true);
    }

    public static function route(Request $request): ?string
    {
        $name = $request->route()?->getName() ?? '';
        if (str_starts_with($name, 'client.team')) {
            return 'users.manage';
        }
        if (str_starts_with($name, 'client.organization')) {
            return 'profile.manage';
        }
        if (str_starts_with($name, 'client.integrations')) {
            return 'integrations.manage';
        }
        if ($name === 'client.wallet') {
            return 'billing.read';
        }
        if (str_starts_with($name, 'client.reports')) {
            return 'reports.read';
        }
        if (str_starts_with($name, 'client.audio')) {
            return $request->isMethod('GET') ? 'audio.read' : 'audio.write';
        }
        if (preg_match('/^client\.(contacts|groups|imports)/', $name)) {
            return $request->isMethod('GET') ? 'contacts.read' : 'contacts.write';
        }
        if (str_starts_with($name, 'client.campaigns')) {
            if (str_ends_with($name, '.create') || str_ends_with($name, '.edit')) {
                return 'campaigns.write';
            }
            if (str_ends_with($name, '.control') || str_ends_with($name, '.test')) {
                return 'campaigns.operate';
            }

            return $request->isMethod('GET') ? 'campaigns.read' : 'campaigns.write';
        }

        return null;
    }
}
