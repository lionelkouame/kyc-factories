<?php

declare(strict_types=1);

namespace App\Application\Repository;

use App\Domain\KycRequest\Aggregate\KycRequest;
use App\Domain\KycRequest\ValueObject\KycRequestId;

interface KycRequestRepositoryPort
{
    public function get(KycRequestId $id): KycRequest;

    public function save(KycRequest $kycRequest): void;
}
