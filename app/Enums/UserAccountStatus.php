<?php

namespace App\Enums;

enum UserAccountStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Actif'),
            self::Disabled => __('Désactivé'),
            self::Archived => __('Archivé'),
        };
    }
}
