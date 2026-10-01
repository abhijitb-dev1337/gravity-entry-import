=== Gravity Entry Import ===
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.5.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Imports entries into Gravity Forms from a CSV file, with column mapping and
batched processing.

== Description ==

Adds an **Import Entries** tab to Gravity Forms' own **Forms → Import/Export**
screen, alongside its built-in Export Entries, Export Forms and Import Forms
tabs. Upload a CSV, map its columns onto the target form's fields, and import
in resumable batches.

Built for migrating historical submissions into Gravity Forms — moving from
another form plugin, restoring an entry export, or seeding a form with records
that were collected elsewhere.

= What it handles =

* Simple fields: text, textarea, email, phone, website, hidden, number.
* Composite fields: name, address and time, mapped either per sub-input
  (separate First/Last columns) or as a single whole-field column.
* Choice fields: radio, select, checkbox and multiselect. Values are matched
  against the field's configured choices by both label and stored value, so a
  CSV carrying display labels imports correctly.
* Checkboxes from one delimited column (`Red, Green, Blue`) or one column per
  choice.
* Dates, parsed using the field's own configured format so `03/04/2025` is not
  silently shifted between March and April.
* Entry metadata: date created, created-by user, source URL, IP, status, read
  flag, payment status/amount and transaction ID.
* File Upload fields: the CSV column holds either a bare filename (already
  staged in this job's own pre-staged files directory) or a full `http://`/
  `https://` URL, and the file ends up attached to the imported entry exactly
  as if it had been uploaded through the real form. See Safeguards below for
  everything that is checked before a file is ever attached.
* Entry notes: map a CSV column to "Entry note" under Entry metadata and that
  row's cell is added to the imported (or updated) entry's own note timeline,
  via Gravity Forms' own note API — visible on the entry-detail screen
  alongside any note a real user would add.
* No form to import into yet? The Upload step can generate one for you from
  the CSV's own headers — see "Create a new form from a CSV" below.

= Safeguards =

* A **Validate** step scans the whole mapped file for likely problems -
  unparseable dates, values that don't match any configured choice, and
  required fields left blank - before anything is imported. Nothing is
  created or changed during this scan; it's purely a report. Rows excluded by
  an "only import matching rows" filter (see below) are never scanned for
  these issues either, since they will never actually be imported.
* An optional **row filter** ("only import rows where…") lets you restrict a
  run to rows whose CSV column meets a simple condition — equals, does not
  equal, contains, does not contain, is empty, or is not empty — matched
  case-insensitively against the raw CSV value, before any column mapping
  happens. There is deliberately no free-form code or regular-expression
  option here. Rows the filter excludes are counted on their own as
  **filtered**, separately from imported, updated, skipped-duplicate and
  failed rows, so you can always tell why a given row didn't end up in the
  form.
* **Saved mapping templates** let you name and reuse a column mapping (plus
  duplicate handling, and notifications) for a form, instead of rebuilding it
  from scratch on every import into that form. Templates are stored per form,
  are not affected by the daily cleanup of abandoned import jobs, and are only
  ever removed by an explicit delete action or by uninstalling the plugin.
* Notifications are **off** by default — importing a back catalogue will not
  email every historical submitter unless you opt in.
* Optional duplicate handling, matched on a field you choose: **Skip** rows
  that already exist (the default), or **Update** the matching entry instead,
  replacing only the fields this import maps and leaving everything else on
  that entry untouched.
* Uploaded CSVs are stored outside the publicly readable uploads path, in a
  directory hardened against direct web access. The main file is deleted as
  soon as the import finishes, and on uninstall.
* File Upload columns are handled defensively on both supported sources:
    * A **local filename** may only reference a file already staged in that
      job's own protected directory — never a path. Directory-traversal
      attempts (`../../wp-config.php` and similar) are rejected outright, and
      so is a filename that resolves to the right directory but turns out to
      be a hard link to a file elsewhere (checked via its link count, not
      just its resolved path).
    * A **remote URL** must be `http://` or `https://` — nothing else is
      even attempted — and may not name this site's own domain (a
      trailing-dot variant of it included) or its own already-resolved IP
      address, checked before any request is made. Fetching then uses
      WordPress's own SSRF-hardened `wp_safe_remote_get()`, which refuses to
      contact a private, loopback or link-local address (this blocks cloud
      metadata endpoints such as `169.254.169.254` too); see Known
      limitations for what that protection, and this plugin's own same-site
      check, do not cover. A remote file over 10MB
      (filterable via `gei_file_upload_max_bytes`) is refused — checked
      against the server's declared size before downloading, and enforced
      again against the bytes actually written to disk (not just compared
      after the fact, which a silently-truncated download could otherwise
      slip past), so a server cannot bypass the limit by lying about,
      omitting, or exceeding its declared size. Each request times out after
      15 seconds so one slow host cannot stall a batch.
    * Whichever source, the file's real content is verified — not just its
      extension or a claimed content type — using the same check Gravity
      Forms' own upload handler relies on, against that field's own allowed
      file types. A file that fails is never attached; the row is recorded as
      failed with a clear reason, the same as any other row-level failure.
    * A file only ever ends up inside Gravity Forms' own per-form uploads
      folder, copied there the same way a genuine submission would be. The
      CSV never determines file size limits, allowed types or the
      destination path — only this plugin's own logic and the field's own
      configuration do.
