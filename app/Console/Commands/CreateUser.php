<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

#[Signature('billing:create-user {email} {name}')]
#[Description('Create the single user of the app (refuses if one already exists)')]
class CreateUser extends Command
{
    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->components->error('A user already exists. This app is single-user.');

            return self::FAILURE;
        }

        User::create([
            'email' => $this->argument('email'),
            'name' => $this->argument('name'),
            'password' => password('Password', required: true),
        ]);

        $this->components->info('User created. Log in to set up two-factor authentication.');

        return self::SUCCESS;
    }
}
