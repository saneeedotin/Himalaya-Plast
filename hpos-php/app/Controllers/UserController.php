<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Flash;

final class UserController extends Controller
{
    private const MIN_PASSWORD = 10;

    public function index(): void
    {
        $this->render('users/index', ['title' => 'Users', 'users' => Db::rows('sp_user_list', [$this->uid()])]);
    }

    public function create(): void
    {
        $this->render('users/form', $this->formData(null, [], ['title' => 'New user']));
    }

    public function store(): void
    {
        $row = ['email' => $this->str('email'), 'name' => $this->str('name'), 'employee_code' => $this->str('employee_code')];
        $roleIds = $this->strList('roles');
        $data = $this->formData(null, $roleIds, ['title' => 'New user', 'row' => $row]);

        $this->saving(function () use ($row, $roleIds) {
            $password = (string) ($_POST['password'] ?? '');
            if (mb_strlen($password) < self::MIN_PASSWORD) {
                throw new \App\Core\AppError('The password must be at least ' . self::MIN_PASSWORD . ' characters.');
            }
            Db::callOut('sp_user_create', [
                $this->uid(), strtolower($row['email']), $row['name'], Auth::hash($password),
                $row['employee_code'] ?: null, json_encode($roleIds), client_ip(),
            ]);
            Flash::success('User created.');
            redirect('/users');
        }, 'users/form', $data);
    }

    public function edit(array $p): void
    {
        [$user, $roleIds] = $this->load($p['id']);
        $this->render('users/form', $this->formData($user, $roleIds, ['title' => 'Edit user']));
    }

    public function update(array $p): void
    {
        [$user, $roleIds] = $this->load($p['id']);
        $newRoles = $this->strList('roles');
        $active = isset($_POST['is_active']) ? 1 : 0;
        $data = $this->formData($user, $newRoles, ['title' => 'Edit user', 'row' => $user]);

        $this->saving(function () use ($user, $newRoles, $active) {
            Db::call('sp_user_update', [$this->uid(), $user['id'], $active, json_encode($newRoles), client_ip()]);
            Flash::success('User updated.');
            redirect('/users');
        }, 'users/form', $data);
    }

    /** An administrator sets a new password for a user who has forgotten theirs. */
    public function resetPassword(array $p): void
    {
        [$user] = $this->load($p['id']);
        $password = (string) ($_POST['new_password'] ?? '');
        if ($password !== (string) ($_POST['confirm_password'] ?? '')) {
            throw new \App\Core\AppError('The two passwords do not match.');
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new \App\Core\AppError('The password must be at least ' . self::MIN_PASSWORD . ' characters.');
        }
        Db::call('sp_user_set_password', [$this->uid(), $user['id'], Auth::hash($password), client_ip()]);
        Flash::success('Password set for ' . $user['name'] . '. They were signed out everywhere and can sign in now.');
        redirect('/users/' . $user['id'] . '/edit');
    }

    /** @return array{0:array,1:list<string>} */
    private function load(string $id): array
    {
        $sets = Db::call('sp_user_get', [$this->uid(), $id]);
        $user = $this->must($sets[0][0] ?? null);
        return [$user, array_column($sets[1] ?? [], 'role_id')];
    }

    private function formData(?array $user, array $roleIds, array $extra): array
    {
        $roles = array_column(Db::rows('sp_role_list', [$this->uid()]), 'name', 'id');
        return $extra + ['user' => $user, 'row' => $user ?? [], 'roles' => $roles, 'roleIds' => $roleIds, 'isSelf' => $user && $user['id'] === Auth::id()];
    }
}
