<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\Market\Text;

final class SettingsController extends Controller
{
    /** Editable analysis keys: name => [label, unit, min, max, scale (shown = stored × scale)]. */
    public const ANALYSIS_FIELDS = [
        'inflation_annual' => ['تورم سالانه‌ی مورد انتظار', '٪', 0, 200, 100],
        'risk_free_annual' => ['نرخ بدون ریسک (اگر خودکار خاموش باشد)', '٪', 0, 100, 100],
        'max_position_weight' => ['سقف وزن هر نماد در سبد', '٪', 5, 100, 1],
        'fee_stock_buy' => ['کارمزد خرید سهام', '٪', 0, 3, 100],
        'fee_stock_sell' => ['کارمزد فروش سهام (با مالیات)', '٪', 0, 3, 100],
        'fee_fund_buy' => ['کارمزد خرید صندوق', '٪', 0, 3, 100],
        'fee_fund_sell' => ['کارمزد فروش صندوق', '٪', 0, 3, 100],
        'fee_bond_buy' => ['کارمزد خرید اوراق بدهی', '٪', 0, 3, 100],
        'fee_bond_sell' => ['کارمزد فروش اوراق بدهی', '٪', 0, 3, 100],
        'fee_option_buy' => ['کارمزد خرید اختیار معامله', '٪', 0, 3, 100],
        'fee_option_sell' => ['کارمزد فروش اختیار معامله', '٪', 0, 3, 100],
    ];

    public function show(Request $q): Response
    {
        $uid = $this->userId();
        $notifier = $this->app->notifier();
        $user = $this->user();
        $links = [];
        foreach (['bale' => 'بله', 'telegram' => 'تلگرام'] as $ch => $label) {
            $links[$ch] = ['label' => $label, 'url' => $notifier->linkUrl($ch, (string) $user['link_code'])];
        }
        $values = [];
        foreach (self::ANALYSIS_FIELDS as $key => [, , , , $scale]) {
            $values[$key] = round($this->app->settings()->float($uid, $key) * $scale, 4);
        }
        return $this->view($q, 'settings', ['title' => 'تنظیمات حساب', 'nav' => 'settings', 'channels' => $notifier->channels($uid),
            'links' => $links, 'values' => $values, 'fields' => self::ANALYSIS_FIELDS,
            'riskFreeAuto' => $this->app->settings()->forUser($uid, 'risk_free_auto') !== '0',
            'treasury' => $this->app->rates()->treasury(),
            'linkCode' => (string) $user['link_code'], 'enabled' => $notifier->enabledChannels(),
            'prefs' => ['buy' => $this->app->settings()->forUser($uid, 'notify_buy_signals') === '1',
                        'sell' => $this->app->settings()->forUser($uid, 'notify_sell_signals') === '1',
                        'disclosures' => $this->app->settings()->forUser($uid, 'notify_disclosures') !== '0',
                        'min' => round($this->app->settings()->float($uid, 'notify_min_probability') * 100)]]);
    }

    public function profile(Request $q): Response
    {
        $email = $q->input('email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'ایمیل معتبر نیست.');
        } else {
            $this->app->db()->update('users', ['display_name' => mb_substr($q->input('display_name'), 0, 100) ?: $this->user()['username'],
                                               'email' => $email], ['id' => $this->userId()]);
            $this->flash('success', 'مشخصات ذخیره شد.');
        }
        return $this->redirect($q, '/settings');
    }

    public function password(Request $q): Response
    {
        $new = (string) ($q->post['new_password'] ?? '');
        if ($new !== (string) ($q->post['new_password_confirm'] ?? '')) {
            $this->flash('error', 'تکرار رمز جدید یکسان نیست.');
            return $this->redirect($q, '/settings');
        }
        $error = $this->app->auth()->changePassword($this->userId(), (string) ($q->post['current_password'] ?? ''), $new);
        $this->flash($error ? 'error' : 'success', $error ?? 'رمز عبور تغییر کرد.');
        if (!$error) {
            $this->audit($q, 'password_change');
        }
        return $this->redirect($q, '/settings');
    }

    public function analysis(Request $q): Response
    {
        foreach (self::ANALYSIS_FIELDS as $key => [$label, , $min, $max, $scale]) {
            $value = Text::parseNumber($q->input($key));
            if ($value === null || $value < $min || $value > $max) {
                $this->flash('error', "«{$label}» باید بین $min و $max باشد.");
                return $this->redirect($q, '/settings');
            }
            $this->app->settings()->set($this->userId(), $key, (string) ($value / $scale));
        }
        $this->app->settings()->set($this->userId(), 'risk_free_auto', $q->input('risk_free_auto') === '1' ? '1' : '0');
        $this->flash('success', 'پارامترهای تحلیل و کارمزد ذخیره شد.');
        return $this->redirect($q, '/settings');
    }

    public function notifications(Request $q): Response
    {
        $s = $this->app->settings();
        $uid = $this->userId();
        $s->set($uid, 'notify_buy_signals', $q->input('buy') === '1' ? '1' : '0');
        $s->set($uid, 'notify_sell_signals', $q->input('sell') === '1' ? '1' : '0');
        $s->set($uid, 'notify_disclosures', $q->input('disclosures') === '1' ? '1' : '0');
        $min = max(0, min(95, (float) (Text::parseNumber($q->input('min_probability')) ?? 55)));
        $s->set($uid, 'notify_min_probability', (string) ($min / 100));
        $this->flash('success', 'تنظیمات اعلان ذخیره شد.');
        return $this->redirect($q, '/settings');
    }

    public function testNotification(Request $q): Response
    {
        $errors = $this->app->notifier()->test($this->userId());
        if (!$this->app->notifier()->channels($this->userId())) {
            $this->flash('error', 'هنوز هیچ پیام‌رسانی متصل نشده است.');
        } elseif ($errors) {
            foreach ($errors as $ch => $e) {
                $this->flash('error', "$ch: $e");
            }
        } else {
            $this->flash('success', 'پیام آزمایشی ارسال شد.');
        }
        return $this->redirect($q, '/settings');
    }

    /** Reads pending bot updates now instead of waiting for cron, so linking feels immediate. */
    public function checkLink(Request $q): Response
    {
        $before = count($this->app->notifier()->channels($this->userId()));
        try {
            $this->app->notifier()->pollUpdates();
        } catch (\Throwable $e) {
            $this->flash('error', 'ارتباط با سرور پیام‌رسان برقرار نشد: ' . $e->getMessage());
            return $this->redirect($q, '/settings#notify');
        }
        $linked = count($this->app->notifier()->channels($this->userId())) > $before;
        $this->flash($linked ? 'success' : 'error', $linked ? 'حساب شما به پیام‌رسان متصل شد.'
            : 'پیام شروع از طرف شما پیدا نشد. ابتدا روی دکمه‌ی اتصال بزنید و در ربات «شروع» را بفرستید.');
        return $this->redirect($q, '/settings#notify');
    }

    public function unlink(Request $q): Response
    {
        $this->app->notifier()->unlink($this->userId(), $q->param('channel'));
        $this->flash('success', 'اتصال پیام‌رسان قطع شد.');
        return $this->redirect($q, '/settings');
    }
}
