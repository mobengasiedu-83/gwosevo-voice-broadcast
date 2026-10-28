<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_attempts', fn (Blueprint $t) => $t->unsignedBigInteger('recording_duration_ms')->nullable());
        // Older approved versions lack a measurement. Recover it only from the
        // exact recording checksum they approved, then freeze it per attempt.
        DB::table('call_attempts')->orderBy('id')->chunkById(200, function ($attempts) {
            foreach ($attempts as $attempt) {
                $version = DB::table('campaign_versions')->where('id', $attempt->campaign_version_id)->first();
                $snapshot = json_decode($version?->snapshot ?? '{}', true)['broadcast'] ?? [];
                $ms = $snapshot['audio_duration_ms'] ?? null;
                if ($ms === null) {
                    $audio = DB::table('audio_files')->where('id', $snapshot['audio_id'] ?? 0)->first();
                    if ($audio && ! empty($snapshot['checksum']) && hash_equals($snapshot['checksum'], (string) $audio->checksum) && $audio->duration > 0) {
                        $ms = (int) ceil($audio->duration * 1000);
                    }
                }
                if (is_numeric($ms) && $ms > 0 && $ms <= 86400000) {
                    DB::table('call_attempts')->where('id', $attempt->id)->update(['recording_duration_ms' => (int) $ms]);
                }
            }
        });
    }

    public function down(): void
    {
        throw new LogicException('Recording price snapshots are financial history and must be retained.');
    }
};
