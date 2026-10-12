<?php

namespace ChristianHnl\PowerMetrics\Policies;

use App\Policies\DefaultAdminPolicies;

class PowerSourcePolicy
{
    use DefaultAdminPolicies;

    protected string $modelName = 'power_source';
}
