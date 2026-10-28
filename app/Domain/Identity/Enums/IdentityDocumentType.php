<?php

namespace App\Domain\Identity\Enums;

/**
 * Identity documents accepted to verify a member (or the legal representative of a legal person).
 */
enum IdentityDocumentType: string
{
    case NationalIdCard = 'cni';
    case Passport = 'passport';
    case VoterCard = 'voter_card';
    case Anid = 'anid';

    public function label(): string
    {
        return match ($this) {
            self::NationalIdCard => "Carte nationale d'identité",
            self::Passport => 'Passeport',
            self::VoterCard => "Carte d'électeur",
            self::Anid => 'Carte ANID',
        };
    }

    /**
     * A passport is checked on its photo page alone; the cards are checked on both sides.
     */
    public function requiresBackSide(): bool
    {
        return $this !== self::Passport;
    }
}
