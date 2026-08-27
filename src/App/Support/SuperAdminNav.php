<?php

namespace Keel\App\Support;

/**
 * The operator panel's navigation.
 *
 * Lives here rather than on the panel's base controller because
 * ActivityController::platformIndex renders a panel page without extending it.
 * One list, so a new panel page cannot end up reachable from some pages and not
 * others.
 *
 * Deliberately not in the product's $navLinks: this is an internal tool and must
 * never appear for an ordinary user.
 */
class SuperAdminNav
{
    /**
     * @return array<string, string>
     */
    public function links(): array
    {
        return [
            'Operations' => '/super-admin',
            'Business' => '/super-admin/metrics',
            'Accounts' => '/super-admin/organizations',
            'Support' => '/super-admin/support',
            'Audit' => '/super-admin/audit',
            'Activity' => '/super-admin/activity',
        ];
    }
}