* Rows that fail to import are written to a separate, downloadable CSV (with
  the original columns plus a Failure Reason column), so a large import's
  failures don't disappear once they scroll out of the on-screen log. Also
  stored in the hardened directory, and only ever reachable through a gated
  download link on the results screen - never a direct URL.
* Progress is checkpointed after every row and each job takes an exclusive
  lock, so a timeout or a second browser tab cannot import the same rows twice.
* Entries are recorded as unattributed rather than credited to whoever ran the
  import, unless you map a "Created by" column.
* Batch size is adjustable for servers with short execution limits.
* An imported entry note is added through Gravity Forms' own note API
  (`GFFormsModel::add_note()`) — the same, real mechanism the entry-detail
  screen itself uses for a hand-typed note — never a direct write to
  whatever table happens to back notes. Attribution follows the same rule as
  "Created by": a row with a recognised "Created by" value credits the note
  to that user by name; every other row gets an unattributed, system-style
  note instead, never silently credited to whoever ran the import.
* "Create a new form from this CSV's headers" generates one field per column
  using a deliberately simple, conservative heuristic (see Known limitations
  for exactly what it checks), with every generated field created **not
  required** — a guessed type is never trusted enough to also guess a field
  is mandatory. The new form, and a link to open it in the form editor, is
  shown before you proceed to mapping, so it's never a surprise that a new,
  permanent form now exists. Creating a new form requires its own capability
  (`gravityforms_edit_forms` and `gravityforms_create_form`, or
  `gform_full_access`) — the same combination Gravity Forms' own Import Forms
  tab requires on this same screen — separately from the plain import
  capability this plugin otherwise uses, so a role trusted only to import
  entries cannot also create permanent new forms. A user without it simply
  doesn't see this option. Each header cell is also run through
  `sanitize_text_field()` before becoming a field's label, and a header with
  more than 150 columns is rejected rather than generating an unbounded form.
* A small, bounded **history log** records a summary (date, who ran it, and
  the same counts the results screen shows) of every completed import, per
  form — up to the most recent 100 per form, oldest dropped first. Unlike a
  job's own record, it holds no header, mapping, raw CSV data or per-row
  error detail, is never swept by the daily cleanup of abandoned import
  jobs, and survives long after that cleanup removes the job it came from —
  only the per-form cap, or uninstalling the plugin, ever removes an entry.
  See "View past imports" on the Upload step.

= Known limitations =

* Credit card fields are deliberately not importable.
* A single-column address goes into street line 1 only; map the sub-inputs
  separately for a full address.
* A single-column name splits on the first space, so "Mary Jane Smith" becomes
  first "Mary", last "Jane Smith". Map First and Last separately to avoid this.
* List fields are imported as simple rows. A List field with multiple columns
  configured is not supported.
* Updating an existing entry only ever adds or replaces the fields this
  import maps; a checkbox field mapped as a single delimited-list column will
  turn on the boxes this row lists but won't uncheck boxes the entry already
  had that aren't mentioned in this row's value.
* If the field you're matching duplicates on already has more than one
  existing entry sharing the same value, **Update** mode has no way to know
  which one you meant and will pick whichever the database happens to return
  — silently, with no warning. Only use Update mode matched on a field you're
  confident is actually unique per entry (email, an order number) rather than
  one that merely usually is.
* Values are stored as-is. If entries are later exported back out to CSV, a
  cell beginning with `=`, `+`, `-` or `@` is a spreadsheet formula-injection
  risk in the downstream export, as it would be for any hand-entered entry.
* A saved mapping template does not include the row filter — a filter rule
  points at a specific column index in one specific CSV's header, so
  reapplying it automatically against a different upload later could silently
  filter on the wrong column. Set the filter fresh each time.
* Only one row-filter condition is supported per import; there is no way to
  combine several conditions with AND/OR in this version.
* A form can hold up to 50 saved mapping templates; delete an old one before
  saving another once you hit that limit.
* There is no in-plugin uploader for the batch of local files a File Upload
  column's "local filename" mode reads from — this version deliberately
  scopes that out as its own separate risk (a general-purpose bulk file
  uploader is a meaningfully larger attack surface than resolving a filename
  a CSV points at) rather than build it under the same review as everything
  else in this release. Stage files into the path shown on the mapping
  screen yourself (SFTP, your host's file manager, etc.) before running the
  import.
* A File Upload column resolves to exactly one file per row, even when the
  field itself allows multiple files per submission. Map one column per file
  if a row needs to attach more than one.
* A remote URL's server must respond to a HEAD request with an accurate
  `Content-Length` header, or the URL is rejected outright rather than
  downloaded to find out — most direct file hosts (including typical object
  storage and static file servers) support this; a server that does not
  respond to HEAD, or omits the header, cannot be used as a remote source in
  this version.
* A pre-staged local file is only ever copied, never deleted, when it is
  used — it stays in the job's pre-staged files directory (and stays
  reusable by a re-run of the same import) until that job is pruned on the
  same schedule as an abandoned upload, or the plugin is uninstalled. Delete
  it yourself sooner if it holds sensitive data you want gone immediately
  after a successful import.
