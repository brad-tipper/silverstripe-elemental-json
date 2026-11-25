<?php

namespace BradTipper\ElementalJson\Extensions;

use SilverStripe\Assets\File;
use SilverStripe\Core\Extension;
use SilverStripe\Security\Member;

/**
 * Files (and folders) imported from the content directory are read-only in the CMS: the committed file is the source.
 *
 * @property string|null $ManagedPath path relative to the content assets directory
 * @property string|null $ManagedHash sha1 of the committed file at import
 * @extends Extension<File>
 */
class ManagedFileExtension extends Extension
{
    private static array $db = [
        'ManagedPath' => 'Varchar(255)',
        'ManagedHash' => 'Varchar(40)',
    ];

    private static array $indexes = [
        'ManagedPath' => true,
    ];

    public function canEdit(?Member $member = null): ?bool
    {
        return $this->owner->ManagedPath ? false : null;
    }

    public function canDelete(?Member $member = null): ?bool
    {
        return $this->owner->ManagedPath ? false : null;
    }
}
