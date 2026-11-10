<?php

declare(strict_types=1);
namespace App\Services;

class ClientValidationException extends \RuntimeException
{
    public function __construct(public readonly array $errors) { parent::__construct('Please correct the highlighted information.'); }
}
