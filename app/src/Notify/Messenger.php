<?php

declare(strict_types=1);

namespace Sabadgardan\Notify;

/** A chat-bot channel (Bale, Telegram, ...). Implementations throw NotifyException on failure. */
interface Messenger
{
    /** Machine name: 'bale', 'telegram'. */
    public function name(): string;

    public function sendMessage(string $chatId, string $text): void;

    public function sendPhoto(string $chatId, string $png, string $caption): void;

    /**
     * Incoming updates after ``$offset`` (Bot API getUpdates semantics).
     *
     * @return list<array<string, mixed>>
     */
    public function updates(int $offset): array;
}
