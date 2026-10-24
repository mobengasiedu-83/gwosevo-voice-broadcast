<?php

namespace App\Services\Clients;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ClientNotice;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Portal
{
    public function authorize(User $user): int
    {
        abort_unless($user->isClient() && $user->clientAccountActive(), 403);

        return (int) $user->client_account_id;
    }

    public function campaigns(User $user)
    {
        return Campaign::where('client_account_id', $this->authorize($user))->whereNull('archived_at')
            ->select(['campaigns.id', 'campaigns.name', 'campaigns.status', 'campaigns.scheduled_at', 'campaigns.timezone', 'campaigns.created_at'])
            ->selectSub(CampaignRecipient::whereColumn('campaign_id', 'campaigns.id')->selectRaw('count(*)'), 'audience_count')
            ->selectSub(CampaignRecipient::whereColumn('campaign_id', 'campaigns.id')->whereExists(function ($q) {
                $q->selectRaw('1')->from('call_attempts')->whereColumn('call_attempts.campaign_recipient_id', 'campaign_recipients.id')->whereNotNull('playback_completed_at');
            })->selectRaw('count(*)'), 'delivered_count');
    }

    public function notices(User $user)
    {
        $id = $this->authorize($user);

        return ClientNotice::where('client_account_id', $id)->where(fn ($q) => $q->whereNull('recipient_user_id')->orWhere('recipient_user_id', $user->id))->select('client_notices.*')
            ->selectSub(DB::table('client_notice_reads')->select('read_at')->whereColumn('client_notice_id', 'client_notices.id')->where('user_id', $user->id)->limit(1), 'read_at');
    }

    public function unread(User $user): int
    {
        return $this->notices($user)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('client_notice_reads')->whereColumn('client_notice_id', 'client_notices.id')->where('user_id', $user->id))->count();
    }

    public function summary(User $user): array
    {
        $id = $this->authorize($user);

        return [
            'contacts' => Contact::where('client_account_id', $id)->count(),
            'groups' => ContactGroup::where('client_account_id', $id)->count(),
            'active' => $this->campaigns($user)->whereIn('status', ['running', 'scheduled'])->count(),
            'ready' => $this->campaigns($user)->where('status', 'ready')->count(),
            'pending_audio' => AudioFile::where('client_account_id', $id)->where('status', 'pending')->count(),
            'approved_audio' => AudioFile::where('client_account_id', $id)->where('status', 'approved')->count(),
            // Only expose aggregate delivery, never channel identifiers, tokens or caller IDs.
            'delivered' => CallAttempt::whereIn('campaign_recipient_id', CampaignRecipient::whereIn('campaign_id', Campaign::where('client_account_id', $id)->select('id'))->select('id'))->whereNotNull('playback_completed_at')->distinct()->count('campaign_recipient_id'),
        ];
    }

    public function reviewNotice(AudioFile $audio): void
    {
        if ($audio->client_account_id === null || ! in_array($audio->status, ['approved', 'rejected'], true)) {
            return;
        }
        $at = $audio->status === 'approved' ? $audio->approved_at : $audio->rejected_at;
        if (! $at) {
            return;
        }
        $key = hash('sha256', 'audio:'.$audio->id.':'.$audio->status.':'.$at->format('Y-m-d H:i:s.u'));
        ClientNotice::firstOrCreate(['event_key' => $key], [
            'client_account_id' => $audio->client_account_id, 'kind' => 'audio.'.$audio->status,
            'title' => 'Audio '.$audio->status,
            'message' => $audio->name.($audio->status === 'approved' ? ' has been approved. Linked draft campaigns can now become ready; launch requirements still apply.' : ' was rejected. Reason: '.($audio->rejection_reason ?: 'Contact support for details.')),
            'occurred_at' => $at,
        ]);
    }

    public function markRead(User $user, int $notice): void
    {
        $record = $this->notices($user)->whereKey($notice)->firstOrFail();
        DB::table('client_notice_reads')->insertOrIgnore(['client_notice_id' => $record->id, 'user_id' => $user->id, 'read_at' => now()]);
    }

    public function markAllRead(User $user): void
    {
        $this->notices($user)->select('client_notices.id')->reorder()->chunkById(250, function ($notices) use ($user) {
            DB::table('client_notice_reads')->insertOrIgnore($notices->map(fn ($n) => ['client_notice_id' => $n->id, 'user_id' => $user->id, 'read_at' => now()])->all());
        }, 'client_notices.id', 'id');
    }
}
