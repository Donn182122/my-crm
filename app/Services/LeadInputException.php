<?php

declare(strict_types=1);
namespace App\Services;
class LeadInputException extends \RuntimeException
{
    public function __construct(public readonly array $errors,public readonly int $status=422){parent::__construct(implode(' ',$errors));}
}
