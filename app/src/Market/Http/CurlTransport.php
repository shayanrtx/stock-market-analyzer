<?php

declare(strict_types=1);

namespace Sabadgardan\Market\Http;

/** cURL multi-handle transport: all URLs of a batch are fetched concurrently. */
final class CurlTransport implements Transport
{
    public const MAX_PARALLEL = 8;

    public function fetchMany(array $urls, array $headers, int $timeout): array
    {
        $results = [];
        foreach (array_chunk(array_values(array_unique($urls)), self::MAX_PARALLEL) as $chunk) {
            $results += $this->batch($chunk, $headers, $timeout);
        }
        return $results;
    }

    /**
     * @param list<string> $urls
     * @param array<string, string> $headers
     * @return array<string, HttpResult>
     */
    private function batch(array $urls, array $headers, int $timeout): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        $multi = curl_multi_init();
        $handles = [];
        foreach ($urls as $url) {
            $h = curl_init($url);
            curl_setopt_array($h, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_ENCODING => '',          // accept gzip/deflate
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_multi_add_handle($multi, $h);
            $handles[$url] = $h;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $url => $h) {
            $body = curl_multi_getcontent($h);
            $results[$url] = new HttpResult((int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), (string) $body,
                curl_error($h));
            curl_multi_remove_handle($multi, $h);
            curl_close($h);
        }
        curl_multi_close($multi);
        return $results;
    }
}