* Mapping a File Upload field as the duplicate-matching field is not
  offered — its stored value is a URL this plugin generates during import,
  never something a CSV cell can be meaningfully compared against.
* The hard-link check on a local pre-staged file works by its link count, not
  just its resolved path. A file placed by a backup or deduplication tool
  that intentionally uses hard links (e.g. `rsync --link-dest`, an rsnapshot-
  style incremental backup) will have the same link count a malicious alias
  would and gets rejected for the same reason, with no way to tell the two
  apart from the error alone — stage a plain copy instead if a legitimate
  file is refused this way. On some network-mounted directories (NFS/SMB),
  the operating system does not always report an accurate link count in the
  first place, which would fail to catch a hard link staged that way; this
  check is strongest on a local/NTFS-backed uploads directory, which is the
  common case.
* A remote URL's host is checked against private/loopback/link-local ranges
  and against this site's own domain (hostname, including a trailing-dot
  variant of it) and its own already-resolved IP address, before a
  connection is opened, but only at that moment. A host whose DNS resolves
  to a safe address at check time and a different, unsafe address moments
  later (a DNS-rebinding race) is not defended against — this is a
  limitation of `wp_safe_remote_get()`/`wp_safe_remote_head()` themselves,
  shared by every WordPress core or plugin caller of those functions, not
  something specific to this plugin. This plugin's own same-site IP
  comparison shares that same kind of timing limitation, and is deliberately
  a proportionate check rather than an exhaustive one — a single A-record
  (IPv4) lookup, not a full resolver or an AAAA/multi-record check — closing
  the obvious "this site's own IP used as a raw literal" case rather than
  promising to catch every possible DNS-level trick.
* Between a resolved file (local or downloaded) passing its content/extension
  check and being copied into Gravity Forms' own uploads folder, nothing
  re-verifies it. Reaching that narrow window at all already requires being
  able to write into this job's own pre-staged files directory, or to control
  what a remote URL serves on a second request — either of which is a bigger
  problem on its own than this window adds.
* An entry note mapped from a CSV column is not checked by the Validate step
  — there's no format for a note to get wrong, only presence or absence, and
  a blank cell simply adds no note. An imported note can't be edited or
  removed from this plugin itself afterward; manage it from the entry's own
  note timeline under **Forms → Entries**, the same as any other note.
* "Create a new form from this CSV's headers" uses a simple, case-insensitive
  substring match against each column header — a header containing "email"
  becomes an Email field, "phone" or "tel" becomes a Phone field (which also
  matches words like "detail" or "hotel" that merely contain "tel" — an
  accepted trade-off of the simple heuristic, not a bug), "date" becomes a
  Date field, "note" or "comment" becomes a Textarea field, and anything else
  becomes a plain Text field. It has no idea what your data actually is
  beyond the header's own wording — always review the generated form in the
  form editor (a link is shown before you proceed) before relying on it, and
  adjust or add validation, choices or required fields by hand as needed.
* A form created this way is a real, permanent Gravity Forms form like any
  other the moment it's created — even if you never complete the import, it
  is not automatically cleaned up. Delete it yourself from **Forms** if it
  was a mistake.
* A CSV header wider than 150 columns is refused outright rather than
  generating a form with that many fields — split the file, or build the
  form by hand, if you genuinely need more fields than that.
* Creating a new form from a CSV requires `gravityforms_edit_forms` and
  `gravityforms_create_form` (or `gform_full_access`), the same capabilities
  Gravity Forms' own Import Forms tab requires on this same screen. Holding
  only this plugin's plain import capability (`gravityforms_edit_entries` by
  default) is not enough — that role can still import into an existing form,
  just not create a new one.
* The past-imports history log records only aggregate counts, not which rows
  succeeded or failed — for that level of detail on a given run, use the
  "Download failed rows" link on that run's own results screen before the
  underlying job record is pruned (about a day after it completes). There is
  no way to re-run or replay a past import from its history entry in this
  version.
