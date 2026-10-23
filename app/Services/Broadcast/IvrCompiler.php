<?php

namespace App\Services\Broadcast;

use App\Models\IvrFlow;

class IvrCompiler
{
    public function compile(IvrFlow $flow): string
    {
        $verified = app(IvrDesigner::class)->validate($flow->graph);
        $context = 'gwosevo-ivr-'.str_replace('-', '', $flow->uuid);
        $out = '; GWO SEVO immutable IVR '.$flow->uuid.' SHA256 '.$flow->checksum."\n[{$context}]\n";
        $base = file_get_contents(base_path('deploy/asterisk/gwosevo-broadcast.conf'));
        $start = strpos($base, 'exten => s,1,');
        $end = strpos($base, ' same => n,UserEvent(VBPlaybackStart');
        $auth = substr($base, $start, $end - $start);
        // Interactive flows validate all referenced recordings, not just the opening recording.
        foreach ($verified['assets'] as $asset) {
            $auth .= ' same => n,GotoIf($["${DB(vb-audio/'.$asset['uuid'].')}" != "'.$asset['checksum'].'"]?failed)'."\n";
            $auth .= ' same => n,GotoIf($[${STAT(e,${ASTDATADIR}/sounds/gwosevo/'.$asset['uuid'].'.wav)} != 1]?failed)'."\n";
        }
        $out .= $auth.' same => n,Goto('.$flow->graph['start'].",1)\n";
        $out .= ' same => n(failed),UserEvent(VBPlaybackFailed,AttemptID:${VB_ATTEMPT_ID})'."\n same => n,Hangup()\n same => n(denied),Hangup(21)\n";
        $assets = array_column($verified['assets'], null, 'id');
        $routes = array_column($verified['routes'], null, 'id');
        foreach ($flow->graph['nodes'] as $n) {
            $id = $n['id'];
            $out .= "\nexten => {$id},1,NoOp(IVR node {$id})\n";
            $type = $n['type'];
            if (in_array($type, ['playback', 'retry_prompt'], true)) {
                $out .= ' same => n,UserEvent(VBPlaybackStart,AttemptID:${VB_ATTEMPT_ID},NodeID:'.$id.")\n";
                $out .= ' same => n,Playback(gwosevo/'.$assets[$n['audio_id']]['uuid'].")\n";
                $out .= ' same => n,GotoIf($["${PLAYBACKSTATUS}" != "SUCCESS"]?s,failed)'."\n";
                $out .= ' same => n,UserEvent(VBPromptComplete,AttemptID:${VB_ATTEMPT_ID},NodeID:'.$id.")\n";
            }
            if (in_array($type, ['input', 'confirmation', 'condition'], true)) {
                if ($type !== 'condition') {
                    $out .= ' same => n,Read(VB_DIGIT,gwosevo/'.$assets[$n['audio_id']]['uuid'].',1,e,1,'.($n['timeout_seconds'] ?? 5).")\n";
                }
                if ($type !== 'condition') {
                    $out .= ' same => n,GotoIf($["${READSTATUS}" = "ERROR"]?s,failed)'."\n";
                }
                $out .= ' same => n,GotoIf($["${VB_DIGIT}" = ""]?'.$n['timeout'].",1)\n";
                foreach ($n['branches'] as $digit => $target) {
                    $out .= ' same => n,GotoIf($["${VB_DIGIT}" = "'.$digit.'"]?'.$id.'-digit-'.array_search($digit, array_keys($n['branches']), true).",1)\n";
                }
                $out .= ' same => n,Goto('.$n['invalid'].",1)\n";
                $i = 0;
                foreach ($n['branches'] as $digit => $target) {
                    $out .= 'exten => '.$id.'-digit-'.$i.',1,UserEvent(VBDigit,AttemptID:${VB_ATTEMPT_ID},NodeID:'.$id.',Digit:'.$digit.")\n same => n,Goto(".$target.",1)\n";
                    $i++;
                }

                continue;
            }
            if ($type === 'opt_out') {
                $out .= ' same => n,UserEvent(VBOptOut,AttemptID:${VB_ATTEMPT_ID},NodeID:'.$id.")\n same => n,Hangup()\n";
            } elseif ($type === 'end') {
                $out .= ' same => n,UserEvent(VBIVRComplete,AttemptID:${VB_ATTEMPT_ID})'."\n same => n,Hangup()\n";
            } elseif ($type === 'transfer') {
                $route = $routes[$n['route_id']];
                $dest = $route['destination'];
                if (! in_array($route['type'], ['queue', 'extension'], true) || ! preg_match('/\A[0-9]{1,12}\z/', $dest)) {
                    throw new \RuntimeException('Invalid transfer route.');
                }
                $check = $route['type'] === 'queue' ? '${QUEUE_MEMBER('.$dest.',ready)}' : '${IF($["${DEVICE_STATE(PJSIP/'.$dest.')}" = "NOT_INUSE"]?1:0)}';
                $out .= ' same => n,GotoIf($['.$check.' < 1]?'.$n['unavailable'].",1)\n";
                $out .= ' same => n,UserEvent(VBTransferStart,AttemptID:${VB_ATTEMPT_ID},NodeID:'.$id.")\n";
                if ($route['type'] === 'queue') {
                    $out .= ' same => n,Queue('.$dest.',c,,,30)'."\n";
                } else {
                    $out .= ' same => n,Dial(PJSIP/'.$dest.',30,gU('.$context.'-answer^${VB_ATTEMPT_ID}^${UNIQUEID}))'."\n";
                    $out .= ' same => n,ExecIf($["${DIALSTATUS}" = "ANSWER"]?UserEvent(VBTransferAnswered,AttemptID:${VB_ATTEMPT_ID}))'."\n";
                }
                $out .= ' same => n,Goto('.$n['next'].",1)\n";
            } else {
                if ($type === 'timeout') {
                    $out .= ' same => n,Wait('.($n['seconds'] ?? 1).")\n";
                }
                $out .= ' same => n,Goto('.$n['next'].",1)\n";
            }
        }

        $out .= '['.$context.'-answer]'."\n".'exten => s,1,UserEvent(VBTransferAnswered,AttemptID:${ARG1},ParentUniqueid:${ARG2})'."\n same => n,Return()\n";

        return $out;
    }
}
