<?php

namespace Keel\App\Controllers\SuperAdmin;

use Keel\App\Services\OperationsMonitor;
use Keel\Core\Request;

/**
 * Tier one: is anything broken.
 *
 * The panel's landing page, because it is the question worth asking first.
 */
class OperationsController extends BaseController
{
    public function index(Request $request): void
    {
        $this->panel('super-admin.operations', 'operations.view', [
            'title' => 'Operations — Duely',
            'ops' => (new OperationsMonitor())->snapshot(),
        ]);
    }
}
