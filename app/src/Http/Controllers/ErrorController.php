<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;

final class ErrorController extends Controller
{
    public function notFound(Request $q): Response
    {
        return $q->wantsJson() ? Response::json(['error' => 'یافت نشد'], 404)
            : $this->page($q, 404, 'صفحه پیدا نشد', 'آدرسی که باز کردید وجود ندارد یا جابه‌جا شده است.');
    }

    public function forbidden(Request $q): Response
    {
        return $q->wantsJson() ? Response::json(['error' => 'دسترسی ندارید'], 403)
            : $this->page($q, 403, 'دسترسی ندارید', 'این بخش فقط برای مدیران سامانه است.');
    }

    public function expired(Request $q): Response
    {
        return $this->page($q, 419, 'نشست منقضی شده است', 'فرم قدیمی بود. صفحه را دوباره باز کنید و دوباره ارسال کنید.');
    }

    public function server(Request $q, \Throwable $e): Response
    {
        $log = $this->app->storagePath('logs/error.log');
        @mkdir(dirname($log), 0775, true);
        @file_put_contents($log, sprintf("[%s] %s %s\n%s\n\n", date('Y-m-d H:i:s'), $q->method, $q->path, $e), FILE_APPEND);
        $detail = $this->app->config->get('debug') ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
            : 'جزئیات خطا در storage/logs/error.log ثبت شد.';
        if ($q->wantsJson()) {
            return Response::json(['error' => 'خطای داخلی سرور', 'detail' => $detail], 500);
        }
        try {
            return $this->page($q, 500, 'خطای غیرمنتظره', $detail);
        } catch (\Throwable) {
            return Response::html('<h1>خطای داخلی سرور</h1>', 500);
        }
    }

    private function page(Request $q, int $status, string $title, string $message): Response
    {
        $layout = $this->app->installed() && $this->app->auth()->check() ? 'layout' : 'layout_guest';
        return $this->view($q, 'error', ['title' => $title, 'message' => $message, 'status' => $status], $layout, $status);
    }
}
