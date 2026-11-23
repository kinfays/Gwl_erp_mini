# Regional Blog

The Public Relations (PR) team of each region writes articles about what is going on in the region (activities, meetings,
workshops, training, announcements), with pictures, galleries and tables. Every member of staff reads the articles of **their own
region**, and only that region.

The blog is **not a module tab**. It is a standalone, full-page, magazine-style blog, opened in a **new browser tab** from the
**Blog** link at the right of the ERP header (next to the bell and the account menu). It shares the ERP login but has its own
layout (no ERP sidebar, no module tabs) and is **not public**: the same login, module and region checks apply as ever.

Code: `App\Services\Blog\BlogVisibility` (who sees and writes what), `BlogPostService` (every change to an article),
`BlogContentService` (editor document -> safe HTML), `BlogMediaService` (pictures), `BlogFeedService` (reader-page queries),
`BlogReadService` (unread count, read tracking), `BlogAnnouncementService` (login announcements); models
`BlogPost`, `BlogMedia`, `BlogPostRevision`, `BlogPostRead`, `BlogAnnouncementDismissal`; `Blog\BlogController`, `Blog\BlogMediaController`;
listener `Listeners\Blog\ArmLoginAnnouncements`; Livewire `Blog\ManagePosts` and `Blog\PostForm` (the editor), and the three small
components `Blog\HeaderLink` (the ERP header's Blog link with its badge), `Blog\LoginAnnouncements` (the login window) and
`Blog\AnnouncementStrip` (the strip on the blog home); layout `layouts/blog.blade.php` (component `x-blog-layout`); views in `resources/views/blog/` and
`resources/views/livewire/blog/`; front end `resources/css/blog.css`, `blog-editor.css`, `resources/js/blog-lightbox.js`,
`blog-editor.js`, `blog-editor-nodes.js`. Switch: `GWL_BLOG_MODULE_ENABLED` (default on; when off the routes are not registered and
the header link is hidden).

## Who can do what

| Person | Reads | Writes |
|---|---|---|
| Any member of staff (every role, Global Admin and HR included) | Published articles of their own region (`employees.region_id`) | Nothing |
| PR Officer (`pr_officer` role, permission `blog.manage_posts`) | The same, plus the drafts and pictures of their region | Create, edit, publish, unpublish, pin and delete articles of **their own region only**; upload pictures to it |
| `super_admin` | Every region (a region filter is offered) | Any region; picks the region on a new article |
| A login with no employee record, or an employee with no region | Nothing (the page says the account is not linked to a region) | Nothing |

There is **no Head Office or manager exception**: the region id is the only thing compared. Head Office is a district, and its staff
carry that district's `region_id`, so they read the blog of that region like everyone else in it.

The `pr_officer` role is **not** `ict_assignable`: only Global Admin / super_admin give it out. Grant it to one or two people per
region. Module access `blog` is allowed for every role, and no permission was added for the editor: `blog.manage_posts` covers it.

## Where things are (routes)

| Route | Address | What |
|---|---|---|
| `blog.home` | `/blog` | Front page: lead story, secondary stories, latest stream, sidebar |
| `blog.category` | `/blog/category/{kind}` | One kind of article (Activities, Meetings, Workshops, Training, Announcements, Other) |
| `blog.tag` | `/blog/tag/{tag}` | Articles with a tag |
| `blog.district` | `/blog/district/{id}` | Articles of a district (a district of another region answers 404) |
| `blog.search` | `/blog/search?q=` | Title, summary, text, venue and tags |
| `blog.gallery` | `/blog/gallery` | The photo gallery |
| `blog.show` | `/blog/posts/{id}/{slug?}` | An article. The id decides; the slug only makes the link readable |
| `blog.media` | `/blog/media/{id}/{thumb\|medium\|large}` | A picture, after the region check |
| `blog.cover` | `/blog/cover/{id}` | An older, single-file cover (articles from before pictures had sizes) |
| `blog.manage`, `blog.create`, `blog.edit` | `/blog/manage...` | The writers' list and editor (`blog.manage_posts`) |
| `blog.media.upload` | `POST /blog/manage/media` | The editor's picture upload (CSRF, `throttle:60,1`, `blog.manage_posts`, a region the writer manages) |
| `blog.read-all` | `POST /blog/read-all` | "Mark all as read" on the front page (CSRF, `throttle:30,1`); redirects to `blog.home` with a notice |

Every list page is a normal GET with the filters in the address, so a link can be shared inside the region (an ordinary reader's
`?region=` is ignored). A guest who opens any of them is sent to the login and brought back afterwards.

## The reading layout

A masthead with the company logo and "<Region> Regional Blog", a search box, "Back to ERP", the theme switch and an account menu; for
writers a "Manage" link. Under it a section strip: All, Activities, Meetings, Workshops, Training, Announcements, Other, Photo Gallery.

* **Front page:** the pinned (else newest) article is the lead story, beside up to three secondary stories; below, a dense "Latest"
  stream (thumbnail, kind, headline, two-line teaser, date, district, reading time) with Newer / Older paging, and a sidebar with
  **Upcoming events** (future `event_date`, soonest first), **Pinned**, **Browse by district** and **Topics** (tags). A story is never shown
  twice on the page. A cover with a **focal point** is cropped around it on cards.
* **Article:** kind, headline, standfirst, byline (author, date, district, reading time), copy-link and print buttons, cover with
  caption and credit, an **event details** box (date, venue, district) when set, the body in a ~70-character reading column with
  figures, galleries, tables and videos, tags, older / newer links, "More from <district>" and related articles. Everything is from
  the **same region**. A print stylesheet drops the chrome and prints the links.
* **Photo gallery:** the pictures of published articles of the region, grouped by article, newest first; each opens the lightbox and the
  title links to the article.
* **Lightbox:** a small dependency-free viewer: arrow keys, Home/End, Esc, Tab kept inside, swipe on touch screens, caption and
  "3 / 12" counter, focus returned to the picture that opened it. It loads only on article and gallery pages.
* Design: serif headlines and body (Source Serif 4, self-hosted from npm) over Atkinson Hyperlegible; the portal's cobalt and lagoon used
  sparingly; follows the portal's light/dark switch; phone first; visible focus; `prefers-reduced-motion` respected; WCAG AA contrast.
  The stylesheet is its own file (no Tailwind, no `app.css`) so the ERP's global rules never reach an article.

## Unread articles (the badge on the Blog link)

The **Blog** link in the ERP header shows a small badge with the number of **published articles of the person's own region that they
have not read**. Nothing shows at 0; the display stops at **99+**; the link's accessible name carries the number ("Regional Blog, 3 unread
articles (opens in a new tab)"). The same number is in the blog masthead ("3 new"), the front page has a **"Mark all as read"** button,
and unread cards and rows carry a **New** marker (lead story, secondary stories and the "Latest" stream and list pages).

**What is "unread"** is written once, in `BlogReadService::unreadQuery()`, and the badge, the masthead, the markers and "mark all" all
call it: a **published** article the person may read (`BlogVisibility::scopeFeed()`, so another region's articles and drafts never
count), **first published within the look-back window** (`GWL_BLOG_UNREAD_LOOKBACK_DAYS`, default 30 days, counted from the start of that
day, so a new user is not shown the archive), **not written by them** (authors count their own articles as read), with **no row in
`blog_post_reads`**. A super_admin has no region of its own, so its number is whatever `scopeFeed()` lets it read: every region inside
the window.

* **An article becomes read when its article page opens** (`BlogController::show`, before the page renders): one `insertOrIgnore`, so a
  refresh, a double click or two tabs never make a second row or an error. A draft, another region's article and an author's own article
  write nothing. "Mark all as read" is **one `INSERT ... SELECT`** (MySQL `INSERT IGNORE`) over the person's unread articles, never a loop.
* **Unpublishing or deleting** removes it from everyone's count at once (a delete also removes the read rows, by cascade). **Republishing
  does not make a read article unread again**: reads stay, and the article keeps its first `published_at`, so the window is measured from the
  first publication (a republished article older than the window is therefore not counted as unread for someone who never read it).
* **Cost.** The count runs in the header of every page for every user, so: the badge is the **deferred Livewire component
  `Blog\HeaderLink`** (the page never waits for it; until it loads the same link is shown without a number); the number is **cached per
  person for `GWL_BLOG_UNREAD_CACHE_SECONDS`** (60); and the query is one indexed `NOT EXISTS` (`blog_posts_unread_index` on
  `region_id, status, published_at` plus the primary key of `blog_post_reads`). A switched-off blog, or an account without the `blog`
  module, does **no blog query at all** (`BlogVisibility::canEnter()` is asked first; a 0 is cached like any number).
* **Cache invalidation.** A person's own key is forgotten when they read something or mark all. A **publish, unpublish or delete** bumps a
  global version that is part of every key (`BlogReadService::forgetEveryone()`, called by `BlogPostService` after the commit), so every
  cached number is stale at once without anyone having to know who is cached. Only numbers are cached.
* **It refreshes without a page reload:** the link polls about once a minute (never faster than the cache) and again when the browser tab
  gets focus or becomes visible, so reading an article in the blog tab updates the ERP tab's badge when the person switches back.

## Login announcements

A PR officer can tick **Show as announcement at login** on a **published** article and choose **Show until** (required) and, optionally,
**Show from**. Any kind of article can be one; it is typically an Announcement. It is **active** when it is published and today is from the
first day (blank = from the day it was published) up to and including the whole last day, **in the app's timezone**
(`config('app.timezone')`). There is **no job**: activeness is worked out at query time (`BlogPost::scopeAnnouncementActive()`), so an
expired announcement simply stops appearing. The two comparisons are `announce_until >= today` and `announce_from < tomorrow` on purpose
(right on MySQL `DATE` and on SQLite, where the date cast is stored with a time, and index-friendly).

* **The window.** When a person **signs in**, the `Login` event (`ArmLoginAnnouncements`) only sets a session flag. The first ERP page that
  renders consumes it in `Blog\LoginAnnouncements::mount()` (the component is in the main layout, and only for someone the header link is
  offered to), so the window shows **once per login**: not on a refresh, not on the next page, not on the blog tab (the blog layout does not
  have the component). Without the flag the component does nothing and asks the database nothing. If a login lands on a blog page first
  (an "intended URL"), the flag waits for the first ERP page after that.
* **What it shows:** the active announcements of the person's **own region** (`BlogVisibility::scopeFeed()`), not dismissed, **newest first**,
  at most `GWL_BLOG_LOGIN_ANNOUNCEMENTS_MAX` (3), one after another with an "n of N" stepper: title, kind and district, teaser, cover thumbnail,
  event date and venue when set, and "Shown until <date>". It is a labelled modal (`role="dialog"`, `aria-modal`, `aria-labelledby`), the
  focus is trapped inside (Alpine `x-trap`), **Esc closes it**, every control is a real button or link, and the entrance animation is only
  played when the person has not asked for reduced motion.
* **Buttons.** **Read full article** opens the article in a new tab (that page marks it read) and moves on; **Don't show again** writes a
  `blog_announcement_dismissals` row, so that announcement never comes back **for that person** (the strip honours it too); **Close** hides
  every announcement of the window for the rest of this **login session** only (kept in the session, nothing is stored); **Next** steps on.
  Dismissals are personal preferences and are **not audited**.
* **Remember-me.** Laravel fires `Login` also when a remember-me cookie re-authenticates a new browser session. The login form (a POST to
  `/login`) always arms the window; a cookie return arms it **at most once per `GWL_BLOG_LOGIN_ANNOUNCEMENTS_RECALL_HOURS`** (12) per person, so
  a long-lived cookie cannot make it appear every morning, and `0` means only the login form ever does.
* **On the blog home** the active announcements also appear as a slim **strip** at the top (`Blog\AnnouncementStrip`), each with a Dismiss
  button that hides it for this session (not remembered).
* **The manage list** shows an "Announcement until 30 Nov" badge (or "Announcement from 1 Dec to 10 Dec" when it has not started), **Expired** once
  the last day has passed, and an **End announcement now** action (`BlogPostService::endAnnouncement()`: the article stays published; the
  dates are cleared).
* **Rules (form and service).** The service enforces them whatever calls it: the last day is required when ticked; the first day cannot
  come after it; a **new or changed** last day cannot be before today (an **unchanged** date that has since passed is kept, otherwise nobody
  could save any other change to an expired announcement); **only a published article** can be one ("Publish the article first"; the form shows
  the box disabled on a draft; `create(..., publish: true)` is allowed because the article ends published); **unpublishing ends it** (and
  publishing again does not bring it back) and deleting the article removes it. A restore or an autosave that does not send the announcement
  leaves it alone.
* **Audit.** Setting or changing one is in the existing `blog_post_updated` entry (the `fields` list names `announce_from` / `announce_until`);
  unpublishing records `announcement_ended`; **End announcement now** is its own action `blog_announcement_ended` (ids only).

## The editor

TipTap 3 (ProseMirror), mounted by Alpine inside a `wire:ignore` block and synced to the Livewire form `Blog\PostForm`. It is loaded
**only on the editor pages** (about 150 KB gzipped); the reading pages load none of it.

* **Text:** paragraph, headings (three levels), bold, italic, underline, strikethrough, links (`https:`, `mailto:`, `tel:` only; they open in
  a new tab with `rel="noopener noreferrer"`), bulleted and numbered lists, quotation with an optional "who said it", horizontal rule,
  text alignment, tables (add/delete rows and columns, header row, merge/split), undo/redo, word and character count, the usual shortcuts,
  a placeholder, and a bubble menu on a selection. The toolbar scrolls sideways on a phone and its buttons are at least 44 px there.
* **Pictures** are `figure` blocks that hold a **media id** (never an address): description (alt text, asked for when the picture is added),
  caption, credit, width (column / wide / full) and position. Add by the Picture button, **drag and drop** or **paste** from the clipboard;
  progress and errors show in the corner of the editor.
* **Gallery** blocks: several pictures in one block, layouts **grid**, **mosaic** and **carousel**, per-picture description and caption,
  reordered by dragging or by the earlier / later buttons (keyboard friendly).
* **Video** blocks: YouTube and Vimeo only. The author pastes an address; only the video's id is kept and the server builds the
  `youtube-nocookie.com` / `player.vimeo.com` embed itself.
* Pictures, galleries and videos are **top-level blocks**: the editor will not put them inside a list, quotation or table cell, and the
  server drops any that arrive nested (the renderer shows them nowhere else, so what is stored is what shows).
* **Cover photo:** optional, with caption, credit and a **focal point** (two sliders over a live preview).
* **Preview as readers see it** renders the exact article partial (`blog/partials/article.blade.php`) the article page uses, on the server,
  for what is on screen now. Nothing is saved.
* **Save draft / Save changes / Publish**, an unsaved-changes warning when leaving, and a status line ("Draft saved at 14:03").
* **Autosave of drafts only.** A draft is saved automatically every `GWL_BLOG_AUTOSAVE_SECONDS` while there are changes (a new article is
  created as soon as it has a region, a title and a kind). A **published article is never changed by autosave**: the component does nothing
  and `BlogPostService::autosave()` refuses; a published article changes only with "Save changes".
* **Someone else saved first?** Every article carries `lock_version`, raised by each change of its content. The editor remembers the
  version it opened; if the article has moved on, it **warns instead of overwriting**, saying who saved and when, and offers "Load their
  version" or "Save mine over theirs" (theirs stays in the versions). Autosave and restore check the same way.
* **Saved versions:** `blog_post_revisions` keeps the last `GWL_BLOG_REVISIONS_KEEP` (20) versions per article: every save, publish and
  restore; automatic saves share one rolling version. The editor lists them and **restores** one (audited as `blog_revision_restored`;
  the version it replaces stays in the list). A version of a Markdown article can be restored too.

### Older (Markdown) articles

Articles written before the editor keep `body_format = markdown` and read exactly as before (raw HTML stripped, unsafe links refused;
the migration filled their `body_html`, `body_text` and reading time once). Opening one in the editor loads its already-safe HTML into
the editor; **saving without touching the text changes nothing about the body**, and the first save that carries the editor's document
converts it to a rich article, once. Reading never converts anything.

## How an article is made safe (the security model)

The browser sends the editor **document** (JSON). It is never stored or printed as it arrives. `BlogContentService`:

1. **cleans** it against an allowlist of nodes, marks and attributes (anything else is dropped), checks every picture id against the
   region's own pictures (another region's, a missing one, or one already in another article is dropped), keeps only `https` / `mailto` /
   `tel` links and caps the depth and size;
