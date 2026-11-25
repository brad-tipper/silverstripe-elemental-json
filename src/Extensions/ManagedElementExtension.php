<?php

namespace BradTipper\ElementalJson\Extensions;

use DNADesign\Elemental\Models\BaseElement;
use SilverStripe\Core\Extension;

/**
 * The `id` a block has in its content file. It is how a re-sync finds the block again and updates it in place.
 *
 * @property string|null $ManagedKey
 * @extends Extension<BaseElement>
 */
class ManagedElementExtension extends Extension
{
    private static array $db = [
        'ManagedKey' => 'Varchar(100)',
    ];

    private static array $indexes = [
        'ManagedKey' => true,
    ];
}