* A form with no recorded import history yet (or one that's been deleted)
  shows no "View past imports" link on the Upload step — history is only
  ever listed per currently-active form, not browsable independently of one.

== Installation ==

1. Ensure Gravity Forms 2.4 or newer is active.
2. Upload the plugin folder to `/wp-content/plugins/`.
3. Activate it through the Plugins screen.
4. Go to **Forms → Import/Export → Import Entries**.

== Usage ==

= Quick start =

1. Go to **Forms → Import/Export → Import Entries** (a tab alongside Gravity
   Forms' own Export Entries, Export Forms and Import Forms).
2. **Upload**: choose the target form and the CSV file, then click
   "Upload and continue". The first row of the CSV must be column headers. No
   form to import into yet? Tick "Create a new form from this CSV's headers"
   instead of picking one, give the new form a name, and it's generated for
   you — see "Create a new form from a CSV" below. If this form (or any
   form) already has recorded past imports, a "View past imports" link
   appears below the form selector.
3. **Map**: each form field is listed on the left; pick the CSV column that
   feeds it on the right. Likely matches are auto-suggested from the header
   text, so check them rather than starting from scratch. A live sample value
   from the first data row is shown next to each mapping so you can confirm
   it's the right column before importing anything. If this form already has
   a saved mapping template, a "Load a saved mapping" dropdown appears above
   the table — pick one and click "Load" to pre-fill the mapping and options
   below instead of starting from the auto-suggestions. To also add a note to
   each imported entry's own note timeline, map a column to "Entry note"
   under Entry metadata.
4. If the form has a File Upload field, its row in the mapping table shows
   where to stage local files for this job. Before starting the import, stage
   any files the "local filename" mode needs into that directory (SFTP, your
   host's file manager, etc.) — the CSV column itself should hold either a
   bare filename already staged there, or a full `http://`/`https://` URL.
5. Set the options for this run — duplicate handling (Skip or Update),
   notifications, rows per batch, and, optionally, a row filter ("only import
   rows where…") to restrict this run to rows matching a simple condition.
   Give the mapping a name under "Save this mapping as" if you'll want to
   reuse it on a future import into this same form, then click
   "Continue to import" (this both saves the template, if you named one, and
   proceeds — nothing extra to click).
6. **Validate**: the whole file is scanned for likely problems — unparseable
   dates, values that don't match any configured choice, required fields left
   blank, and a File Upload column value that looks like neither a plausible
   filename nor a valid URL — before anything is imported. Nothing is
   created or changed during this scan. Review the summary, then either go
   back to mapping to fix something, or proceed.
7. **Import**: click "Start import" and watch the progress bar. It's safe to
   leave the tab and come back; an interrupted import resumes from the same
   screen without re-importing rows already processed. The status line reports
   imported, updated, skipped, filtered and failed counts separately, so a row
   your filter excluded is never confused with a skipped duplicate or a
   failure. If any rows fail, a "Download failed rows" link appears with the
   full list, including why each one failed.

= Use case: migrating from another form plugin =

Moving off Contact Form 7, WPForms, Ninja Forms, or a hand-rolled form, and
the historical submissions need to survive the move.

1. Export the old plugin's submissions to CSV (however that plugin supports
   export — most have one; otherwise a database export gets you there).
2. Build the receiving form in Gravity Forms with the equivalent fields.
3. Upload the CSV on the Import Entries tab, targeting that form.
4. Map each column. Leave **Send form notifications** off — the point is
   preserving history, not re-notifying anyone from years of old submissions.
5. Start the import, then spot-check a handful of entries under
   **Forms → Entries** against the original CSV.

= Use case: restoring a Gravity Forms entry export =

Bringing back entries from a CSV produced by Gravity Forms' own
**Export Entries** tab — after recreating a form, or moving entries to
another site.

1. Locate the CSV from the original **Export Entries** run.
2. Make sure the target form exists (recreate it first if needed — this
   plugin imports entries, not form definitions; use GF's own **Export
   Forms**/**Import Forms** tabs for the form structure itself).
3. Upload the CSV on the Import Entries tab. Because the column headers are
   already the field labels GF itself generated, the mapping screen will
   auto-suggest almost the entire set.
4. If there's any chance some of these entries already exist on the target
   (a partial restore, or a re-run after an earlier attempt), turn on
   duplicate matching against a field that's unique per entry (email is the
   usual choice), and choose **Skip** to leave the existing copy alone.
5. Check the Validate summary, then start the import.

= Use case: seeding a form with records collected elsewhere =

Consolidating years of signups, contacts, or requests from a spreadsheet or
another system into a Gravity Form, so everything going forward lives in one
place.

1. Build the Gravity Form with the fields you want to track from here on.
2. Get the historical data into CSV (a spreadsheet export usually needs no
   extra work beyond "Save As CSV").
3. Map each source column. Also map **Date created** under Entry metadata if
   the CSV has an original date/timestamp column, so historical entries keep
   their real dates instead of showing today's date.
4. If this consolidation is arriving in several batches (one export per
   source system, or per month), name and save this mapping under "Save this
   mapping as" the first time — every later batch into the same form can then
   load it back with one click instead of remapping every column again.
5. Leave notifications off and start the import.

= Use case: re-running an import after fixing bad data =

An earlier import had a wrong column mapping or a batch of malformed rows,
the CSV has since been corrected, and it needs to go back in without
duplicating the rows that already succeeded.

1. Fix the problem in the CSV (or in the mapping) and re-upload it.
2. If the original run saved a mapping template for this form, load it from
   the "Load a saved mapping" dropdown instead of rebuilding the mapping —
   duplicate field, mode and notification settings come back with it.
3. Turn on duplicate matching against a field that reliably identifies one
   entry (email, an order/reference number — anything unique per row and
   already mapped).
4. Choose **Skip** to leave already-correct entries alone and only add
   genuinely new or previously-failed rows, or choose **Update** if the fix
   needs to correct data on entries that already imported wrong — Update
   replaces only the fields this run maps on the matching entry and leaves
   everything else on it untouched.
5. If only a known subset of rows need re-running (for example, everything
   with a "Failed" status column from a previous attempt), add a row filter
   for that column instead of hand-editing the CSV down to just those rows.
6. Re-run the import.

= Use case: loading test data before a form goes live =

Populating a new or changed form with realistic sample entries to check
field behavior, notifications, and any connected integrations before real
visitors start submitting it.

1. Prepare a CSV covering the cases worth checking — long text, every
   checkbox combination, edge-case dates, etc.
2. Import it into the form under test.
3. Review the resulting entries under **Forms → Entries**, and turn
   notifications on for this one run if the notification content itself
   needs checking.
4. Once satisfied, select the test entries and bulk-delete them from
   **Forms → Entries** before the form goes live.

= Use case: creating a new form from a CSV =

Starting from a spreadsheet of data with no matching Gravity Form yet, and
wanting to get it into one quickly rather than building the form by hand
first.

1. On the Upload step, tick "Create a new form from this CSV's headers"
   instead of picking an existing form, and give the new form a name.
2. Choose the CSV and click "Upload and continue". One field is generated
   per column, guessing a simple type from each header's own wording (see
   Known limitations for exactly how), every field created not required.
3. The Map step shows which form was just created, with a link to open it in
   the form editor — do that first and review the guessed field types,
   adding any choices, validation or required fields the generated form
   doesn't already have, before trusting it for anything beyond this import.
4. Back on the Map step, the mapping is already a 1:1 match (the field
   labels were generated from these exact headers), so there's normally
   nothing left to remap. Set the usual options and continue as any other
   import.

== Frequently Asked Questions ==

= What does the CSV need to look like? =

The first row must be column headers. Any delimiter among comma, semicolon, tab
or pipe is detected automatically. UTF-8 is assumed; Windows-1252 is converted,
and an Excel byte-order mark is stripped.

= Will it email everyone in the file? =

Not unless you tick "Send form notifications" on the mapping screen. It is off
by default.

= I don't see the "Import Entries" tab =

Check three things, in order:

1. Gravity Forms must be active — the tab does not register at all until it is.
2. You need Gravity Forms access. Any user with `gform_full_access` (which
   administrators get by default) can always see it; other roles need the
   `gravityforms_edit_entries` capability (or whatever `gei_capability`
   is filtered to).
3. It lives inside **Forms → Import/Export**, as a fourth tab next to Export
   Entries, Export Forms and Import Forms — not as its own item in the Forms
   menu.

= Can it update existing entries? =

Yes, as of 1.2.0. Turn on duplicate matching against a field on the mapping
screen and choose **Update** instead of **Skip**. A matching row then updates
that entry via Gravity Forms' own update API, replacing only the fields this
import maps — anything the CSV doesn't map is left exactly as it was, never
blanked. **Skip** (the default) still just leaves the existing entry alone.

= What does Validate check, and can it fix anything itself? =

It only reports; it never changes data. Before any row is imported, the whole
mapped file is scanned for: dates that don't parse into the field's expected
format, values in a choice field (radio, select, checkbox, multiselect) that
don't match any option configured on that field, and required fields left
blank for a mapped column. Gravity Forms' own required-field validation never
runs during an API import, so this is the only check that catches that last
one. Fix issues in the CSV or the mapping and re-validate, or proceed anyway —
Validate is advisory, not a hard gate.

= Can I import files into a File Upload field? =

Yes, as of 1.4.0. Map the column to the File Upload field the same as any
other field. Each cell must be either a bare filename (no path) already
staged into the directory shown on the mapping screen for that job, or a full
`http://`/`https://` URL to fetch. Every file, from either source, is
checked against the field's own allowed file types using Gravity Forms' own
real content-sniffing check (not just the extension or a claimed content
type) before it is attached, and a remote URL is fetched with WordPress's own
SSRF-hardened `wp_safe_remote_get()` with a 10MB default size cap
(`gei_file_upload_max_bytes`). A file that fails any check is never attached;
the row is recorded as failed with a clear reason, same as any other failure.
See Safeguards and Known limitations above for the full detail.

= How large a file can it handle? =

The upload itself is bounded by the server's upload limit, shown on the upload
screen. Beyond that, rows are processed in batches and the file is read from a
stored byte offset each time, so total file size is not the constraint — expect
roughly 25 rows per request at the default batch size.

= Can I reuse a column mapping on my next import? =

Yes, as of 1.3.0. On the mapping screen, name the current mapping under "Save
this mapping as" and click "Continue to import" — the mapping, duplicate
handling and notification setting are saved together under that name, scoped
to the form you're importing into. On a later import into that same form, a
"Load a saved mapping" dropdown appears above the mapping table; choosing a
template and clicking "Load" re-renders the screen with everything from that
template pre-filled instead of the auto-suggested guesses. A loaded template
can be removed with the "Delete this saved mapping" action next to it — that's
the only way a template disappears; it is never expired or swept up by the
plugin's own daily cleanup of abandoned import jobs, only an explicit delete or
uninstalling the plugin removes one. The row filter (see below) is not part of
a saved template, since it refers to a specific CSV's column layout.

= Can I import only some of the rows in a file? =

Yes, as of 1.3.0. On the mapping screen, "Only import matching rows" lets you
pick a CSV column, an operator (equals, does not equal, contains, does not
contain, is empty, is not empty) and, where the operator needs one, a
comparison value. Matching is case-insensitive and runs against the raw CSV
cell, before any column mapping. A row that does not match is never imported
and is never flagged by the Validate step either — it's counted separately as
"filtered", distinct from a skipped duplicate or a blank-row skip, both during
Validate and during the real import. There is deliberately no free-form code
or regular-expression option, and only one condition can be set per import.

= Can I add a note to each imported entry? =

Yes, as of 1.5.0. Map a CSV column to "Entry note" under Entry metadata on the
mapping screen. Once a row's entry is created (or updated), if that column's
cell is non-blank, it's added to the entry's own note timeline via Gravity
Forms' own note API (`GFFormsModel::add_note()`) — the same timeline you see
on the entry-detail screen, alongside any note a real user typed in by hand.
If this row's CSV also maps a "Created by" column that resolves to a real
user, the note is credited to that same user; otherwise it's added as an
unattributed, system-style note, the same "0 means unattributed" rule
"Created by" itself already follows. Validate never checks this column —
there's no format for a note to get wrong, only presence or absence.

= Can it create the form for me instead of me building one first? =

Yes, as of 1.5.0. On the Upload step, tick "Create a new form from this CSV's
headers" instead of picking an existing form, give it a name, and upload the
CSV as usual. One field is generated per column, with a simple, conservative
type guessed from each header's own wording (an "email" header becomes an
Email field, "phone"/"tel" becomes Phone, "date" becomes Date, "note"/
"comment" becomes Textarea, anything else becomes plain Text), and every
generated field is created **not required**. The Map step then shows which
form was created, with a link to open it in the form editor — review the
guessed types there before relying on them; this is a starting point, not a
guarantee. The mapping itself needs no extra work, since the generated
field labels are the CSV's own headers.

This option is only shown to, and only usable by, a user who holds
`gravityforms_edit_forms` and `gravityforms_create_form` (or
`gform_full_access`) — the same capabilities Gravity Forms' own Import Forms
tab requires on this same screen. This plugin's own plain import capability
alone is not enough, since creating a form is a separate, higher-trust action
than importing entries into one that already exists.

= Is there a record of past imports once the job itself is cleaned up? =

Yes, as of 1.5.0. A small, bounded summary — date, who ran it, and the same
imported/updated/skipped/filtered/failed counts the results screen showed —
is recorded for every completed import, per form, and kept (up to the most
recent 100 per form) long after the underlying job record is pruned. Find it
via "View past imports" on the Upload step, next to any form that has at
least one recorded entry. It holds no raw data, mapping or per-row detail,
and there's no re-run/replay action in this version — for that level of
detail on a specific run, use that run's own "Download failed rows" link
before its job record is pruned (about a day after it completes).

== Hooks ==

`gei_entry` — filters each entry array before insertion (or, for a row
matched in Update mode, before the update is saved).

    add_filter( 'gei_entry', function ( $entry, $form ) {
        $entry['source_url'] = 'https://example.com/legacy-form';
        return $entry;
    }, 10, 2 );

`gei_capability` — filters the capability required to import.
Defaults to `gravityforms_edit_entries`. Any user with Gravity Forms' own
`gform_full_access` (administrators, by default) can always use the importer
regardless of this filter, matching how Gravity Forms' own admin menus work.

`gei_file_upload_max_bytes` — filters the maximum size, in bytes, of a
remote file this plugin will download for a File Upload column. Defaults to
10485760 (10MB). Has no effect on a local, pre-staged file, which is instead
bounded by the field's own configured maximum file size.

    add_filter( 'gei_file_upload_max_bytes', function ( $max_bytes ) {
        return 25 * MB_IN_BYTES;
    } );

== Changelog ==

= 1.5.1 =
* Fixed a capability gap in "Create a new form from this CSV's headers"
  (added in 1.5.0): creating a new form now requires its own, separate
  capability check - the same `gravityforms_edit_forms` +
  `gravityforms_create_form` combination Gravity Forms' own Import Forms tab
  already requires on this same Import/Export screen - instead of only the
  plain entries-import capability this importer otherwise uses. A user with
  only the import capability no longer sees the checkbox, and a direct
  request attempting to use it anyway is rejected with a clear message
  rather than silently ignored or allowed through. `gform_full_access`
  continues to work regardless, the same as everywhere else in this plugin.
* Each CSV header cell is now run through `sanitize_text_field()` before
  becoming a generated field's label, matching the form-title treatment this
  same feature already applied. Not a known exploit against the currently
  supported Gravity Forms version, but closes the gap defensively rather than
  depending on that holding true forever.
* Added a 150-field cap on "Create a new form from this CSV's headers": a CSV
  with an unreasonably wide header row now fails with a clear error instead
  of silently generating an equally large form.

= 1.5.0 =
* Added entry notes: map a CSV column to a new "Entry note" entry-metadata
  target, and a non-blank cell is added to the imported (or updated) entry's
  own note timeline via Gravity Forms' own note API
  (`GFFormsModel::add_note()`), never a direct write to whatever table
  backs notes. Attribution reuses whatever this row's own "Created by"
  column already resolved, falling back to an unattributed, system-style
  note the same way "Created by" itself already defaults to unattributed.
  Not checked by the Validate step - there is no format for a note to get
  wrong, only presence or absence.
* Added "Create a new form from this CSV's headers" on the Upload step, as
  an alternative to picking an existing form: generates one field per CSV
  column via `GFAPI::add_form()`, guessing a simple, conservative field type
  from each header's own wording (email/phone/date/note-comment, otherwise
  plain text), with every generated field created not required. The Map
  step then shows which form was created, with a link to review it in the
  form editor, before you proceed - the guessed types are a starting point,
  never a guarantee. The Upload step no longer hides its whole form entirely
  on a site with no active forms yet, since this new path needs none.
* Added a small, bounded, persistent history log of completed imports, per
  form - date, who ran it, and the same imported/updated/skipped/filtered/
  failed counts the results screen already shows, and nothing else (no
  header, mapping, raw CSV data or per-row error detail). Stored one option
  per form (autoload off), capped at the most recent 100 entries per form,
  oldest dropped first - the same pattern saved mapping templates already
  established. Unlike a job's own record, this is never removed by the
  daily cleanup of abandoned import jobs, so it survives long after that
  cleanup prunes the job it came from; only the per-form cap, or
  uninstalling the plugin, ever removes an entry. See "View past imports" on
  the Upload step, next to any form with at least one recorded import.

= 1.4.2 =
* Closed two gaps found in the 1.4.1 same-site check for a remote URL:
    * A remote URL using a trailing-dot variant of this site's own domain
      (e.g. `example.com.` — a standard form that resolves exactly the same
      as the undotted name) was not recognised as naming this site, and
      could reach this site's own backend. Both sides of the comparison are
      now normalised before being checked.
    * A remote URL using this site's own already-resolved IP address
      directly, instead of its domain name, was not recognised either,
      since the existing check only ever compared hostnames. This site's
      own IP address is now also checked against the URL's, in addition to
      the hostname comparison.
* Known limitations and the Safeguards section updated to reflect both of
  the above, and to plainly disclose the scope of the new IP comparison
  (a single-record lookup, sharing the same kind of timing limitation
  already disclosed for DNS-rebinding) rather than leave it undocumented.

= 1.4.1 =
* Security hardening for the File Upload column feature added in 1.4.0,
  following an additional review:
    * A local, pre-staged filename that resolved to a hard link aliasing a
      file outside this job's own directory (rather than a genuinely staged
      file) is now rejected. The existing containment check already followed
      symlinks and junctions back to their real target; a hard link has no
      separate target for that check to follow, so it needed its own guard.
    * A remote download that was silently truncated at this plugin's own
      size cap (rather than genuinely being that size) is no longer accepted
      as if it were a complete, valid file.
    * A remote URL naming this site's own domain is now refused outright,
      before any request is attempted, closing a WordPress-core carve-out
      that otherwise applies to a site's own host and that this feature never
      had a reason to rely on.
* Known limitations expanded to plainly disclose two residual risk areas
  (a DNS-rebinding edge case shared by WordPress core's own safe-HTTP
  functions, and a narrow validate-then-copy window) rather than leave the
  existing SSRF-protection description reading as more complete than it is.

= 1.4.0 =
* Added support for mapping a CSV column to a Gravity Forms File Upload
  field. Each cell is either a bare filename already staged in that job's
  own protected pre-staged-files directory, or a full `http://`/`https://`
  URL — the resulting file is attached to the imported entry exactly as if
  it had been uploaded through the real form. This is the highest-risk
  feature added so far and was built and reviewed accordingly:
    * A remote URL is fetched only with WordPress's own SSRF-hardened
      `wp_safe_remote_get()`/`wp_safe_remote_head()`, never a raw HTTP
      client, which refuses a private, loopback or link-local address
      (including a cloud metadata endpoint) before ever opening a
      connection. Only `http://`/`https://` is attempted; every other
      scheme is refused before any request is constructed.
    * A remote file's declared size is checked before downloading, and the
      actual bytes received are capped independently of that declared size
      (default 10MB, filterable via `gei_file_upload_max_bytes`), so a
      server cannot bypass the limit by lying about or omitting it.
    * A local filename may only reference a file already staged inside that
      job's own directory; sanitize_file_name(), basename() and a
      realpath()-based containment check together reject any
      directory-traversal attempt rather than resolve it.
    * Every file's real content is verified with the same check Gravity
      Forms' own upload handler uses (WordPress core's
      `wp_check_filetype_and_ext()`, not just the extension or a claimed
      content type) against that field's own configured allowed types
      before it is ever attached.
    * A file is only ever copied into Gravity Forms' own per-form uploads
      structure, the same destination a genuine submission would use — the
      CSV itself never determines size limits, allowed types or the
      destination path.
    * Every temporary file is cleaned up on every code path, including a
      row that fails after its file was already placed; the pre-staged
      files directory is removed by the same stale-job pruning and
      uninstall sweep that already clean up an abandoned CSV upload.
* The **Validate** step now also flags a File Upload column value that
  looks like neither a plausible bare filename nor a valid `http(s)` URL —
  a syntax check only; Validate still never fetches a URL or writes to disk.
* The mapping screen now shows, next to any File Upload field, where to
  stage local files for that job and that a column may instead hold a URL.

= 1.3.1 =
* Fixed a crash on the mapping screen when the duplicate-check field arrived
  as an array instead of the expected single value (only reachable via a
  crafted request, not through the normal UI) — it now degrades safely to
  "no duplicate field selected" instead of a fatal error.

= 1.3.0 =
* Added saved mapping templates: name and persist the current column mapping,
  duplicate handling and notification setting, scoped to a form, and reload
  them on a future import into that same form from a new "Load a saved
  mapping" dropdown on the mapping screen. Stored one option per form
  (autoload off), capped at 50 templates per form; removed only by an
  explicit "Delete" action or on uninstall — never by the daily cleanup of
  abandoned import jobs, since a template is durable configuration, not
  throwaway job state.
* Added an optional conditional row-import filter: "Only import matching
  rows" restricts a run to rows whose CSV column meets a simple condition
  (equals, does not equal, contains, does not contain, is empty, is not
  empty), matched case-insensitively against the raw CSV value before any
  column mapping. Excluded rows are counted as a new, distinct "filtered"
  outcome — separate from imported, updated, skipped-duplicate and failed —
  in both the real import and the Validate step, and the Validate step never
  flags an excluded row with a possible issue, since it will never actually
  be imported. Deliberately a fixed operator set rather than free-form code
  or a regular expression, and one condition per import in this version.

= 1.2.0 =
* Added a **Validate** step between mapping and import: scans the whole
  mapped CSV and reports unparseable dates, values that don't match a
  choice field's configured options, and required fields left blank -
  before any entry is created. Nothing is written to the database during
  this scan.
* Added an **Update** mode alongside the existing duplicate-skip option: a
  matching row can now update the existing entry instead of being skipped,
  replacing only the fields this import maps and leaving everything else on
  that entry untouched. Skip remains the default and is unchanged.
* Rows that fail to import during a real run are now also written to a
  downloadable failed-rows CSV (original columns plus a Failure Reason
  column), so a large import's failures are no longer limited to the
  on-screen sample of the first 100. Only reachable through a gated,
  nonce'd, ownership-checked download link on the results screen.
* The results screen now shows an Updated count alongside
  Imported/Skipped/Failed.
* Stale-job pruning and plugin uninstall now also remove a job's failed-rows
  CSV alongside its main upload, so this doesn't introduce a new class of
  orphaned file the existing cleanup missed.

= 1.1.1 =
* Hardened the AJAX batch endpoint and the mapping/run screens so a job can
  only be driven or resumed by the user who started it (or a user with
  `gform_full_access`). Previously, any user who could pass the import
  capability check could act on any job if they somehow learned its job ID.

= 1.1.0 =
* Added a daily scheduled cleanup of abandoned import jobs and their CSVs, so
  an upload that's never finished doesn't sit on disk indefinitely.
* The duplicate-skip check now fetches every existing value for the matched
  field once per batch instead of running one lookup query per row, cutting
  a large import's duplicate-check cost by roughly two orders of magnitude.
* Closed a narrow race where two requests recovering the same abandoned
  import lock at once could both believe they held it.
* A stale job's lock record is now removed along with the job itself.
* count_rows() during upload now gets the same execution-time/memory
  headroom the batch loop already had, so a very large CSV can't stall the
  upload step itself.

= 1.0.0 =
* Initial release.
