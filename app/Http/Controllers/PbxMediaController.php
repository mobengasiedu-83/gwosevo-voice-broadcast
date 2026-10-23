<?php

namespace App\Http\Controllers;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\CampaignVersion;
use App\Services\Broadcast\MediaDelivery;
use Illuminate\Support\Facades\Storage;

class PbxMediaController extends Controller
{
    public function __invoke(string $attempt, string $token, string $audio, string $checksum)
    {
        abort_unless(app(MediaDelivery::class)->remote(), 404);
        $call = CallAttempt::where('uuid', $attempt)->first();
        abort_unless($call && $call->token && hash_equals($call->token, $token) && $call->sent_at && ! $call->ended_at && $call->sent_at->gt(now()->subMinutes(15)), 404);
        $snapshot = CampaignVersion::findOrFail($call->campaign_version_id)->snapshot['broadcast'];
        $assets = array_merge([['uuid' => $snapshot['audio_uuid'], 'checksum' => $snapshot['checksum']]], $snapshot['ivr']['assets'] ?? []);
        abort_unless(collect($assets)->contains(fn ($asset) => $asset['uuid'] === $audio && hash_equals($asset['checksum'], $checksum)), 404);
        $asset = AudioFile::where('uuid', $audio)->where('status', 'approved')->firstOrFail();
        $disk = Storage::disk('local');
        abort_unless($disk->exists($asset->storage_key) && hash_equals($checksum, $asset->checksum) && hash_equals($checksum, hash_file('sha256', $disk->path($asset->storage_key))), 404);

        return response()->file($disk->path($asset->storage_key), [
            'Content-Type' => 'audio/wav',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
