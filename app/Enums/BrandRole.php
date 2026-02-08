<?php

namespace App\Enums;

enum BrandRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Proprietário',
            self::Admin => 'Administrador',
            self::Editor => 'Editor',
            self::Viewer => 'Visualizador',
        };
    }

    public function canEdit(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Editor]);
    }

    public function canManage(): bool
    {
        return in_array($this, [self::Owner, self::Admin]);
    }
}
