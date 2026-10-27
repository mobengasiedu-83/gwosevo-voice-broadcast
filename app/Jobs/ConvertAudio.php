<?php

namespace App\Jobs;

use App\Services\Broadcast\AudioConversion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ConvertAudio implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public bool $failOnTimeout = true;

    public function __construct(public int $audioId)
    {
        $this->onQueue('audio-conversion');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('audio-convert-'.$this->audioId))->dontRelease()->expireAfter(90)];
    }

    public function handle(AudioConversion $conversion): void
    {
        $conversion->convert($this->audioId);
    }

    public function failed(?Throwable $exception): void
    {
        app(AudioConversion::class)->fail($this->audioId, 'Audio processing was interrupted. Please upload the recording again.');
    }
}
