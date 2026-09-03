<?php

namespace App\Services\Reporting\Contracts;

interface AdministrativeReportBuilder
{
    public function key(): string;
    public function label(): string;
    public function description(): string;
    public function build(array $filters): array;
}