2. **renders** the HTML from the cleaned document, escaping every text and attribute (so `<script>` typed in a paragraph is shown as text);
3. runs it through `symfony/html-sanitizer` with a strict allowlist, then a last check refuses a block whose pictures or links point
   anywhere but `/blog/media/{id}/{size}` or an allowed scheme (no external or `data:` image can survive, so there is no hotlinking and no
   tracking pixel).

`body_html`, `body_text` (plain text for search and teasers) and `reading_minutes` are regenerated together from the same cleaned
document on **every save**; readers get `body_html` as it is stored (`BlogPost::bodyHtml()`), with no rendering work per request. Print an
article only through that method. A video is built from a validated id and skips the sanitiser (which would refuse an iframe).

## Pictures (the media pipeline)

* **Upload** goes to `blog.media.upload`, never trusting the browser's file type: it must **decode as a real JPG, PNG, WebP or GIF (first
  frame)**. SVG, a `.php` or text file renamed `.jpg`, an empty or truncated file are refused with a message. Limits (config below): file
  size, longest side, megapixels (checked against the PHP memory limit before decoding), and pictures per article.
* Every picture is **rebuilt with GD**: turned upright from its EXIF orientation (read from the file's own bytes, so the `exif` extension is
  not needed), then re-encoded. That **removes EXIF including GPS**, comments and any payload hidden in the file. A stored "original"
  (at most 2560 px) plus **thumb 400 / medium 900 / large 1600 px**, each also as **WebP**, are made; a small picture is never enlarged
  and its larger sizes point at the same files. A picture with transparency stays PNG, everything else becomes JPEG.
