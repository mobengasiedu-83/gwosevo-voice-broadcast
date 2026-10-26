<?php

namespace App\Services\Clients;

use App\Models\AudioFile;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\ContactImport;
use App\Models\User;

class Notices
{
    // Only explicitly safe account events enter the client inbox. Audit details stay internal.
    private const EVENTS = [
        'audio.uploaded' => ['Recording awaiting review', AudioFile::class, 'audio.index'],
        'audio.approved' => ['Recording approved', AudioFile::class, 'audio.index'],
        'audio.rejected' => ['Recording rejected — review the reason in Recordings', AudioFile::class, 'audio.index'],
        'audio.resubmitted' => ['Recording resubmitted for review', AudioFile::class, 'audio.index'],
        'campaign.audio_ready' => ['Campaign recording is ready', Campaign::class, 'campaigns.show'],
        'broadcast.start' => ['Campaign started', Campaign::class, 'campaigns.show'],
        'broadcast.schedule' => ['Campaign scheduled', Campaign::class, 'campaigns.show'],
        'broadcast.pause' => ['Campaign paused', Campaign::class, 'campaigns.show'],
        'broadcast.resume' => ['Campaign resumed', Campaign::class, 'campaigns.show'],
        'broadcast.cancel' => ['Campaign cancelled', Campaign::class, 'campaigns.show'],
        'contacts.import_completed' => ['Contact import completed', ContactImport::class, 'contacts.import'],
        'contacts.import_failed' => ['Contact import failed — review and resume', ContactImport::class, 'contacts.import'],
        'contacts.import_blocked' => ['Contact import blocked — review group and account access', ContactImport::class, 'contacts.import'],
    ];

    private function query(User $user)
    {
        $tenancy = app(Tenancy::class);
        $clientId = $tenancy->clientId($user);
        abort_unless($clientId !== null && $tenancy->usable($user), 403);

        return AuditLog::where('client_id', $clientId)->where(function ($query) {
            foreach (self::EVENTS as $action => [$title, $model, $route]) {
                $query->orWhere(fn ($q) => $q->where('action', $action)->where('entity_type', (new $model)->getMorphClass()));
            }
        });
    }

    public function summary(User $user): array
    {
        $query = $this->query($user);
        $latest = (clone $query)->latest('id')->limit(8)->get();

        return [
            'unread' => (clone $query)->where('id', '>', $user->notice_read_through_id ?? 0)->count(),
            'latest_id' => (int) ((clone $query)->max('id') ?? 0),
            'items' => $latest->map(fn ($event) => $this->item($event, $user)),
        ];
    }

    public function page(User $user)
    {
        $events = $this->query($user)->latest('id')->paginate(25);

        return $events->through(fn ($event) => $this->item($event, $user));
    }

    public function visibleThrough(User $user, int $id): bool
    {
        return $this->query($user)->whereKey($id)->exists();
    }

    private function item(AuditLog $event, User $user): array
    {
        [$title, $model, $route] = self::EVENTS[$event->action];
        // Do not generate a link to a reassigned, removed or otherwise hidden subject.
        $subject = $model::where('client_id', $event->client_id)->whereKey($event->entity_id)->exists();

        return [
            'id' => $event->id, 'title' => $title, 'created_at' => $event->created_at,
            'unread' => $event->id > ($user->notice_read_through_id ?? 0),
            'url' => $subject ? route($route, $model === AudioFile::class ? [] : [$event->entity_id]) : null,
        ];
    }
}
