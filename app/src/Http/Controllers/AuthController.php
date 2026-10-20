<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\Auth\Auth;
use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;

final class AuthController extends Controller
{
    public function loginForm(Request $q): Response
    {
        if ($this->app->auth()->check()) {
            return $this->redirect($q, '/');
        }
        return $this->view($q, 'auth/login', ['title' => 'ورود', 'username' => '', 'error' => '',
            'registrationOpen' => $this->registrationOpen()], 'layout_guest');
    }

    public function login(Request $q): Response
    {
        $username = $q->input('username');
        $error = $this->app->auth()->attempt($username, (string) ($q->post['password'] ?? ''), $q->ip());
        if ($error !== null) {
            $this->audit($q, 'login_failed', $username, null);
            return $this->view($q, 'auth/login', ['title' => 'ورود', 'username' => $username, 'error' => $error,
                'registrationOpen' => $this->registrationOpen()], 'layout_guest', 422);
        }
        $this->audit($q, 'login');
        return $this->redirect($q, '/');
    }

    public function registerForm(Request $q): Response
    {
        if (!$this->registrationOpen()) {
            return $this->view($q, 'auth/closed', ['title' => 'ثبت‌نام'], 'layout_guest');
        }
        return $this->view($q, 'auth/register', ['title' => 'ثبت‌نام', 'old' => [], 'errors' => []], 'layout_guest');
    }

    public function register(Request $q): Response
    {
        if (!$this->registrationOpen()) {
            return $this->view($q, 'auth/closed', ['title' => 'ثبت‌نام'], 'layout_guest', 403);
        }
        $old = ['username' => $q->input('username'), 'email' => $q->input('email'), 'display_name' => $q->input('display_name')];
        // Honeypot: real users never see or fill this field.
        if ($q->input('website') !== '') {
            return $this->redirect($q, '/login');
        }
        $auth = $this->app->auth();
        $errors = $auth->validateRegistration($old['username'], $old['email'], (string) ($q->post['password'] ?? ''),
            (string) ($q->post['password_confirm'] ?? ''));
        if (!$errors && $auth->registrationsFromIp($q->ip()) >= Auth::REGISTER_LIMIT) {
            $errors['username'] = 'از این شبکه امروز حساب‌های زیادی ساخته شده است. فردا دوباره تلاش کنید.';
        }
        if ($errors) {
            return $this->view($q, 'auth/register', ['title' => 'ثبت‌نام', 'old' => $old, 'errors' => $errors], 'layout_guest', 422);
        }
        $needsApproval = $this->app->settings()->system('registration_approval') === '1';
        $id = $auth->createUser($old['username'], (string) $q->post['password'], mb_substr($old['display_name'], 0, 100), false,
            $old['email'], $needsApproval ? Auth::PENDING : Auth::ACTIVE);
        $this->audit($q, 'register', $old['username'], $id);
        if ($needsApproval) {
            return $this->view($q, 'auth/pending', ['title' => 'ثبت‌نام انجام شد'], 'layout_guest');
        }
        $auth->attempt($old['username'], (string) $q->post['password'], $q->ip());
        $this->flash('success', 'حساب شما ساخته شد. برای شروع، اولین معامله‌ی سبد را ثبت کنید.');
        return $this->redirect($q, '/');
    }

    public function logout(Request $q): Response
    {
        $this->audit($q, 'logout');
        $this->app->auth()->logout();
        return $this->redirect($q, '/login');
    }

    private function registrationOpen(): bool
    {
        return $this->app->settings()->system('registration_open') === '1';
    }
}
