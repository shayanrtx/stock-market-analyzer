<?php

declare(strict_types=1);

namespace Tests;

use Sabadgardan\Core\Settings;
use Sabadgardan\Notify\BotApiMessenger;
use Sabadgardan\Notify\Messenger;
use Sabadgardan\Notify\NotifyException;
use Sabadgardan\Signals\Signal;

/** Records what would have been sent. */
final class FakeMessenger implements Messenger
{
    /** @var list<array{0: string, 1: string, 2: string}> method, chat, text */
    public array $sent = [];
    /** @var list<array<string, mixed>> */
    public array $updates = [];
    public bool $fail = false;

    public function __construct(private string $channel = 'bale')
    {
    }

    public function name(): string
    {
        return $this->channel;
    }

    public function sendMessage(string $chatId, string $text): void
    {
        if ($this->fail) {
            throw new NotifyException('blocked');
        }
        $this->sent[] = ['message', $chatId, $text];
    }

    public function sendPhoto(string $chatId, string $png, string $caption): void
    {
        $this->sent[] = ['photo', $chatId, $caption];
    }

    public function updates(int $offset): array
    {
        return array_values(array_filter($this->updates, static fn ($u) => $u['update_id'] >= $offset));
    }
}

final class NotifierTest extends TestCase
{
    private function signal(string $side = Signal::BUY, float $probability = 0.6): Signal
    {
        $s = new Signal('71000000001000003', 'فملی', $side, time(), 5800, 5300, 6400, $probability,
            ['samples' => 40, 'conditioned' => true, 'horizon_days' => 42], 72.0, ['صف خرید: حجم صف سنگین']);
        $s->id = 1;
        return $s;
    }

    public function testBotApiCallsAndErrors(): void
    {
        $calls = [];
        $http = function (string $url, array $fields) use (&$calls): array {
            $calls[] = [$url, $fields];
            return str_contains($url, 'getUpdates') ? ['ok' => true, 'result' => [['update_id' => 7]]] : ['ok' => true];
        };
        $bale = BotApiMessenger::bale('TOKEN', $http);
        $bale->sendMessage('42', 'سلام');
        $this->assertSame('https://tapi.bale.ai/botTOKEN/sendMessage', $calls[0][0]);
        $this->assertSame(['chat_id' => '42', 'text' => 'سلام'], $calls[0][1]);
        $this->assertSame(7, $bale->updates(0)[0]['update_id']);

        $tg = BotApiMessenger::telegram('T', static fn () => ['ok' => false, 'description' => 'Forbidden']);
        $e = $this->expectException(NotifyException::class, fn () => $tg->sendMessage('1', 'x'));
        $this->assertContains('Forbidden', $e->getMessage());
        $this->expectException(NotifyException::class, fn () => BotApiMessenger::bale('')->sendMessage('1', 'x'));
    }

    public function testLinkingThroughStartCode(): void
    {
        $app = $this->makeApp();
        $uid = $app->auth()->createUser('ali', 'password1', 'علی', false);
        $code = (string) $app->db()->value('SELECT link_code FROM users WHERE id = ?', [$uid]);
        $m = new FakeMessenger();
        $m->updates = [
            ['update_id' => 10, 'message' => ['text' => '/start ' . str_repeat('0', 16), 'chat' => ['id' => 99]]],
            ['update_id' => 11, 'message' => ['text' => "/start $code", 'chat' => ['id' => 555]]],
            ['update_id' => 12, 'message' => ['text' => 'hello', 'chat' => ['id' => 555]]],
        ];
        $n = $app->notifier();
        $n->useMessengers($m);
        $this->assertSame(1, $n->pollUpdates());
        $this->assertSame('555', (string) $n->channels($uid)[0]['chat_id']);
        $this->assertSame('13', $app->settings()->system('last_updates_offset_bale'));
        $this->assertContains('معتبر نیست', $m->sent[0][2], 'unknown code gets a helpful reply');
        $this->assertSame(0, $n->pollUpdates(), 'processed updates are not linked twice');
    }

    public function testDispatchRespectsAudienceAndPreferences(): void
    {
        $app = $this->makeApp();
        $holder = $app->auth()->createUser('holder', 'password1', 'h', false);
        $watcher = $app->auth()->createUser('watcher', 'password1', 'w', false);
        $quiet = $app->auth()->createUser('quiet', 'password1', 'q', false);
        $m = new FakeMessenger();
        $n = $app->notifier();
        $n->useMessengers($m);
        foreach ([$holder => '1', $watcher => '2', $quiet => '3'] as $uid => $chat) {
            $n->link($uid, 'bale', $chat);
        }
        $app->settings()->set($quiet, 'notify_buy_signals', '0');

        $this->assertSame(2, $n->dispatch($this->signal(), [$holder], [$watcher, $quiet]));
        $this->assertSame(['1', '2'], array_column($m->sent, 1));
        $this->assertContains('فملی', $m->sent[0][2]);
        $this->assertContains('احتمال رسیدن به هدف', $m->sent[0][2]);

        $m->sent = [];
        $this->assertSame(1, $n->dispatch($this->signal(Signal::SELL), [$holder], [$watcher]), 'sell warnings go to holders only');
        $this->assertSame(['1'], array_column($m->sent, 1));

        $m->sent = [];
        $app->settings()->set($watcher, 'notify_min_probability', '0.7');
        $this->assertSame(1, $n->dispatch($this->signal(Signal::BUY, 0.6), [$holder], [$watcher]), 'below the user minimum');

        $m->fail = true;
        $this->assertSame(0, $n->dispatch($this->signal(), [$holder], []));
        $this->assertSame('failed', (string) $app->db()->value("SELECT status FROM notifications ORDER BY id DESC LIMIT 1"));
    }

    public function testMessageContents(): void
    {
        $text = $this->signal()->message('دستیار سبدگردان');
        $this->assertContains('سیگنال خرید', $text);
        $this->assertContains('هدف: 6,400', $text);
        $this->assertContains('حد ضرر: 5,300', $text);
        $this->assertContains('توصیه‌ی قطعی', $text);
        $this->assertNear(1.2, $this->signal()->rewardRisk(), 1e-9);
        $this->assertSame(Settings::SYSTEM, 0);
    }
}
