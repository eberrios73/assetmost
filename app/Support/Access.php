<?php

namespace App\Support;

use App\Models\RolePermission;
use Illuminate\Support\Facades\Cache;

/**
 * Roles and what they may do.
 *
 * The line that matters here is passwords. Everything else — assets, people, vendors,
 * licences, docs — is operational data that plenty of people legitimately need. Stored
 * credentials are different in kind: they are the organisation's keys, they live in an
 * encrypted column, and reading one needs the escrow key. So the levels are not a tidy
 * ladder where more senior means more visible. They are one hard question — do you hold
 * the keys? — with an ordinary permission scale beside it. That is why Operations sits
 * above User on everything and still cannot read a password.
 *
 * Defaults live here; the Roles & access screen writes overrides to role_permissions.
 * SuperAdmin is deliberately not overridable — the screen that edits permissions cannot
 * be allowed to remove the permission to edit permissions.
 */
class Access
{
    public const USER = 'User';
    public const OPERATIONS = 'Operations';
    public const IT_ADMIN = 'IT Admin';
    public const SUPER_ADMIN = 'SuperAdmin';

    /** Assignable roles, least privileged first. */
    public const ROLES = [self::USER, self::OPERATIONS, self::IT_ADMIN, self::SUPER_ADMIN];

    /** The one permission the escrow key gates. Checking it is not enough on its own. */
    public const REVEAL = 'logins.reveal';

    /**
     * Every capability the app checks, in display order.
     * `keyed` marks a permission that also requires the escrow key, not just a tick.
     */
    public const PERMISSIONS = [
        ['key' => 'people.view',     'group' => 'People',      'label' => 'View staff and vendors'],
        ['key' => 'people.edit',     'group' => 'People',      'label' => 'Add and edit staff and vendors'],
        ['key' => 'assets.view',     'group' => 'Assets',      'label' => 'View devices, locations, rooms'],
        ['key' => 'assets.edit',     'group' => 'Assets',      'label' => 'Add and edit assets'],
        ['key' => 'licenses.view',   'group' => 'Licensing',   'label' => 'View products and licences'],
        ['key' => 'licenses.edit',   'group' => 'Licensing',   'label' => 'Add and edit products and licences'],
        ['key' => 'tasks.view',      'group' => 'Tasks & docs', 'label' => 'View tasks and docs'],
        ['key' => 'tasks.edit',      'group' => 'Tasks & docs', 'label' => 'Add and edit tasks and docs'],
        ['key' => 'logins.view',     'group' => 'Credentials', 'label' => 'View credential records (not the password)'],
        ['key' => self::REVEAL,      'group' => 'Credentials', 'label' => 'Reveal passwords', 'keyed' => true],
        ['key' => 'companies.manage', 'group' => 'Administration', 'label' => 'Add and edit companies'],
        ['key' => 'settings.manage', 'group' => 'Administration', 'label' => 'Change settings and roles'],
    ];

    /** Shipped defaults. Anything absent is denied. */
    public const DEFAULTS = [
        self::USER => [],
        self::OPERATIONS => [
            'people.view', 'people.edit', 'assets.view', 'assets.edit',
            'licenses.view', 'licenses.edit', 'tasks.view', 'tasks.edit', 'logins.view',
        ],
        self::IT_ADMIN => [
            'people.view', 'people.edit', 'assets.view', 'assets.edit',
            'licenses.view', 'licenses.edit', 'tasks.view', 'tasks.edit',
            'logins.view', self::REVEAL, 'companies.manage',
        ],
        // SuperAdmin is implicit — see allows().
    ];

    public static function keys(): array
    {
        return array_column(self::PERMISSIONS, 'key');
    }

    /** Permissions that additionally require the escrow key. */
    public static function keyed(): array
    {
        return array_column(array_filter(self::PERMISSIONS, fn ($p) => $p['keyed'] ?? false), 'key');
    }

    /** The effective matrix: defaults with any saved overrides applied. */
    public static function matrix(): array
    {
        return Cache::rememberForever('access.matrix', function () {
            $matrix = [];
            foreach (self::ROLES as $role) {
                $defaults = self::DEFAULTS[$role] ?? self::keys();   // SuperAdmin: everything
                foreach (self::keys() as $key) {
                    $matrix[$role][$key] = in_array($key, $defaults, true);
                }
            }
            foreach (RolePermission::all() as $row) {
                if (isset($matrix[$row->role][$row->permission])) {
                    $matrix[$row->role][$row->permission] = (bool) $row->allowed;
                }
            }
            $matrix[self::SUPER_ADMIN] = array_fill_keys(self::keys(), true);
            return $matrix;
        });
    }

    public static function forget(): void
    {
        Cache::forget('access.matrix');
    }

    /** Does this role hold this permission? Ignores the escrow key — see canReveal(). */
    public static function allows(?string $role, string $permission): bool
    {
        if ($role === self::SUPER_ADMIN) {
            return true;
        }
        return (bool) (self::matrix()[$role][$permission] ?? false);
    }
}
