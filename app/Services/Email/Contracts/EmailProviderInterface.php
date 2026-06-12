<?php

namespace App\Services\Email\Contracts;

interface EmailProviderInterface
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function send(string $email, string $subject, string $template, array $data = [], array $metadata = []): array;

    public function name(): string;
}