* Files are stored **only on the private `local` disk**, under `blog/media/{region}/{year}/{month}/`, named by a random id (the client's
  file name is not kept). Nothing has a public address.
* **Serving** is `blog.media`, which applies `BlogVisibility::canViewMedia()`: another region's picture answers **404**; the region's
  writers see every picture of the region (drafts and unattached uploads included); a reader is only given a picture that a **published**
  article of their region currently shows (`in_use`), so a draft's pictures and a picture the author took out stay private;
  `super_admin` sees all. Sends `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=86400`, an `ETag` and `Last-Modified`
  (a repeat request gets a 304) and `Vary: Accept` (WebP to browsers that ask for it). Pages use `srcset`, `sizes`, `width` / `height`
  (no layout shift), `loading="lazy"` and `decoding="async"`.
* **Clean-up:** `php artisan blog:prune-media`, scheduled **daily at 03:20** while the blog flag is on (needs the scheduler, like the other
  daily jobs), deletes pictures never put in an article after `GWL_BLOG_MEDIA_ORPHAN_HOURS` (24), and pictures an article no longer shows
  **and no saved version shows either**. Deleting an article deletes all its files; replacing or removing a cover deletes the old picture.
* **Audit:** `blog_media_uploaded` / `blog_media_removed` (ids, sizes and the reason only), `blog_post_updated`, `blog_revision_restored`,
  `blog_post_created / published / unpublished / pinned / unpinned / deleted`, `blog_announcement_ended`. Reads and dismissals are not audited. No file contents, file names, captions or article text are
  ever put in an audit row.

