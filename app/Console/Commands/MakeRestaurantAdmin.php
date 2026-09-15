<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeRestaurantAdmin extends Command
{
    protected $signature = 'restaurant:admin {email : Email of an existing account}';

    protected $description = 'Grant restaurant administrator access to an existing account';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('Créez d’abord un compte depuis la page d’inscription.');

            return self::FAILURE;
        }
        $user->forceFill(['role' => 'admin'])->save();
        $this->info('Accès administrateur accordé.');

        return self::SUCCESS;
    }
}
