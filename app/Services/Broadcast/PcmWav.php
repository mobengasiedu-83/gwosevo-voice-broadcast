<?php

namespace App\Services\Broadcast;

class PcmWav
{
    /** Validate decoded PCM and remove metadata before approval/checksumming. */
    public function read(string $path): array
    {
        $ready = app(Readiness::class);
        if (! is_file($path) || filesize($path) > config('voice_broadcast.max_audio_bytes')) {
            $ready->fail('Audio exceeds the size limit.');
        }
        $bytes = file_get_contents($path);
        if (strlen($bytes) < 44 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE' || unpack('V', substr($bytes, 4, 4))[1] + 8 !== strlen($bytes)) {
            $ready->fail('Invalid WAV container.');
        }
        $fmt = null;
        $data = null;
        $offset = 12;
        while ($offset + 8 <= strlen($bytes)) {
            $id = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
            $offset += 8;
            if ($size > strlen($bytes) - $offset) {
                $ready->fail('Truncated WAV data.');
            }
            if ($id === 'fmt ') {
                if ($fmt !== null) {
                    $ready->fail('Duplicate WAV format.');
                } $fmt = substr($bytes, $offset, $size);
            }
            if ($id === 'data') {
                if ($data !== null) {
                    $ready->fail('Duplicate WAV audio data.');
                } $data = substr($bytes, $offset, $size);
            }
            $offset += $size + ($size % 2);
        }
        if ($offset !== strlen($bytes) || $fmt === null || strlen($fmt) < 16 || $data === null || strlen($data) === 0 || strlen($data) % 2) {
            $ready->fail('WAV requires complete format and audio chunks.');
        }
        $f = unpack('vformat/vchannels/Vrate/Vbyterate/valign/vbits', substr($fmt, 0, 16));
        if ($f !== ['format' => 1, 'channels' => 1, 'rate' => 8000, 'byterate' => 16000, 'align' => 2, 'bits' => 16]) {
            $ready->fail('Audio must be 8 kHz mono 16-bit PCM WAV. Convert it before upload.');
        }
        $duration = strlen($data) / 16000;
        if ($duration > config('voice_broadcast.max_audio_seconds')) {
            $ready->fail('Audio exceeds the duration limit.');
        }
        // Canonicalize: discard metadata/unknown chunks; only verified PCM bytes enter the PBX artifact.
        $canonical = 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('V', 16).substr($fmt, 0, 16).'data'.pack('V', strlen($data)).$data;

        return ['bytes' => $canonical, 'duration' => $duration];
    }
}
