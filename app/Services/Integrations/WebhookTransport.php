<?php

namespace App\Services\Integrations;

use Illuminate\Validation\ValidationException;

class WebhookTransport
{
    public function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [$host];
        }

        return array_values(array_unique(array_column(dns_get_record($host, DNS_A) ?: [], 'ip')));
    }

    public function destination(string $url): array
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query']) || ($parts['port'] ?? 443) !== 443 || ! preg_match('/\A[a-zA-Z0-9.-]+\z/', $parts['host'])) {
            throw ValidationException::withMessages(['url' => 'Use a public HTTPS endpoint on port 443 without credentials, query or fragment.']);
        }
        $ips = $this->addresses($parts['host']);
        if (! $ips || count($ips) > 20) {
            throw ValidationException::withMessages(['url' => 'The endpoint must resolve to public IPv4 addresses.']);
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) || (int) explode('.', $ip)[0] >= 224) {
                throw ValidationException::withMessages(['url' => 'Private, shared and reserved endpoint addresses are forbidden.']);
            }
        }

        return [$parts['host'], $ips[0]];
    }

    public function send(string $url, string $body, array $headers): int
    {
        // Revalidate DNS at every attempt and pin the approved address for this connection.
        [$host, $ip] = $this->destination($url);
        $curl = curl_init($url);
        $bytes = 0;
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RESOLVE => ["{$host}:443:{$ip}"], CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($handle, string $data) use (&$bytes): int {
                $bytes += strlen($data);

                return $bytes > 65536 ? 0 : strlen($data);
            },
        ]);
        try {
            if (curl_exec($curl) === false) {
                throw new \RuntimeException('Webhook transport did not confirm delivery.');
            }

            return (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
        }
    }
}
