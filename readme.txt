=== Gravity Entry Import ===
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.1
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

= Safeguards =

* Notifications are **off** by default — importing a back catalogue will not
  email every historical submitter unless you opt in.
* Optional duplicate skipping, matched on a field you choose. The field must
  also be mapped, and must hold a single value.
* Uploaded CSVs are stored outside the publicly readable uploads path, in a
  directory hardened against direct web access. The file is deleted as soon as
  the import finishes, and on uninstall.
* Progress is checkpointed after every row and each job takes an exclusive
  lock, so a timeout or a second browser tab cannot import the same rows twice.
* Entries are recorded as unattributed rather than credited to whoever ran the
  import, unless you map a "Created by" column.
* Batch size is adjustable for servers with short execution limits.

= Known limitations =

* Credit card fields are deliberately not importable.
* A single-column address goes into street line 1 only; map the sub-inputs
  separately for a full address.
* A single-column name splits on the first space, so "Mary Jane Smith" becomes
  first "Mary", last "Jane Smith". Map First and Last separately to avoid this.
* List fields are imported as simple rows. A List field with multiple columns
  configured is not supported.
* Every row creates a new entry; nothing is ever updated in place.
* Values are stored as-is. If entries are later exported back out to CSV, a
  cell beginning with `=`, `+`, `-` or `@` is a spreadsheet formula-injection
  risk in the downstream export, as it would be for any hand-entered entry.

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
   "Upload and continue". The first row of the CSV must be column headers.
3. **Map**: each form field is listed on the left; pick the CSV column that
   feeds it on the right. Likely matches are auto-suggested from the header
   text, so check them rather than starting from scratch. A live sample value
   from the first data row is shown next to each mapping so you can confirm
   it's the right column before importing anything.
4. Set the options for this run — duplicate skipping, notifications, rows per
   batch — then click "Continue to import".
5. **Import**: click "Start import" and watch the progress bar. It's safe to
   leave the tab and come back; an interrupted import resumes from the same
   screen without re-importing rows already processed.

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
   **Skip rows that already exist** matched on a field that's unique per
   entry (email is the usual choice).
5. Start the import.

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
4. Leave notifications off and start the import.

= Use case: re-running an import after fixing bad data =

An earlier import had a wrong column mapping or a batch of malformed rows,
the CSV has since been corrected, and it needs to go back in without
duplicating the rows that already succeeded.

1. Fix the problem in the CSV (or in the mapping) and re-upload it.
2. Turn on **Skip rows that already exist**, matched on a field that reliably
   identifies one entry (email, an order/reference number — anything unique
   per row and already mapped).
3. Re-run the import. Rows matching an entry already on the form are skipped;
   only genuinely new or previously-failed rows are added.
4. This only prevents duplicates going forward — it does not fix rows that
   already imported wrong. Correct or delete those directly under
   **Forms → Entries** first if the bad data is already in.

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

No. Every row creates a new entry. The duplicate option skips rows that already
match an existing entry; it does not merge into them.

= How large a file can it handle? =

The upload itself is bounded by the server's upload limit, shown on the upload
screen. Beyond that, rows are processed in batches and the file is read from a
stored byte offset each time, so total file size is not the constraint — expect
roughly 25 rows per request at the default batch size.

== Hooks ==

`gei_entry` — filters each entry array before insertion.

    add_filter( 'gei_entry', function ( $entry, $form ) {
        $entry['source_url'] = 'https://example.com/legacy-form';
        return $entry;
    }, 10, 2 );

`gei_capability` — filters the capability required to import.
Defaults to `gravityforms_edit_entries`. Any user with Gravity Forms' own
`gform_full_access` (administrators, by default) can always use the importer
regardless of this filter, matching how Gravity Forms' own admin menus work.

== Changelog ==

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
