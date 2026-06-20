<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Criptografa tokens OAuth gravados em texto plano antes da adoção do cast
 * 'encrypted' em SocialAccount e AnalyticsConnection.
 *
 * Idempotente: valores já criptografados (decryptString funciona) são pulados.
 */
return new class extends Migration
{
    private array $targets = [
        'social_accounts' => ['access_token', 'refresh_token'],
        'analytics_connections' => ['access_token', 'refresh_token'],
    ];

    public function up(): void
    {
        foreach ($this->targets as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->select(array_merge(['id'], $columns))
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($table, $columns) {
                    foreach ($rows as $row) {
                        $updates = [];

                        foreach ($columns as $column) {
                            $value = $row->{$column};

                            if ($value === null || $value === '') {
                                continue;
                            }

                            if ($this->isEncrypted($value)) {
                                continue;
                            }

                            $updates[$column] = Crypt::encryptString($value);
                        }

                        if ($updates) {
                            DB::table($table)->where('id', $row->id)->update($updates);
                        }
                    }
                });
        }
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    public function down(): void
    {
        // Sem reversão: não voltamos tokens para texto plano
    }
};