## Data

* `blog_posts` (added): `body_format` (`markdown` | `rich`), `body_json` (the editor document, canonical for a rich article), `body_html`,
  `body_text`, `reading_minutes`, `slug`, `cover_media_id`, `cover_caption`, `cover_credit`, `cover_focal_x/y`, `lock_version`. For a
  rich article the old `body` column mirrors the plain text; for a Markdown one it is the source.
* `blog_media`: region, article (empty until first saved), uploader, `kind` (inline / gallery / cover), mime, size, `path` (stored original),
  `variants` (sizes and WebP paths), alt, caption, credit, order, `in_use`.
* `blog_post_revisions`: article, user, title, `body_json` (or `body_markdown` for an older article), `lock_version`, reason.
* `blog_posts` (added for announcements and the badge): `announce_from`, `announce_until` (dates, both blank = not an announcement), index
  `blog_posts_announce_index (region_id, status, announce_until)`, and `blog_posts_unread_index (region_id, status, published_at)` for the
  unread count (the older feed index has `is_pinned` in the middle, so it cannot serve a range on `published_at`).
* `blog_post_reads`: `user_id`, `blog_post_id`, `read_at`; the composite primary key is the uniqueness rule, an index on `blog_post_id`,
  both links cascade. Written only by `BlogReadService`, set-based.
