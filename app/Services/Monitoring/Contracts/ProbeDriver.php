<?php

namespace App\Services\Monitoring\Contracts;

use App\Models\Target;
use App\Services\Monitoring\ProbeResult;

interface ProbeDriver
{
    public function probe(Target $target, ?int $samples = null, ?int $timeout = null): ProbeResult;
}
