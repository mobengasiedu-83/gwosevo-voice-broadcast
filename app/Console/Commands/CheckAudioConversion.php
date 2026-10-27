<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

class CheckAudioConversion extends Command
{
    protected $signature = 'audio:conversion-check';

    protected $description = 'Check FFmpeg and FFprobe availability for audio conversion';

    public function handle(): int
    {
        $available = true;
        foreach (['ffmpeg', 'ffprobe'] as $tool) {
            try {
                $process = new Process([config('voice_broadcast.'.$tool), '-version']);
                $process->setTimeout(5);
                $process->mustRun();
                $this->info(strtok($process->getOutput(), "\n"));
            } catch (Throwable $e) {
                $this->error($tool.' is unavailable. Configure '.strtoupper($tool).'_BINARY with its executable path.');
                $available = false;
            }
        }
        $this->line('Queue connection: '.config('queue.default').'. Keep an audio-conversion queue worker running for asynchronous conversion.');

        return $available ? self::SUCCESS : self::FAILURE;
    }
}
