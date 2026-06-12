<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Cria (ou promove) o super admin da plataforma.
 *
 * Uso manual:    php artisan admin:create admin@empresa.com --password=...
 * Uso no deploy: php artisan admin:create --ensure
 *   → idempotente: só age se nenhum admin existir, usando as variáveis
 *     SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD / SUPER_ADMIN_NAME.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create
        {email? : E-mail do super admin (fallback: SUPER_ADMIN_EMAIL)}
        {--name= : Nome (fallback: SUPER_ADMIN_NAME ou derivado do e-mail)}
        {--password= : Senha (fallback: SUPER_ADMIN_PASSWORD; se ausente, gera uma aleatória e exibe)}
        {--ensure : Não faz nada se já existir um administrador (uso em deploy)}';

    protected $description = 'Cria ou promove o super admin da plataforma';

    public function handle(): int
    {
        if ($this->option('ensure') && User::where('is_admin', true)->exists()) {
            $this->info('Já existe um administrador. Nada a fazer.');

            return self::SUCCESS;
        }

        $email = strtolower((string) ($this->argument('email') ?: env('SUPER_ADMIN_EMAIL', '')));

        if (!$email) {
            if ($this->option('ensure')) {
                $this->warn('Nenhum administrador existe e SUPER_ADMIN_EMAIL não está definido.');
                $this->warn('Crie um com: php artisan admin:create email@dominio.com');

                return self::SUCCESS; // não falha o deploy
            }

            $this->error('Informe o e-mail: php artisan admin:create email@dominio.com');

            return self::FAILURE;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("E-mail inválido: {$email}");

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update(['is_admin' => true, 'is_active' => true]);
            $this->info("Usuário existente {$email} promovido a super admin.");

            return self::SUCCESS;
        }

        $password = (string) ($this->option('password') ?: env('SUPER_ADMIN_PASSWORD', ''));
        $generated = false;

        if (!$password) {
            $password = Str::password(16);
            $generated = true;
        }

        $name = (string) ($this->option('name') ?: env('SUPER_ADMIN_NAME', '')) ?: Str::title(Str::before($email, '@'));

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt($password),
            'is_admin' => true,
            'is_active' => true,
        ]);

        // email_verified_at não é fillable; o admin de bootstrap nasce verificado
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->info("Super admin criado: {$email}");

        if ($generated) {
            $this->warn("Senha gerada (anote, não será exibida novamente): {$password}");
        }

        return self::SUCCESS;
    }
}
