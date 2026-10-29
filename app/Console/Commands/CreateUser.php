<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateUser extends Command
{
    protected $signature = 'tracker:create-user {email : Sign-in e-mail address} {--name=Student : Display name} {--password= : Password (prompted if omitted; avoid typing it in shared shells)}';

    protected $description = 'Create the sign-in account (there is no registration page)';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $password = $this->option('password') ?: $this->secret('Password (at least 12 characters, with letters and numbers)');

        $v = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(12)->letters()->numbers()],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        User::create(['name' => $this->option('name'), 'email' => $email, 'password' => Hash::make($password)]);
        $this->info("Account created for {$email}. Sign in at the app's address.");

        return self::SUCCESS;
    }
}
