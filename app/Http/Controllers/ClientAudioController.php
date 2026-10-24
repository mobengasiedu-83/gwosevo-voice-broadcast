<?php

namespace App\Http\Controllers;

use App\Models\AudioFile;
use App\Services\Broadcast\AudioLibrary;
use App\Services\Clients\Portal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClientAudioController extends Controller
{
    private function access(Request $request, AudioFile $audio): void
    {
        $id = app(Portal::class)->authorize($request->user());
        abort_unless((int) $audio->client_account_id === $id, 404);
    }

    public function index(Request $request, Portal $portal)
    {
        $id = $portal->authorize($request->user());
        $data = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:pending,approved,rejected']);
        $base = AudioFile::where('client_account_id', $id)->whereNotNull('uuid');
        $counts = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        if (! empty($data['q'])) {
            $base->where('name', 'like', '%'.addcslashes($data['q'], '%_\\').'%');
        }
        if (! empty($data['status'])) {
            $base->where('status', $data['status']);
        }

        return view('clients.audio', [
            'account' => $request->user()->clientAccount()->firstOrFail(), 'unread' => $portal->unread($request->user()),
            'counts' => $counts, 'audio' => $base->latest()->paginate(15, ['id', 'uuid', 'name', 'status', 'duration', 'created_at', 'approved_at', 'rejected_at', 'rejection_reason'])->withQueryString(),
            'maxBytes' => config('voice_broadcast.max_audio_bytes'), 'maxSeconds' => config('voice_broadcast.max_audio_seconds'),
        ]);
    }

    public function upload(Request $request, AudioLibrary $library, Portal $portal)
    {
        $portal->authorize($request->user());
        $data = $request->validate(['name' => 'required|string|max:255', 'audio' => 'required|file|max:'.(int) ceil(config('voice_broadcast.max_audio_bytes') / 1024)]);
        $library->upload($request->user(), $request->file('audio'), $data['name']);

        return redirect()->route('client.audio')->with('status', 'Recording uploaded and pending internal approval.');
    }

    public function resubmit(Request $request, AudioFile $audio, AudioLibrary $library)
    {
        $this->access($request, $audio);
        $library->resubmit($request->user(), $audio);

        return redirect()->route('client.audio')->with('status', 'Recording resubmitted for internal review.');
    }

    private function file(Request $request, AudioFile $audio): string
    {
        $this->access($request, $audio);
        abort_unless($audio->storage_key && Storage::disk('local')->exists($audio->storage_key), 404, 'Recording unavailable. Contact support.');
        $path = Storage::disk('local')->path($audio->storage_key);
        abort_unless($audio->checksum && hash_equals($audio->checksum, hash_file('sha256', $path)), 409, 'Recording integrity check failed. Contact support.');

        return $path;
    }

    public function preview(Request $request, AudioFile $audio)
    {
        return response()->file($this->file($request, $audio), [
            'Cache-Control' => 'private, no-store', 'Content-Type' => 'audio/wav',
            'Content-Disposition' => 'inline; filename="'.$audio->uuid.'.wav"', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function download(Request $request, AudioFile $audio)
    {
        return response()->download($this->file($request, $audio), $audio->uuid.'.wav', ['Cache-Control' => 'private, no-store', 'Content-Type' => 'audio/wav', 'X-Content-Type-Options' => 'nosniff']);
    }
}
