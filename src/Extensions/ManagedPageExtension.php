<?php

namespace BradTipper\ElementalJson\Extensions;

use BradTipper\ElementalJson\Content\ContentLoader;
use BradTipper\ElementalJson\ElementalJson;
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

        // Elemental is usually applied to Page, so its extension can run after this one (which is on SiteTree) and add
        // the block editor back. Run after every extension has had its say.
        $owner = $this->owner;
        $apply = function (FieldList $fields) use ($owner): void {
            $this->hideEditor($fields, $owner);
        };
        (function () use ($apply) {
            $this->afterExtending('updateCMSFields', $apply);
        })->call($owner);
    }

    /** Visibility lives on the Settings tab, which is a separate form from the main fields. */
    protected function updateSettingsFields(FieldList $fields): void
    {
        if (!$this->owner->ManagedSource) {
            return;
        }
        foreach ($this->fileSettings($this->owner) as $name) {
            $this->lock($fields, $name);
        }
    }

    private function hideEditor(FieldList $fields, SiteTree $owner): void
    {
        $fields->removeByName('ElementalArea');
        $fields->removeByName('Content');

        // Bookkeeping, and every page setting the file sets: shown but not editable, since the next sync would revert it.
        foreach (['ManagedSource', 'ManagedHash'] as $name) {
            $this->lock($fields, $name);
        }
        $this->lock($fields, 'Title');
        foreach ($this->fileSettings($owner) as $name) {
            $this->lock($fields, $name);
        }

        $source = Convert::raw2xml($owner->ManagedSource);
        $fields->findOrMakeTab('Root.Main')->unshift(LiteralField::create(
            'ManagedContentNotice',
            '<div class="alert alert-info" role="status"><strong>This page\'s content is configured in code.</strong> '
            . "Its title and blocks come from <code>$source</code> and are rebuilt on every deploy, so they cannot be edited here. "
            . 'Edit the file and deploy to change them. URL and other settings below can still be edited.</div>'
        ));

        $submissions = $this->submissionsGrid($owner);
        if ($submissions) {
            $fields->addFieldToTab('Root.FormSubmissions', $submissions);
            $fields->findOrMakeTab('Root.FormSubmissions')->setTitle('Form submissions');
        }
    }

    private function lock(FieldList $fields, string $name): void
    {
        $field = $fields->dataFieldByName($name);
        if ($field) {
            $fields->replaceField($name, $field->performReadonlyTransformation());
        }
    }

    /** @return list<string> CMS field names of the page settings that the page's content file sets */
    private function fileSettings(SiteTree $owner): array
    {
        $map = [
            'menu_title' => 'MenuTitle',
            'meta_description' => 'MetaDescription',
            'show_in_menus' => 'ShowInMenus',
            'show_in_search' => 'ShowInSearch',
        ];
        try {
            $path = ElementalJson::contentDir() . '/' . $owner->ManagedSource;
            $data = (new ContentLoader())->loadFile($path, ElementalJson::pagesDir())->data;
        } catch (Throwable $e) {
            return array_values($map);
        }
        return array_values(array_intersect_key($map, $data));
    }

    /** Submissions of every form block on this page, across all of them, so they stay reachable while the editor is hidden. */
    private function submissionsGrid(SiteTree $owner): ?GridField
    {
        $formClass = 'DNADesign\\ElementalUserForms\\Model\\ElementForm';
        $submittedClass = 'SilverStripe\\UserForms\\Model\\Submission\\SubmittedForm';
        if (!class_exists($formClass) || !class_exists($submittedClass) || !$owner->ElementalAreaID) {
            return null;
        }
        $formIds = BaseElement::get()
            ->filter(['ParentID' => $owner->ElementalAreaID, 'ClassName' => ClassInfo::subclassesFor($formClass)])
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
