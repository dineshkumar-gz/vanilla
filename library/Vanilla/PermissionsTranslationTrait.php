<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2018 Vanilla Forums Inc.
 * @license GPL-2.0-only
 */

namespace Vanilla;

use Vanilla\Utility\NameScheme;
use Vanilla\Permissions;

/**
 * Translates permission names between the old and new formats.
 *
 * Vanilla is changing the naming convention of permissions so this class is necessary to aid in that.
 */
trait PermissionsTranslationTrait {
    /** @var NameScheme */
    private $nameScheme;

    /** @var array Groups of permissions that can be consolidated into one. */
    private $consolidatedPermissions = [
        'discussions.moderate' => ['discussions.announce', 'discussions.close', 'discussions.sink'],
        'discussions.manage' => ['discussions.delete', 'discussions.edit']
    ];

    /** @var array Permissions that have been deprecated and should no longer be used. */
    private $deprecatedPermissions = [
        'Garden.Activity.Delete',
        'Garden.Activity.View',
        'Garden.SignIn.Allow',
        'Garden.Curation.Manage',
        'Vanilla.Approval.Require',
        'Vanilla.Comments.Me'
    ];

    /** @var array A static mapping of updated permission names. */
    private $renamedPermissions = [
        'Conversations.Moderation.Manage' => 'conversations.moderate',
        'Email.Comments.Add' => 'comments.email',
        'Email.Conversations.Add' => 'conversations.email',
        'Email.Discussions.Add' => 'discussions.email',
        'Garden.Moderation.Manage' => 'community.moderate',
        'Garden.NoAds.Allow' => 'noAds.use',
        'Garden.Settings.Manage' => 'site.manage',
        'Garden.Users.Approve' => 'applicants.manage',
        'Groups.Group.Add' => 'groups.add',
        'Groups.Moderation.Manage' => 'groups.moderate',
        'Plugins.Attachments.Upload.Allow' => 'uploads.add',
        'Reputation.Badges.Give' => 'badges.moderate',
        'Vanilla.Tagging.Add' => 'tags.add',
    ];

    /** @var array These permissions should not be renamed. */
    private $fixedPermissions = [
        'Reactions.Negative.Add',
        'Reactions.Positive.Add'
    ];

    /**
     * Collapse multiple permissions down into a single one, where possible.
     *
     * @param array $permissions An array of permissions.
     * @return array
     */
    private function consolidatePermissions(array $permissions) {
        $result = $permissions;

        foreach ($this->consolidatedPermissions as $name => $perms) {
            $pass = 0;
            $total = count($perms);
            foreach ($perms as $currentPerm) {
                if (!array_key_exists($currentPerm, $permissions)) {
                    // If a key isn't present, assume this is the wrong permission type (e.g. global, category).
                    continue 2;
                } elseif ($permissions[$currentPerm]) {
                    $pass++;
                }
            }

            if ($pass == $total) {
                $val = true;
            } elseif ($pass == 0) {
                $val = false;
            } else {
                $val = null;
            }

            // If we had all or none of the child permissions, remove them. Only include the parent.
            if ($val !== null) {
                foreach ($perms as $currentPerm) {
                    unset($result[$currentPerm]);
                }
            }

            $result[$name] = $val;
            unset($currentPerm, $pass);
        }

        return $result;
    }

    /**
     * Given a permissions object, return its data array in the explicit-scope format.
     *
     * @param \Vanilla\Permissions $permissions
     * @return array
     */
    private function formatPermissions(Permissions $permissions) {
        $raw = $permissions->getPermissions();
        $global = [];
        $categories = [];

        /**
         * Format an array of permission names.
         *
         * @param array $perms
         * @return array
         */
        $format = function (array $perms) {
            $result = [];

            foreach ($perms as $name => $val) {
                // Discard deprecated permissions.
                if ($this->isPermissionDeprecated($name)) {
                    continue;
                }
                // Rename using mapping or standard renaming (i.e. Addon.Resource.Config to Resource.Config).
                $name = $this->renamePermission($name);
                $result[$name] = $val;
            }
            // Collapse multiple permissions into single value, where relevant.
            $result = $this->consolidatePermissions($result);

            ksort($result);
            return $result;
        };

        // Step one: separate global and per-resource (e.g. category) permissions.
        foreach ($raw as $key => $val) {
            if (is_string($val)) {
                // Permissions objects only contain what permissions we have, so the value will always be true.
                $global[$val] = true;
            } elseif (is_array($val)) {
                foreach ($val as $resourceID) {
                    $rowPermissions = $categories[$resourceID] ?? [];
                    $rowPermissions[$key] = true;
                    $categories[$resourceID] = $rowPermissions;
                }
            }
        }

        // Step two: add in global permissions, formatted using relevant translations.
        $result = [
            [
                'type' => 'global',
                'permissions' => $format($global)
            ]
        ];

        // Step three: add in per-resource permissions, also formatted.
        foreach ($categories as $categoryID => $permissionSlugs) {
            // Default category (-1) permissions now fall under an ID of zero (0).
            $categoryPermissions = [
                'id' => $categoryID == -1 ? 0 : $categoryID,
                'type' => 'category'
            ];

            $categoryPermissions['permissions'] = $format($permissionSlugs);
            $result[] = $categoryPermissions;
        }

        /**
         * Step four: return the result. We should have an array of permission rows. Each row containing a
         * type (either "global" or a specific resource), a permissions key containing an associative array of
         * permission slugs keys with boolean values indicating whether or not they are granted and, optionally, an id
         * key to represent a specific resource, if this is a per-resource set of permissions.
         */
        return $result;
    }

    /**
     * Determine if a permission slug is deprecated.
     *
     * @param string $permission The name of the permission to check.
     * @return bool
     */
    private function isPermissionDeprecated($permission) {
        $result = in_array($permission, $this->deprecatedPermissions);
        return $result;
    }

    /**
     * Rename a legacy Vanilla permission slug.
     *
     * @param string $permission
     * @return string
     */
    private function renamePermission($permission) {
        if (array_key_exists($permission, $this->renamedPermissions)) {
            // Already got a mapping for this permission? Go ahead and use it.
            $result = $this->renamedPermissions[$permission];
        } else {
            // Time to format the permission name.
            $segments = explode('.', $permission);

            // Pop the application off the top, if it seems safe to do so.
            if (!in_array($permission, $this->fixedPermissions) && count($segments) == 3) {
                unset($segments[0]);
            }

            foreach ($segments as &$seg) {
                $seg = $this->nameScheme->convert($seg);
            }

            // Cache the renamed permission for this request.
            $result = implode('.', $segments);
            $this->renamedPermissions[$permission] = $result;
        }

        return $result;
    }
}
