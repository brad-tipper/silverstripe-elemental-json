<?php

namespace BradTipper\ElementalJson\Sync;

/** What a sync did, per page slug. */
final class SyncReport
{
    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $updated = [];

    /** @var list<string> */
    public array $unchanged = [];

    /** @var list<string> pages released back to the CMS because their file was removed */
    public array $released = [];

    /** @var array<string, string> slug (or file) => message */
    public array $errors = [];

    public function summary(): string
    {
        return sprintf(
            '%d created, %d updated, %d unchanged, %d released, %d failed',
            count($this->created),
            count($this->updated),
            count($this->unchanged),
            count($this->released),
            count($this->errors),
        );
    }
}
