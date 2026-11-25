<?php

namespace BradTipper\ElementalJson\Extensions;

use DNADesign\Elemental\Models\BaseElement;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\CMS\Model\SiteTree;

/**
 * Marks a page as configured from a content file. In the CMS the block editor is hidden and replaced with a
 * notice naming the file; if the page has form blocks, their submissions get their own tab so they stay reachable.
 *
 * @property string|null $ManagedSource path of the content file, relative to the content directory
 * @property string|null $ManagedHash hash of the file (and its assets) at the last sync
 * @extends Extension<SiteTree>
 */
class ManagedPageExtension extends Extension
{
    private static array $db = [
        'ManagedSource' => 'Varchar(255)',
        'ManagedHash' => 'Varchar(40)',
    ];

    protected function updateCMSFields(FieldList $fields): void
    {
        if (!$this->owner->ManagedSource) {
            return;
        }

        $fields->removeByName('ElementalArea');
        $fields->removeByName('Content');

        $source = Convert::raw2xml($this->owner->ManagedSource);
        $fields->findOrMakeTab('Root.Main')->unshift(LiteralField::create(
            'ManagedContentNotice',
            '<div class="alert alert-info" role="status"><strong>This page\'s content is configured in code.</strong> '
            . "Its blocks come from <code>$source</code> and are rebuilt on every deploy, so they cannot be edited here. "
            . 'Edit the file and deploy to change them. Title, URL and settings below can still be edited, except where the file sets them.</div>'
        ));

        $submissions = $this->submissionsGrid();
        if ($submissions) {
            $fields->addFieldToTab('Root.FormSubmissions', $submissions);
            $fields->findOrMakeTab('Root.FormSubmissions')->setTitle('Form submissions');
        }
    }

    /** Submissions of every form block on this page, across all of them, so they stay reachable while the editor is hidden. */
    private function submissionsGrid(): ?GridField
    {
        $formClass = 'DNADesign\\ElementalUserForms\\Model\\ElementForm';
        $submittedClass = 'SilverStripe\\UserForms\\Model\\Submission\\SubmittedForm';
        if (!class_exists($formClass) || !class_exists($submittedClass) || !$this->owner->ElementalAreaID) {
            return null;
        }
        $formIds = BaseElement::get()
            ->filter(['ParentID' => $this->owner->ElementalAreaID, 'ClassName' => ClassInfo::subclassesFor($formClass)])
            ->column('ID');
        if (!$formIds) {
            return null;
        }
        $config = GridFieldConfig_RecordViewer::create()
            ->addComponent(new GridFieldExportButton('buttons-before-left'))
            ->addComponent(new GridFieldDeleteAction());
        return GridField::create(
            'FormSubmissions',
            'Submissions',
            DataObject::get($submittedClass)->filter(['ParentID' => $formIds])->sort('Created', 'DESC'),
            $config
        );
    }
}
