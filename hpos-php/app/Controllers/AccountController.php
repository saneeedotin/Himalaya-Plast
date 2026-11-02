<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Flash;
use App\Core\View;

/** The signed-in user's own profile: details, password, theme and recent sign-ins. */
final class AccountController
{
    private const MIN_LENGTH = 10;

    public function show(): void
    {
        $this->render();
    }

    /** The old standalone page now lives on the profile. */
    public function showPassword(): void
    {
        redirect('/account#password');
    }

    public function update(): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        Db::call('sp_profile_update', [Auth::id(), $name, $phone === '' ? null : $phone, client_ip()]);
        Flash::success('Profile saved.');
        redirect('/account');
    }

    public function changePassword(): void
    {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $error = null;
        if ($new !== $confirm) {
            $error = 'The new passwords do not match.';
        } elseif (mb_strlen($new) < self::MIN_LENGTH) {
            $error = 'The new password must be at least ' . self::MIN_LENGTH . ' characters.';
        } elseif ($new === $current) {
            $error = 'The new password must be different from the current one.';
        } else {
            $row = Db::row('sp_auth_get_user_by_id', [Auth::id()]);
            if ($row === null || !password_verify($current, $row['password_hash'])) {
                $error = 'The current password is incorrect.';
            }
        }

        if ($error !== null) {
            http_response_code(422);
            $this->render($error);
            return;
        }

        Db::call('sp_auth_change_password', [Auth::id(), Auth::hash($new), Auth::tokenHash(), client_ip()]);
        Flash::success('Password changed. Other devices have been signed out.');
        redirect('/account#password');
    }

    private function render(?string $passwordError = null): void
    {
        $sets = Db::call('sp_profile_get', [Auth::id()]);
        $profile = $sets[0][0] ?? null;
        if ($profile === null) {
            throw new \App\Core\AppError('Your account could not be found.');
        }
        View::render('account/profile', [
            'title' => 'My profile',
            'profile' => $profile,
            'roles' => array_column($sets[1] ?? [], 'name'),
            'signIns' => $sets[2] ?? [],
            'minLength' => self::MIN_LENGTH,
            'passwordError' => $passwordError,
        ]);
    }
}
