<?php

namespace App\Enums;

enum PostStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::PendingReview => 'Aguardando Revisão',
            self::Approved => 'Aprovado',
            self::Scheduled => 'Agendado',
            self::Publishing => 'Publicando',
            self::Published => 'Publicado',
            self::Failed => 'Falhou',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingReview => 'yellow',
            self::Approved => 'blue',
            self::Scheduled => 'indigo',
            self::Publishing => 'orange',
            self::Published => 'green',
            self::Failed => 'red',
        };
    }
}