* `blog_announcement_dismissals`: `user_id`, `blog_post_id`, `dismissed_at`; same shape; written only by `BlogAnnouncementService`.
* No view counter and no PDF attachment block were built (see "Not built").

## Configuration (`config/gwl.php`, `.env.example`)

| Key (env) | Default | Meaning |
|---|---|---|
| `blog_module_enabled` (`GWL_BLOG_MODULE_ENABLED`) | on | Master switch: routes, header link |
| `blog_cover_max_mb` (`GWL_BLOG_COVER_MAX_MB`) | 3 | Biggest cover accepted by the form |
| `blog_media_max_mb` (`GWL_BLOG_MEDIA_MAX_MB`) | 8 | Biggest picture file. Keep at or below PHP `upload_max_filesize` / `post_max_size` |
| `blog_media_max_dimension` (`GWL_BLOG_MEDIA_MAX_DIMENSION`) | 6000 | Longest side, in pixels |
| `blog_media_max_megapixels` (`GWL_BLOG_MEDIA_MAX_MEGAPIXELS`) | 24 | Most pixels in one picture |
| `blog_media_max_per_article` (`GWL_BLOG_MEDIA_MAX_PER_ARTICLE`) | 60 | Pictures one article may hold |
| `blog_media_orphan_hours` (`GWL_BLOG_MEDIA_ORPHAN_HOURS`) | 24 | Age before an unused picture is deleted |
| `blog_revisions_keep` (`GWL_BLOG_REVISIONS_KEEP`) | 20 | Saved versions kept per article |
| `blog_autosave_seconds` (`GWL_BLOG_AUTOSAVE_SECONDS`) | 10 | How often a draft is saved by itself |
| `blog_unread_lookback_days` (`GWL_BLOG_UNREAD_LOOKBACK_DAYS`) | 30 | Only articles first published within this many days count as unread |
| `blog_unread_cache_seconds` (`GWL_BLOG_UNREAD_CACHE_SECONDS`) | 60 | How long the header badge's number is cached per person (it also sets the poll, never under 30 s). `0` = no cache |
| `blog_login_announcements_max` (`GWL_BLOG_LOGIN_ANNOUNCEMENTS_MAX`) | 3 | Most announcements shown in the login window (and the strip) |
| `blog_login_announcements_recall_hours` (`GWL_BLOG_LOGIN_ANNOUNCEMENTS_RECALL_HOURS`) | 12 | Hours between windows for a remember-me re-authentication. `0` = only the login form shows the window |

