<?php

namespace App\Services\Reporting\Contracts;

interface ReportRenderer
{
    public function format(): string;
    public function extension(): string;
    public function mimeType(): string;
    public function render(array $report): string;
}
