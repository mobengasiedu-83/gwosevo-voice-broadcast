<?php

namespace App\Http\Controllers;

use App\Models\AudioFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class InternalTestMediaController extends Controller
{
    public function __invoke(Request $request, string $token)
    {
        abort_unless(
            $request->ip() === '173.16.254.254',
            404
        );

        abort_unless(
            preg_match('/\A[a-f0-9]{64}\z/', $token),
            404
        );

        $key = 'broadcast-internal-test:' . $token;
        $authorization = Cache::get($key);

        abort_unless(
            is_array($authorization)
                && ($authorization['audio_id'] ?? null) === 1
                && ($authorization['extension'] ?? null) === '208',
            404
        );

        $audio = AudioFile::findOrFail(1);

        abort_unless($audio->status === 'approved', 404);

        $disk = Storage::disk('local');

        abort_unless($disk->exists($audio->storage_key), 404);

        $path = $disk->path($audio->storage_key);

        abort_unless(
            hash_equals(
                $audio->checksum,
                hash_file('sha256', $path)
            ),
            404
        );

        return response()->file($path, [
            'Content-Type' => 'audio/wav',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
