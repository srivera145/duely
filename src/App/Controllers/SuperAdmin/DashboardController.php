<?php

namespace Keel\App\Controllers\SuperAdmin;

use Keel\App\Services\PlatformMetrics;
use Keel\Core\Request;

/**
 * Tier two: how the business is doing.
 *
 * Aggregates only. A tenant is a name and a number here, never its contents.
 */
class DashboardController extends BaseController
{
    public function metrics(Request $request): void
    {
        $this->panel('super-admin.metrics', 'metrics.view', [
            'title' => 'Business — Duely',
            'metrics' => (new PlatformMetrics())->snapshot(),
        ]);
    }
}
