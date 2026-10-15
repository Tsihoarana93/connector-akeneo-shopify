<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateUser extends Command
{
    protected $signature = 'fidelity:create-user
                            {email : Email address used to log in}
                            {--name= : Display name, defaults to the part of the email before the @}';

    protected $description = 'Create a user who can log in to the sync interface, or reset an existing user\'s password';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = (string) $this->secret('Password (at least 12 characters)');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', Password::min(12)]]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = $this->option('name') ?? $user->name ?? Str::before($email, '@');
        $user->password = $password;
        $user->save();

        $this->info($user->wasRecentlyCreated ? "User {$email} created." : "Password of {$email} updated.");

        return self::SUCCESS;
    }
}
