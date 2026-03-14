<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\ValueObject;

enum DocumentType: string
{
    case Cni = 'cni';
    case Passeport = 'passeport';
    case TitreDeSejour = 'titre_de_sejour';
}
