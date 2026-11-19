<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('admin:set-password
    {username : Admin username (letters, digits, dot, dash, underscore)}
    {--password= : The password. Leave it out to be asked for it with hidden input (an option stays in your shell history)}')]
#[Description('Create an admin, or change the password of an existing one')]
class SetAdminPasswordCommand extends Command
{
    public const MIN_PASSWORD_LENGTH = 10;

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));
        $password = $this->option('password');

        if ($password === null) {
            $password = (string) $this->secret('Password baru (input tersembunyi)');

            if ($password !== (string) $this->secret('Ulangi password')) {
                $this->error('Kedua password tidak sama.');

                return self::FAILURE;
            }
        }

        $validator = Validator::make(
            ['username' => $username, 'password' => $password],
            [
                'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
                'password' => ['required', 'string', 'min:'.self::MIN_PASSWORD_LENGTH, 'max:200'],
            ],
            [
                'username.regex' => 'Username hanya boleh berisi huruf, angka, titik, minus, dan garis bawah.',
                'password.min' => 'Password minimal '.self::MIN_PASSWORD_LENGTH.' karakter.',
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $admin = Admin::firstOrNew(['username' => $username]);
        $created = ! $admin->exists;

        // The model hashes the password; only the hash is stored.
        $admin->password = $password;
        $admin->save();

        $this->info($created
            ? "Admin \"{$admin->username}\" dibuat."
            : "Password admin \"{$admin->username}\" diganti.");

        return self::SUCCESS;
    }
}