None of this needs the scheduler or a queue worker. After editing `.env` on a server run `php artisan config:cache` again.

Decoding a large picture needs memory (about 10 bytes a pixel). The upload is refused with a message, not crashed, when the PHP
`memory_limit` is too small for the picture; raise it, or lower `blog_media_max_megapixels`, for a small server.

## Rules that are easy to get wrong

* **Visibility lives only in `BlogVisibility`** (feed scope, `canRead`, `canManageIn`, `scopeManageable`, `canViewMedia`, `scopeGallery`). The
  pages, the picture route, the manage list and the services all call it; never filter by region in Blade. `BlogFeedService` only starts
  every query from `scopeFeed()`.
* **Every change goes through `BlogPostService`** (re-reads the article `lockForUpdate`, authorises on that copy, writes the version and the
  audit entry). Pictures go through `BlogMediaService` only; never write a `blog_media` row or a file anywhere else.
* **The body is only ever printed through `BlogPost::bodyHtml()`.** Never put `body_json` or a client-sent HTML string on a page, and never
  store anything the editor sent without `BlogContentService::process()`.
* An article or picture the user may not read answers **404**, not 403, and the Livewire components resolve ids inside `scopeManageable()`.
* The editor sends `body_json` only when it has something to say; a Markdown article stays as it is until then.
* The Livewire scoping trait `ScopesBlogByActor` is, like the others, a separate trait that only wraps `BlogVisibility`.
* **"Unread" is defined only in `BlogReadService::unreadQuery()`** and **a login announcement is "active" only in
  `BlogPost::scopeAnnouncementActive()`** (given to a person only through `BlogAnnouncementService::activeFor()`). Never count unread or
  pick announcements in Blade, a screen or a second query; never write `blog_post_reads` or `blog_announcement_dismissals` anywhere else.
