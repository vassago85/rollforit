<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

#[Signature('op:add {email? : Operator email address} {name? : Operator display name} {--password= : Supply the password instead of being prompted}')]
#[Description('Create (or promote) a user with operator access to the Roll4It dashboard')]
class AddOperator extends Command
{
    public function handle(): int
    {
        $email = (string) ($this->argument('email') ?? $this->ask('Email'));
        $name = (string) ($this->argument('name') ?? $this->ask('Display name', explode('@', $email)[0] ?? 'Operator'));
        $password = (string) ($this->option('password') ?? $this->secret('Password'));

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'password' => $password,
        ], [
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }

        $user = User::firstOrNew(['email' => strtolower($email)]);
        $existed = $user->exists;
        $user->name = $name;
        $user->password = Hash::make($password);
        $user->is_operator = true;
        $user->save();

        $this->info($existed
            ? "Promoted {$user->email} to operator and reset their password."
            : "Created operator {$user->email}.");

        return self::SUCCESS;
    }
}
