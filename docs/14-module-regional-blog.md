# Regional Blog

The Public Relations (PR) team of each region writes articles about what is going on in the region (activities, meetings,
workshops, training, announcements), with pictures, galleries and tables. Every member of staff reads the articles of **their own
region**, and only that region.

The blog is **not a module tab**. It is a standalone, full-page, magazine-style blog, opened in a **new browser tab** from the
**Blog** link at the right of the ERP header (next to the bell and the account menu). It shares the ERP login but has its own
layout (no ERP sidebar, no module tabs) and is **not public**: the same login, module and region checks apply as ever.

Code: `App\Services\Blog\BlogVisibility` (who sees and writes what), `BlogPostService` (every change to an article),
`BlogContentService` (editor document -> safe HTML), `BlogMediaService` (pictures), `BlogFeedService` (reader-page queries); models
`BlogPost`, `BlogMedia`, `BlogPostRevision`; `Blog\BlogController`, `Blog\BlogMediaController`; Livewire `Blog\ManagePosts` and
`Blog\PostForm` (the editor); layout `layouts/blog.blade.php` (component `x-blog-layout`); views in `resources/views/blog/` and
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
  `blog_post_created / published / unpublished / pinned / unpinned / deleted`. No file contents, file names, captions or article text are
  ever put in an audit row.

## Data

* `blog_posts` (added): `body_format` (`markdown` | `rich`), `body_json` (the editor document, canonical for a rich article), `body_html`,
  `body_text`, `reading_minutes`, `slug`, `cover_media_id`, `cover_caption`, `cover_credit`, `cover_focal_x/y`, `lock_version`. For a
  rich article the old `body` column mirrors the plain text; for a Markdown one it is the source.
* `blog_media`: region, article (empty until first saved), uploader, `kind` (inline / gallery / cover), mime, size, `path` (stored original),
  `variants` (sizes and WebP paths), alt, caption, credit, order, `in_use`.
* `blog_post_revisions`: article, user, title, `body_json` (or `body_markdown` for an older article), `lock_version`, reason.
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

## Tests

`tests/Feature/Blog/`: `BlogContentServiceTest` (sanitiser: scripts, event handlers, `javascript:` / `data:` links, iframes, styles, external
images, bad nesting; allowed formatting survives; figures and galleries), `BlogMediaTest` (content validation, EXIF stripped, sizes, private
disk only, who is given which picture, 304 and WebP, clean-up), `BlogEditorFlowTest` (round trip, versions kept at 20 and restorable,
concurrency warning, autosave drafts only, Markdown articles, picture usage, preview, audit), `BlogPagesTest`, `BlogNavigationTest` (the tab is
gone, the header link opens a new tab, the layout has no ERP chrome), `BlogPruneCommandTest`, plus the earlier reading, authoring and seeder tests.
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

## Not built (ideas for later)

In-app notices when an article is published; comments; a "latest from your region" card on the ERP dashboard; scheduled publishing; a PDF
attachment block (programme / agenda) served through the same region-checked route; a view counter; the demo seeder
(`RegionalBlogDemoSeeder`) still writes Markdown articles, so its articles show the older format until opened in the editor.