* The unread number is **cached**: a publish, unpublish or delete must call `BlogReadService::forgetEveryone()` (`BlogPostService` does);
  anything new that changes which articles are published has to do the same.
* The login flag is armed by the `Login` event and consumed **only** by `Blog\LoginAnnouncements::mount()`; keep that component out of the
  blog layout, and keep the listener free of queries. Do not register `ArmLoginAnnouncements` in `AppServiceProvider` (event discovery
  finds it; registering it too would run it twice).
* A deferred Livewire component is **not mounted** when its placeholder renders: `Blog\HeaderLink::placeholder()` reads its mount
  parameters from the argument, not from `$this`.

## Tests

`tests/Feature/Blog/`: `BlogContentServiceTest` (sanitiser: scripts, event handlers, `javascript:` / `data:` links, iframes, styles, external
images, bad nesting; allowed formatting survives; figures and galleries), `BlogMediaTest` (content validation, EXIF stripped, sizes, private
disk only, who is given which picture, 304 and WebP, clean-up), `BlogEditorFlowTest` (round trip, versions kept at 20 and restorable,
concurrency warning, autosave drafts only, Markdown articles, picture usage, preview, audit), `BlogPagesTest`, `BlogNavigationTest` (the tab is
gone, the header link opens a new tab, the layout has no ERP chrome), `BlogPruneCommandTest`, `BlogUnreadTest` (what counts, window, read once,
authors, republish, mark all as one statement, cap, cache invalidation, no query when off or without access, masthead and markers) and
`BlogAnnouncementTest` (the last-day boundary and timezone, first day, region isolation, once per login through the real login form, intended URL,
remember-me throttle, Close against Don't show again, the maximum, the validation rules, unpublish and delete, the form, the manage list, audit,
the migration), plus the earlier reading, authoring and seeder tests.
The browser editor itself cannot be unit-tested; use the checklist below.

