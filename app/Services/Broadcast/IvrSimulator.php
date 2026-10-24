<?php

namespace App\Services\Broadcast;

use Illuminate\Support\Facades\Validator;

class IvrSimulator
{
    public function run(array $graph, array $inputs): array
    {
        Validator::make(['inputs' => $inputs], ['inputs' => 'array|max:40', 'inputs.*' => 'string|max:20'])->validate();
        app(IvrDesigner::class)->validate($graph);
        $nodes = array_column($graph['nodes'], null, 'id');
        $id = $graph['start'];
        $digit = '';
        $cursor = 0;
        $trace = [];
        for ($step = 0; $step < 20; $step++) {
            $node = $nodes[$id];
            $type = $node['type'];
            $next = null;
            $event = ['node' => $id, 'type' => $type];
            if (in_array($type, ['input', 'confirmation', 'condition'], true)) {
                if ($type !== 'condition') {
                    $digit = $inputs[$cursor++] ?? 'timeout';
                }
                if ($digit === 'timeout' || $digit === '') {
                    $next = $node['timeout'];
                    $event['result'] = 'timeout';
                } elseif (array_key_exists($digit, $node['branches'])) {
                    $next = $node['branches'][$digit];
                    $event['result'] = 'digit '.$digit;
                } else {
                    $next = $node['invalid'];
                    $event['result'] = 'invalid input';
                }
            } elseif ($type === 'transfer') {
                $result = $inputs[$cursor++] ?? 'unavailable';
                if (! in_array($result, ['answered', 'no_answer', 'unavailable'], true)) {
                    app(Readiness::class)->fail('Transfer simulation expects answered, no_answer or unavailable.');
                }
                $next = $result === 'unavailable' ? $node['unavailable'] : $node['next'];
                $event['result'] = 'transfer '.$result;
            } elseif (in_array($type, ['end', 'opt_out'], true)) {
                $event['result'] = $type === 'end' ? 'ivr completed' : 'simulated opt-out; no suppression saved';
                $trace[] = $event;

                return ['trace' => $trace, 'consumed_inputs' => $cursor, 'simulation_only' => true];
            } else {
                $next = $node['next'];
                $event['result'] = $type === 'timeout' ? 'wait '.($node['seconds'] ?? 1).' seconds' : 'prompt simulated';
            }
            $event['next'] = $next;
            $trace[] = $event;
            $id = $next;
        }
        app(Readiness::class)->fail('Simulation exceeded the maximum path length.');
    }
}
