<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'type', 'description'];

    /**
     * Obter um valor de configuração.
     */
    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        $cacheKey = "settings.{$group}.{$key}";

        return Cache::remember($cacheKey, 3600, function () use ($group, $key, $default) {
            $setting = static::where('group', $group)->where('key', $key)->first();

            if (!$setting) {
                return $default;
            }

            return $setting->castValue();
        });
    }

    /**
     * Definir um valor de configuração.
     */
    public static function set(string $group, string $key, mixed $value, string $type = 'string', ?string $description = null): void
    {
        $storeValue = match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => is_string($value) ? $value : json_encode($value),
            'encrypted' => $value ? Crypt::encryptString((string) $value) : null,
            'integer' => (string) $value,
            default => (string) $value,
        };

        $updateData = ['value' => $storeValue, 'type' => $type];
        if ($description !== null) {
            $updateData['description'] = $description;
        }

        $setting = static::updateOrCreate(
            ['group' => $group, 'key' => $key],
            $updateData,
        );

        try {
            \Illuminate\Support\Facades\Log::info("Setting::set [{$group}.{$key}] => wasRecentlyCreated={$setting->wasRecentlyCreated}, id={$setting->id}, type={$type}, stored_length=" . strlen($storeValue ?? ''));
        } catch (\Throwable $e) {
            // Log failure must never crash the application
        }

        Cache::forget("settings.{$group}.{$key}");
        Cache::forget("settings.{$group}");
    }

    /**
     * Obter todas as configurações de um grupo.
     */
    public static function getGroup(string $group): array
    {
        $cacheKey = "settings.{$group}";

        return Cache::remember($cacheKey, 3600, function () use ($group) {
            $settings = static::where('group', $group)->get();
            $result = [];

            foreach ($settings as $setting) {
                $result[$setting->key] = $setting->castValue();
            }

            return $result;
        });
    }

    /**
     * Definir múltiplas configurações de um grupo.
     */
    public static function setGroup(string $group, array $values, array $types = []): void
    {
        foreach ($values as $key => $value) {
            $type = $types[$key] ?? 'string';
            static::set($group, $key, $value, $type);
        }

        Cache::forget("settings.{$group}");
    }

    /**
     * Converter valor armazenado para o tipo correto.
     */
    public function castValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'boolean' => (bool) $this->value,
            'integer' => (int) $this->value,
            'json' => json_decode($this->value, true),
            'encrypted' => $this->decryptSafely(),
            default => $this->value,
        };
    }

    /**
     * Obter valor sem máscara (para campos encrypted, retorna mascarado).
     */
    public function maskedValue(): string
    {
        if ($this->type === 'encrypted' && $this->value) {
            $decrypted = $this->decryptSafely();
            if ($decrypted && strlen($decrypted) > 8) {
                return substr($decrypted, 0, 4) . str_repeat('•', strlen($decrypted) - 8) . substr($decrypted, -4);
            }
            return $decrypted ? '••••••••' : '';
        }

        return (string) $this->value;
    }

    private function decryptSafely(): ?string
    {
        try {
            return Crypt::decryptString($this->value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Limpar cache de todas as settings.
     */
    public static function clearCache(): void
    {
        $groups = static::distinct('group')->pluck('group');
        foreach ($groups as $group) {
            Cache::forget("settings.{$group}");
            $keys = static::where('group', $group)->pluck('key');
            foreach ($keys as $key) {
                Cache::forget("settings.{$group}.{$key}");
            }
        }
    }
}
