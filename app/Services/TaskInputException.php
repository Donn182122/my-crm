<?php

declare(strict_types=1);
namespace App\Services;
class TaskInputException extends \RuntimeException
{
    public function __construct(public array $errors,public int $status=422){parent::__construct('Task request needs review.');}
}
