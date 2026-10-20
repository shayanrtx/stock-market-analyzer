<?php

declare(strict_types=1);

namespace Sabadgardan\Notify;

/**
 * Telegram-compatible Bot API client. Bale exposes the same API at tapi.bale.ai, so one class
 * serves both. Telegram's API is blocked from Iranian servers; Bale works there.
 */
final class BotApiMessenger implements Messenger
{
    public const BALE_URL = 'https://tapi.bale.ai/bot';
    public const TELEGRAM_URL = 'https://api.telegram.org/bot';
    public const CAPTION_LIMIT = 1024;

    /** @var callable(string, array<string, mixed>): array<string, mixed> */
    private $http;

    /** @param callable|null $http (url, fields) => decoded JSON; injected in tests */
    public function __construct(private string $channel, private string $baseUrl, private string $token,
                                ?callable $http = null)
    {
        $this->http = $http ?? [$this, 'post'];
    }

    public static function bale(string $token, ?callable $http = null): self
    {
        return new self('bale', self::BALE_URL, $token, $http);
    }

    public static function telegram(string $token, ?callable $http = null): self
    {
        return new self('telegram', self::TELEGRAM_URL, $token, $http);
    }

    public function name(): string
    {
        return $this->channel;
    }

    public function sendMessage(string $chatId, string $text): void
    {
        $this->call('sendMessage', ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 4000)]);
    }

    public function sendPhoto(string $chatId, string $png, string $caption): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($file, $png);
        try {
            $short = mb_strlen($caption) <= self::CAPTION_LIMIT;
            $this->call('sendPhoto', ['chat_id' => $chatId, 'photo' => new \CURLFile($file, 'image/png', 'signal.png'),
                                      'caption' => $short ? $caption : mb_substr($caption, 0, 200) . '…']);
            if (!$short) {
                $this->sendMessage($chatId, $caption);  // full analysis as a follow-up message
            }
        } finally {
            @unlink($file);
        }
    }

    public function updates(int $offset): array
    {
        $res = $this->call('getUpdates', ['offset' => $offset, 'timeout' => 0, 'limit' => 100]);
        return is_array($res['result'] ?? null) ? $res['result'] : [];
    }

    /** @return array<string, mixed> */
    private function call(string $method, array $fields): array
    {
        if ($this->token === '') {
            throw new NotifyException("توکن ربات {$this->channel} تنظیم نشده است.");
        }
        $res = ($this->http)($this->baseUrl . $this->token . '/' . $method, $fields);
        if (!($res['ok'] ?? false)) {
            throw new NotifyException(sprintf('%s: %s', $this->channel, $res['description'] ?? 'پاسخ نامعتبر'));
        }
        return $res;
    }

    /** @return array<string, mixed> */
    private function post(string $url, array $fields): array
    {
        $h = curl_init($url);
        curl_setopt_array($h, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($h);
        $error = curl_error($h);
        curl_close($h);
        if ($body === false) {
            throw new NotifyException("{$this->channel}: اتصال برقرار نشد ($error)");
        }
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : ['ok' => false, 'description' => 'پاسخ غیر JSON'];
    }
}
