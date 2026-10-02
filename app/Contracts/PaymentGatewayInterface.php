<?php

namespace App\Contracts;

interface PaymentGatewayInterface
{
    public function charge(array $data): array;

    public function refund(string $transactionId, ?float $amount = null): array;

    public function getTransaction(string $transactionId): array;
}
