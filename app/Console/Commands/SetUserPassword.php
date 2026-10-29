<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class SetUserPassword extends Command
{
    protected $signature = 'tracker:set-password {email} {--password= : New password (prompted if omitted)}';

    protected $description = 'Reset a forgotten password from the server';

    public function handle(): int
    {
        $user = User::where('email', strtolower(trim($this->argument('email'))))->first();
        if (! $user) {
            $this->error('No account with that e-mail address.');

            return self::FAILURE;
        }
        $password = $this->option('password') ?: $this->secret('New password (at least 12 characters, with letters and numbers)');
        $v = Validator::make(['password' => $password], ['password' => ['required', Password::min(12)->letters()->numbers()]]);
        if ($v->fails()) {
            $this->error($v->errors()->first());

            return self::FAILURE;
        }

        // Changing the hash also ends every existing "keep me signed in" session for this account.
        $user->forceFill(['password' => Hash::make($password), 'remember_token' => null])->save();
        $user->tokens()->delete(); // phones must sign in again with the new password
        $this->info('Password changed.');

        return self::SUCCESS;
    }
}
