# Regional Blog

The Public Relations (PR) team of each region writes short articles about what is going on in the region (activities, meetings,
workshops, training, announcements). Every member of staff reads the articles of **their own region**, and only that region.
Code: `App\Services\Blog\BlogVisibility` (who sees and writes what), `BlogPostService` (every change), model `BlogPost`;
Livewire `Blog\Feed`, `ManagePosts`, `PostForm`; controller `Blog\BlogController`; views `resources/views/blog/` and
`resources/views/livewire/blog/`. Switch: `GWL_BLOG_MODULE_ENABLED` (default on; when off the routes are not registered and the
"Blog" tab is hidden).

## Who can do what

| Person | Reads | Writes |
|---|---|---|
| Any member of staff (every role, Global Admin and HR included) | Published articles of their own region (`employees.region_id`) | Nothing |
| PR Officer (`pr_officer` role, permission `blog.manage_posts`) | The same, plus the drafts of their region | Create, edit, publish, unpublish, pin and delete articles of **their own region only** |
| `super_admin` | Every region (a region filter is offered) | Any region; picks the region on a new article |
| A login with no employee record, or an employee with no region | Nothing (the page says the account is not linked to a region) | Nothing |

There is **no Head Office or manager exception**: the region id is the only thing compared. Head Office is a district, and its staff
carry that district's `region_id`, so they read the blog of that region like everyone else in it.

The `pr_officer` role is **not** `ict_assignable`: only Global Admin / super_admin give it out (same as the Health & Safety roles).
Grant it to one or two people per region.

## What an article holds

Title, kind (Activity, Meeting, Workshop, Training, Announcement, Other), optional summary, the article (Markdown, see below), up to
five tags, an optional date of the activity, venue and district (the district must be in the article's region), an optional cover
photo, and a status (draft or published) with a "pinned" flag. The region is fixed when the article is first saved and never changes.

* **Markdown, with raw HTML stripped and unsafe links (`javascript:`, `data:`) refused** (`BlogPost::bodyHtml()`), so an author can
  format an article but can never put markup or script in a colleague's page. Never print the body with anything else.
* **Pinned articles come first**, then newest published first. Only a published article can be pinned; unpublishing unpins.
* The published date shown is the **first** publication; republishing after an edit keeps it.
* **Tags** are typed on one line, lower-cased, de-duplicated and capped (`BlogPostService::cleanTags()`); search matches title,
  summary, body, venue and tags.

## Cover photos

JPG, PNG or WebP up to `GWL_BLOG_COVER_MAX_MB` (default 3). They are stored on the **private** `local` disk under `blog/covers/` and
have no public URL: the page asks `blog.cover`, which applies the same region check as the article (404 otherwise). Replacing,
removing or deleting an article deletes the file. Photos are not re-encoded, so a picture's hidden camera details stay in the file.

## Rules that are easy to get wrong

* **Visibility lives only in `BlogVisibility`** (feed scope, `canRead`, `canManageIn`, `scopeManageable`). The feed, the article page, the
  cover route, the manage list and the service all call it; never filter by region in Blade.
* **Every change goes through `BlogPostService`**, which re-reads the article `lockForUpdate` inside a transaction, authorises the actor
  on that copy, and writes the audit entry (`blog_post_created/updated/published/unpublished/pinned/unpinned/deleted`, module `blog`).
* An article the user may not read answers **404**, the same as one that does not exist, so one region cannot learn what another
  has published. The Livewire components resolve ids inside the actor's own part of the blog and never trust an id from the browser.
* The blog scoping trait (`Livewire\Blog\Concerns\ScopesBlogByActor`) is, like the others, a separate trait that only wraps the service.
* Module access is `true` for every role (migration `2026_10_13_000002_seed_regional_blog_module_access`, repeated for fresh installs
  and tests by `RegionalBlogRolePermissionSeeder` and `ModuleAccessSeeder`). A role created later in the UAC screen gets a
  `module_access` row like any other module; set it to allowed if the new role should read the blog.

## Not built (ideas for later)

In-app notices when an article is published; comments; a "latest from your region" card on the dashboard; scheduled publishing;
attachments (programmes, slides); a rich-text editor.