## Manual QA checklist (browser)

Run once after a deploy, as a PR officer (and once as an ordinary reader).

1. **Header link:** the Blog link shows at the right of the ERP header, opens a **new tab**, and the blog is signed in. Signed out, the address
   goes to the login and returns to the blog after signing in.
2. **Insert a picture:** New article, give a headline and a kind, press **Picture**, choose a photo: progress shows, the picture appears, and you
   are asked for a description. Add width/position, a caption and a credit.
3. **Paste an image:** copy an image (a screenshot) and press Ctrl+V in the text: it uploads and appears.
4. **Drag and drop:** drag an image file from the desktop onto the text: it is added where you dropped it.
5. **A wrong file:** try a `.pdf`, a renamed text file, an SVG and a very large photo: each is refused with a clear message.
6. **Gallery:** add a gallery of 4 pictures; reorder by dragging and with the earlier/later buttons; switch Grid / Mosaic / Carousel; add a
   caption to one.
7. **Lightbox:** publish, open the article, click a picture: it opens; Left/Right and Home/End move; Esc closes and focus returns; on a phone,
   swipe moves; the caption and "2 / 5" counter show; Tab stays inside the viewer.
8. **Mobile toolbar:** at phone width the page does not scroll sideways, the toolbar scrolls inside its own box and its buttons are easy to tap.
9. **Autosave:** type in a new draft; after ~10 seconds "Draft saved automatically" appears and the address becomes `/blog/manage/{id}/edit`.
   For a **published** article, edit and wait: nothing is saved until "Save changes".
10. **Unsaved-changes warning:** edit, then close the tab or press Back: the browser asks before leaving. After saving, it does not.
11. **Versions:** save a few times; "Saved versions" lists them; **Restore** an older one: the text returns, and the replaced text is still listed.
12. **Two people:** open the same article in two browsers, save in one, then save in the other: the second sees the warning (who and when) and
    offers "Load their version" or "Save mine over theirs".
13. **Preview:** "Preview as readers see it" shows the real reader layout for what is on screen; close it with Esc or the button.
14. **Privacy:** as a reader of another region, the article and picture addresses answer "not found"; a draft's pictures are not shown to readers.
15. **Print:** print an article: no masthead, sidebar or buttons; links are written out after their text.
16. **Dark mode and keyboard:** switch the theme; every control can be reached by Tab and shows a focus outline.
17. **Unread badge:** as a reader with unread articles, the Blog link in the ERP header shows a number a moment after the page loads (not before).
    Open one article in the blog tab, switch back to the ERP tab: within a few seconds (on focus) the number goes down by one; at 0 it disappears.
    The masthead and the New markers agree; "Mark all as read" clears them.
18. **Login announcement:** as a PR officer publish an article, tick "Show as announcement at login", choose today as the last day, save. Sign out
    and in as a reader of that region: the window appears on the first page, once. Tab stays inside it, Esc closes it, "Read full article" opens a
    new tab. Refresh: it does not return. Sign in again: it does (Close), or does not (Don't show again). With the OS set to reduce motion it
    appears without animation.
19. **Ending:** "End announcement now" on the manage list; the reader no longer sees it at the next login. Set the last day to yesterday via the
    database to see the "Expired" badge.

## Not built (ideas for later)

In-app notices when an article is published; comments; a "latest from your region" card on the ERP dashboard; scheduled publishing; a PDF
attachment block (programme / agenda) served through the same region-checked route; a view counter; the demo seeder
(`RegionalBlogDemoSeeder`) still writes Markdown articles, so its articles show the older format until opened in the editor.
