# silverstripe-elemental-json

Configure a page's Elemental blocks from JSON or YAML files committed with the code, alongside pages that are edited in the CMS as normal.

- A file's **path is the page's slug**; nested folders are nested pages.
- Blocks are **created and updated on `dev/build`** (every deploy runs it), or with `sake tasks:elemental-json-sync`.
- A matched page shows a notice in the CMS and its **block editor is hidden**. The file owns the whole block area.
- Assets are **only files committed next to the content**, imported into a read-only `Managed` folder in the media library.
- **Form submissions are never deleted by a sync.**
- `sake tasks:elemental-json-export` writes an existing CMS site out as these files (and copies its images), and the first sync **adopts** the existing blocks in place rather than recreating them.

## Layout

```text
app/content/
  pages/
    index.yml            -> /            (slug "home")
    pricing.yml          -> /pricing
    help/index.yml       -> /help
    help/billing.json    -> /help/billing
  assets/
    images/hero.png      -> referenced as @images/hero.png
```

`.json`, `.yml` and `.yaml` all work, with the same schema. The folder is `content_dir` in `_config/elemental-json.yml`.

## A page file

```yaml
# app/content/pages/help/billing.yml
title: Billing help            # page fields: title, menu_title, meta_description, show_in_menus, show_in_search
create: true                   # create the page (and missing parents) if it does not exist; otherwise the sync errors
slug: help/billing             # optional; defaults to the file path
class: Page                    # optional page class used when creating
blocks:
  - id: hero                   # required, unique in the file: how re-syncs find (and update in place) this block
    type: hero                 # class name, configured alias, or derived alias (HeroBlock => hero)
    title: Billing, sorted
    lead: How trials and renewals work.
    hero_variant: split        # db fields: snake_case, camelCase or the field name
    media: '@images/billing.png'   # has_one to a file: a committed asset
    buttons:                   # has_many: a list; items are matched by `name` if they have one, else by position
      - {text: Start trial, url: /signup, variant: primary}
  - id: faq
    type: faq
    items:
      - {question: Do I get a trial?, answer: '14 days.'}
```

Anything that is not a field or relation on the block is an error that names the file and path.

## Forms (Elemental UserForms)

With `dnadesign/silverstripe-elemental-userforms` a form block can be configured too. **List `fields` before `recipients`.**

```yaml
  - id: contact
    type: form
    title: Contact us
    submit_button_text: Send
    on_complete_message: Thanks, we will reply soon.
    fields:
      - {type: text, name: name, title: Name, required: true}
      - {type: email, name: email, title: Email, required: true}
      - {type: dropdown, name: topic, title: Topic, options: [{title: General, value: general}, {title: Bug, value: bug}]}
      - {type: text, name: message, title: Message, rows: 5}
    recipients:
      - {email_address: support@example.com, email_subject: New enquiry, email_from: no-reply@example.com}
      - {send_email_to_field: email, email_subject: We got your message, email_from: support@example.com}   # copy to the submitter
```

Submissions are protected:

- Fields are matched by `name`, never recreated when unchanged, so their identity (and conditional logic) is stable. A submission stores its own copy of each name, title and value, so removing or renaming a field later does not change old submissions.
- `Submissions` can not be set from a file.
- A sync **refuses to remove or change the type of a block that has submissions**. That page fails with an error, nothing about it changes, and other pages still sync. Keep the block in the file, or export and delete the submissions in the CMS first.
- The block editor is hidden on managed pages, so the page gets a **Form submissions** tab (view, CSV export, delete) listing the submissions of every form block on it.

## Assets

Only committed files. Reference them as `@path/inside/assets`:

- they are imported into the media library under `Managed/<same folders>` (setting `asset_folder`), published, and made **read-only** in the CMS;
- a file is matched by its path and only replaced when its content changes, so re-syncs never duplicate;
- a missing file or a path outside `assets/` is an error.

## What happens in the CMS

On a page that has a file, the block editor is replaced by a notice that names the file. Title, URL and settings stay editable (the file wins for the ones it sets). If the file is deleted, the page is released: its blocks stay as they were and it becomes editable again.

## Running it

```bash
sake db:build                                  # syncs after the build (errors throw in dev, are logged in live)
sake tasks:elemental-json-sync --dry-run
sake tasks:elemental-json-sync --page=help/billing --force
```

An invalid file in live is logged and that page keeps its current content, so a typo cannot break a deploy.

## Settings (`_config/elemental-json.yml`)

| Key | Default | |
| --- | --- | --- |
| `content_dir` | `app/content` | folder with `pages/` and `assets/` |
| `asset_folder` | `Managed` | media library folder for imported assets |
| `page_class` | `Page` | class for pages a file creates |
| `publish` | `true` | publish pages (and blocks) after syncing, if the page is already published or new |
| `types` | `{}` | extra block aliases, `alias: Class` |
