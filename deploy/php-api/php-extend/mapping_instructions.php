<?php
declare(strict_types=1);

/**
 * Mapping Instructions bubble extracted from index.php render_admin().
 * Usage from index.php (inside render_admin):
 *   require_once __DIR__ . '/php-extend/mapping_instructions.php';
 *   render_mapping_instructions($ctx);
 */
function render_mapping_instructions(array $ctx): void {
?>
        <div id="mapping-instructions" class="bubble" aria-live="polite" style="order:3">
          <h2 class="info-title">Mapping Instructions</h2>
          <div class="info-subtitle" id="mapping-instructions-subtitle">
            Mapping instructions for <b><?= htmlspecialchars((string)($ctx['map_table'] ?? 'none selected'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></b>
          </div>

          <div class="info-content" id="mapping-instructions-content">
            <?php
              $mapTableLower = strtolower((string)($ctx['map_table'] ?? ''));
              if ($mapTableLower && strpos($mapTableLower, 'donation') !== false): ?>
                <p><b>Importing into donations (and auto-creating donors)</b></p>
                <p><b>General rule</b></p>
                <ul>
                  <li>Donors are auto-created/linked in the <code>donors</code> table.</li>
                  <li>Candidates are auto-linked via <code>people_id</code> or <code>original_id</code>.</li>
                  <li>You just need to map the correct CSV columns.</li>
                </ul>

                <p><b>Case: (year)_donor_information_for_candidate.csv</b></p>
                <ol>
                  <li>In <b>Admin → CSV Upload → Table</b>:
                    <ul>
                      <li>Destination table: <b>donations</b></li>
                      <li>Upload file: <b>(year)_donor_information_for_candidate.csv</b></li>
                    </ul>
                  </li>
                  <li>On Mapping Screen — map columns as follows:</li>
                </ol>

                <p><b>Required / Recommended</b></p>
                <ul>
                  <li><code>_(year)CandidateDonations_Id</code> → <b>original_id</b><br>
                    <small>Links each donation to the correct <code>candidate_overview</code> row for (year)</small>
                  </li>
                  <li><code>DateReceived</code> → <b>date</b></li>
                  <li><code>DonationAmount</code> → <b>amount</b> <small>(auto‑strips $ and commas)</small></li>
                  <li><code>MoneyOrGoodsServices</code> → <b>money_or_goods_services</b></li>
                  <li><code>OtherDetail</code> → <b>notes</b></li>
                </ul>

                <p><b>Donor (auto‑created/linked in donors table)</b></p>
                <ul>
                  <li><code>DonorName_First</code> → <b>donor_first_name</b></li>
                  <li><code>DonorName_Last</code> → <b>donor_last_name</b></li>
                  <li><code>CompanyOrOrganisation</code> → <b>donor_org_name</b></li>
                </ul>

                <p><b>Address (builds “location” automatically)</b></p>
                <ul>
                  <li><code>Address_Line1</code> → <b>address_line1</b></li>
                  <li><code>Address_Line2</code> → <b>address_line2</b></li>
                  <li><code>Address_City</code> → <b>address_city</b></li>
                  <li><code>Address_PostalCode</code> → <b>address_postalcode</b></li>
                  <li><code>Address_Country</code> → <b>address_country</b></li>
                </ul>

                <p><b>Ignore (leave unmapped)</b></p>
                <ul>
                  <li><code>PartADonationEntry_Id</code></li>
                  <li><code>DateRangeFinishDate</code></li>
                  <li><code>AdditionalDateReceived</code>, <code>AdditionalDateReceived2..6</code></li>
                  <li><code>Contributions</code> <small>(optional: map to notes if you want the text)</small></li>
                  <li><code>DonorName_Prefix</code></li>
                  <li>Any system fields (<code>donor_id</code>, <code>candidate_person_id</code>, <code>candidate_overview_id</code>, <code>created_at</code>)</li>
                  <li><code>CandidateDonations2023Test_Id</code> <small>(not used for 2011–2020)</small></li>
                </ul>

                <p><b>Click “Import with Mapping”.</b> Optional: tick <b>Truncate table before insert</b> for a clean reload.</p>

                <p><b>What Happens Automatically</b></p>
                <ul>
                  <li><b>Donors:</b> Auto‑created/linked using donor_first_name/donor_last_name or donor_org_name. A <code>normalized_name</code> prevents duplicate donors. If a donor is a person, a matching record is also ensured in <code>people</code>.</li>
                  <li><b>Candidate linking priority:</b>
                    <ol>
                      <li><code>people_id</code> / <code>candidate_person_id</code> (not in your CSV)</li>
                      <li>Candidate first/last name (if present)</li>
                      <li><code>original_id</code> + <code>year</code> (preferred)</li>
                    </ol>
                  </li>
                  <li><b>Year:</b> Taken from <code>DateReceived</code> where possible; otherwise inferred from filename/header (2011).</li>
                  <li><b>Location:</b> Built automatically from the mapped address fields if no single location column is provided.</li>
                  <li><b>Insert:</b> Creates rows in <code>donations</code> with year, date, amount, money_or_goods_services, notes, location, donor_id, candidate_person_id, candidate_overview_id.</li>
                  <li><b>Re‑imports:</b> Uses INSERT (not upsert). If you re‑run, use <b>Truncate</b> first for a clean reload.</li>
                </ul>

                <p><b>Tips</b></p>
                <ul>
                  <li>Never map <code>_(year)CandidateDonations_Id</code> to <code>people_id</code>. Always map it to <code>original_id</code>.</li>
                  <li>If a row’s <code>original_id</code> doesn’t match any <code>candidate_overview</code> for (year):
                    <ul>
                      <li>The donation and donor still insert.</li>
                      <li><code>candidate_overview_id</code> remains NULL.</li>
                    </ul>
                  </li>
                </ul>

                <p><b>Optional Verification Queries</b></p>
                <p>Run in <b>Admin → Read‑only Query</b>:</p>
<pre>-- Check newly created donors
SELECT id, first_name, last_name, org_name 
FROM donors 
ORDER BY id DESC LIMIT 10;

-- Donations linked to a person
SELECT COUNT(*) 
FROM donations 
WHERE year = 2011 
  AND candidate_person_id IS NOT NULL;

-- Donations linked to candidate_overview
SELECT COUNT(*) 
FROM donations 
WHERE year = 2011 
  AND candidate_overview_id IS NOT NULL;</pre>
            <?php elseif ($mapTableLower && strpos($mapTableLower, 'candidate_overview') !== false): ?>
                <p><b>Importing candidate_overview CSVs</b></p>
                <p><b>General rule</b></p>
                <ul>
                  <li>Never do manual column mapping.</li>
                  <li>Always leave every dropdown as <b>Ignore</b>.</li>
                  <li>Click <b>Import with Mapping</b> — the enhanced importer automatically handles mappings.</li>
                </ul>

                <p><b>Case 1: Cleaned 2023 Candidates File</b><br>File: Electorate-Candidates-2023-GE-9-October-cleaned.csv</p>
                <ol>
                  <li>Set destination table to <b>candidate_overview</b> and select the file; click <b>Continue to Mapping</b>.</li>
                  <li>On Mapping Screen:
                    <ul>
                      <li>Info box confirms enhanced importer is active.</li>
                      <li><b>Important:</b> leave all columns as <b>Ignore</b>.</li>
                      <li>Optional: tick <b>Truncate table before insert</b> to clear the table.</li>
                      <li>Click <b>Import with Mapping</b>.</li>
                    </ul>
                  </li>
                </ol>
                <p><b>Automatic Import Behaviour</b></p>
                <ul>
                  <li>First Name → people.first_name</li>
                  <li>Last Name → people.last_name</li>
                  <li>Electorate → resolves/creates electorates.name → electorate_id</li>
                  <li>Party → resolves/creates parties.name → party_id</li>
                  <li>year = 2023 inferred from filename</li>
                  <li>Inserts into candidate_overview with people_id, party_id, electorate_id, year; totals stay NULL.</li>
                </ul>
                <p><b>Notes</b></p>
                <ul>
                  <li>Header normalization happens automatically (e.g., "First Name" → first_name).</li>
                  <li>Keep names consistent to avoid duplicate parties/electorates.</li>
                  <li><code>original_id</code> not populated for this file (fine).</li>
                </ul>

                <p><b>Case 2: 2011–2024 Candidate Donations CSV</b><br>File: (year)_candidate_donations.csv (year = 2011, 2014, 2017, 2020, 2023)</p>
                <ol>
                  <li>In <b>Admin → CSV Upload → Table</b>:
                    <ul>
                      <li>Destination table: <b>candidate_overview</b></li>
                      <li>Upload file: <b>(year)_candidate_donations.csv</b></li>
                    </ul>
                  </li>
                  <li>On Mapping Screen:
                    <ul>
                      <li><b>Do not map anything</b>; the manual grid is ignored.</li>
                      <li>Optional: tick <b>Truncate</b> to clear the table before insert.</li>
                      <li>Click <b>Import with Mapping</b>.</li>
                    </ul>
                  </li>
                </ol>
                <p><b>Automatic Import Behaviour</b></p>
                <ul>
                  <li>Reads headers: <code>CandidateName_First</code>, <code>CandidateName_Last</code>, <code>Electorate</code>, <code>Party</code>.</li>
                  <li>Creates/links people by candidate name → <code>people_id</code>.</li>
                  <li>Creates/links party and electorate by name.</li>
                  <li>Year detected from <code>_2011CandidateDonations_Id</code> (or year in filename).</li>
                  <li><code>_2011CandidateDonations_Id</code> is stored in <code>candidate_overview.original_id</code>.</li>
                  <li>Totals auto‑parsed (<code>TotalDonationsACD</code>, <code>PartA/B/C/D</code>, <code>Expenses</code>).</li>
                  <li>De‑dupe: <code>UNIQUE(year, people_id)</code> + <code>INSERT IGNORE</code>.</li>
                </ul>
                <p><b>Important Correction</b></p>
                <ul>
                  <li><b>Never</b> map <code>_2011CandidateDonations_Id</code> to <code>people_id</code>. It is stored in <code>candidate_overview.original_id</code>.</li>
                </ul>
<?php elseif ($mapTableLower === 'stg_overview_2023'): ?>
                <p><b>stg_overview_2023 — What this is</b></p>
                <ul>
                  <li>Temporary <b>staging table</b> used to load the 2023 candidate donations CSV before running the Maintenance backfill.</li>
                  <li>The <b>Maintenance → “Backfill 2023 original_id”</b> action copies <code>candidatedonations2023test_id</code> from this staging table into <code>candidate_overview.original_id</code> (year 2023) so <b>donations</b> can link correctly.</li>
                </ul>

                <p><b>When and why to use it</b></p>
                <ol>
                  <li>Use when preparing the site to link 2023 donations → candidates.</li>
                  <li>Load <b>candidate_csv/2023_candidate_donations.csv</b> into <b>stg_overview_2023</b>.</li>
                  <li>Run <b>Maintenance → Backfill 2023 original_id</b> to safely fill <code>candidate_overview.original_id</code> for 2023.</li>
                </ol>

                <p><b>Expected/Helpful columns in stg_overview_2023</b></p>
                <ul>
                  <li><code>candidatedonations2023test_id</code> <small>(REQUIRED to copy into candidate_overview.original_id)</small></li>
                  <li><code>candidatename_first</code>, <code>candidatename_last</code></li>
                  <li><code>party</code>, <code>electorate</code></li>
                </ul>

                <p><b>How to load the CSV</b></p>
                <ol>
                  <li>Recommended (no manual mapping): <b>Import CSVs from Server</b>
                    <ul>
                      <li>Place the file under <code>data/candidate_csv/2023_candidate_donations.csv</code> on the server.</li>
                      <li>Choose the file and set Target Table to <b>stg_overview_2023</b> (create if missing).</li>
                    </ul>
                  </li>
                  <li>Alternative: <b>CSV Upload → Table</b>
                    <ul>
                      <li>Destination table: <b>stg_overview_2023</b></li>
                      <li>Ensure the columns listed above are present; you may <b>Create column (TEXT)</b> if needed.</li>
                      <li>It’s fine to leave unrelated columns as <b>Ignore</b>.</li>
                    </ul>
                  </li>
                </ol>

                <p><b>After loading</b></p>
                <ol>
                  <li>Go to <b>Maintenance</b> and click <b>Backfill 2023 original_id</b>.</li>
                  <li>Check the result message: it shows before/after counts and updated rows per matching step.</li>
                  <li>Optional: once backfill finishes, you can <b>Truncate</b> <code>stg_overview_2023</code> to keep things tidy.</li>
                </ol>

                <p><b>Notes & Troubleshooting</b></p>
                <ul>
                  <li>The backfill is <b>idempotent</b>: it only fills <code>NULL</code> values and can be re-run safely.</li>
                  <li>If no rows are updated, confirm staging columns exist (case-insensitive): <code>candidatename_first</code>, <code>candidatename_last</code>, <code>party</code>, <code>electorate</code>, and <code>candidatedonations2023test_id</code>.</li>
                  <li>You can re-run after improving the staging file; only NULL <code>original_id</code> values will be filled.</li>
                </ul>
<?php elseif ($mapTableLower && strpos($mapTableLower, 'meetings') !== false): ?>
                <p><b>Importing Ministerial Diaries into meetings Table</b></p>
                <p><b>General Rule</b></p>
                <ul>
                  <li>Use the <b>AI Name Finder</b> first to enrich your CSV.</li>
                  <li>Then import into <b>meetings</b>.</li>
                  <li>The importer auto‑creates/links people for <b>minister</b> and <b>attendees</b>.</li>
                </ul>

                <p><b>Step 1 — Enrich CSV with AI Name Finder</b></p>
                <ul>
                  <li><b>Tool:</b> AI Name Finder</li>
                  <li><b>Mode:</b> Ministerial Diaries CSV → enrich + flag attendees</li>
                </ul>
                <p><b>Input CSV headers (expected)</b></p>
                <ul>
                  <li>Minister</li>
                  <li>Date</li>
                  <li>Schedule Time</li>
                  <li>Title</li>
                  <li>Type</li>
                  <li>Portfolio</li>
                  <li>Location</li>
                  <li>Notes</li>
                  <li>With/Attendees</li>
                </ul>
                <p><b>Output (enriched CSV includes extra columns)</b></p>
                <ul>
                  <li><code>Attendees_Text</code>: normalized text of attendees</li>
                  <li><code>Attendees_Names</code>: AI‑flagged person names, semicolon‑separated<br>
                    <small>Example: "John Smith; Jane Doe"</small>
                  </li>
                </ul>

                <p><b>Step 2 — Import Enriched CSV into meetings</b></p>
                <ol>
                  <li>In <b>Admin → CSV Upload → Table</b>:
                    <ul>
                      <li>Destination table: <b>meetings</b></li>
                    </ul>
                  </li>
                  <li>On Mapping Screen — map columns as follows:</li>
                </ol>
                <p><b>Required mappings</b></p>
                <ul>
                  <li><code>Date</code> → <b>date</b></li>
                  <li><code>Title</code> → <b>title</b></li>
                  <li><code>Type</code> → <b>type</b></li>
                  <li><code>Portfolio</code> → <b>portfolio</b></li>
                  <li><code>Location</code> → <b>location</b></li>
                  <li><code>Notes</code> → <b>notes</b></li>
                  <li><code>Attendees_Text</code> → <b>with_text</b></li>
                </ul>
                <p><b>Leave as Ignore</b></p>
                <ul>
                  <li><code>Minister</code> → <b>Ignore</b> (importer derives <code>minister_person_id</code> automatically)</li>
                  <li><code>Attendees_Names</code> → <b>Ignore</b> (importer reads and upserts people automatically)</li>
                </ul>
                <p><b>Time handling</b></p>
                <ul>
                  <li>If you only have <code>Schedule Time</code> (e.g., "9:30 AM - 10:00 AM"): <b>leave unmapped</b> — importer parses into <code>start_time</code> / <code>end_time</code>.</li>
                  <li>If you already have <code>Start_Time</code> and <code>End_Time</code> columns:
                    <ul>
                      <li><code>Start_Time</code> → <b>start_time</b></li>
                      <li><code>End_Time</code> → <b>end_time</b></li>
                    </ul>
                    <small>(Both approaches work)</small>
                  </li>
                </ul>

                <p><b>What Happens Automatically</b></p>
                <ul>
                  <li><b>Minister linking</b>
                    <ul>
                      <li>Importer resolves <code>minister_person_id</code> from the <code>Minister</code> column (or AI‑enriched ai_first_name / ai_last_name).</li>
                      <li>If the minister doesn’t exist in <code>people</code>, it creates them.</li>
                    </ul>
                  </li>
                  <li><b>Attendees linking</b>
                    <ul>
                      <li>Importer reads <code>Attendees_Names</code>.</li>
                      <li>Each name is split into first/last (best‑effort) and upserted into <code>people</code>, preventing duplicates.</li>
                      <li>Attendees are then linked to the meeting.</li>
                    </ul>
                  </li>
                </ul>
<?php elseif ($mapTableLower && strpos($mapTableLower, 'people') !== false): ?>
                <p><b>Importing into the people table</b></p>
                <p><b>General rule</b></p>
                <ul>
                  <li>Use destination table: <b>people</b></li>
                  <li>Map only the name fields.</li>
                  <li>Leave other columns as <b>Ignore</b>.</li>
                </ul>
                <p><b>Steps</b></p>
                <ol>
                  <li>In <b>Admin → CSV Upload → Table</b>:
                    <ul>
                      <li>Destination table: <b>people</b></li>
                      <li>Upload your CSV</li>
                    </ul>
                  </li>
                  <li>On Mapping Screen (recommended): 
                    <ul>
                      <li><code>First_Name</code> (or <code>First Name</code>) → <b>first_name</b></li>
                      <li><code>Last_Name</code> (or <code>Last Name</code>) → <b>last_name</b></li>
                      <li><code>Prefix</code> / <code>CandidateName_Prefix</code> (if present) → <b>prefix</b> (or choose “Create column 'prefix' (TEXT)” if the column doesn’t exist)</li>
                      <li><code>Electorate</code> → <b>Ignore</b></li>
                      <li><code>Party</code> → <b>Ignore</b></li>
                    </ul>
                  </li>
                  <li>Click <b>Import with Mapping</b>.</li>
                </ol>
                <p><b>What happens</b></p>
                <ul>
                  <li>Each row inserts one person with an auto-generated <code>people.id</code>.</li>
                  <li>These records can be linked later by the <code>candidate_overview</code> importer.</li>
                  <li>Matching is done case-insensitively on <code>first_name</code> + <code>last_name</code>.</li>
                  <li>If a person is missing, <code>candidate_overview</code> will create them automatically.</li>
                </ul>
                <p><b>Avoiding duplicates</b></p>
                <ul>
                  <li>The generic importer uses <code>INSERT IGNORE</code>; without a <code>UNIQUE</code> constraint, exact duplicates may slip through.</li>
                </ul>
                <p>Optional checks (Admin → Read-only Query):</p>
<pre>-- Find potential duplicates (case-insensitive)
SELECT 
  UPPER(first_name) AS fn, 
  UPPER(last_name) AS ln, 
  COUNT(*) AS c
FROM people
GROUP BY UPPER(first_name), UPPER(last_name)
HAVING COUNT(*) > 1
ORDER BY c DESC;</pre>
                <p>Optional uniqueness enforcement (after cleaning duplicates):</p>
<pre>ALTER TABLE people 
ADD UNIQUE idx_people_name (first_name, last_name);</pre>
                <p><b>Notes</b></p>
                <ul>
                  <li><b>Electorate</b> and <b>Party</b> are not part of the <code>people</code> table.
                    <ul>
                      <li>To load them, import into their own tables:
                        <ul>
                          <li><code>parties</code>: map <b>Party</b> → <b>name</b></li>
                          <li><code>electorates</code>: map <b>Electorate</b> → <b>name</b></li>
                        </ul>
                      </li>
                    </ul>
                  </li>
                  <li>You do not need to prefill <code>people</code> for <code>candidate_overview</code> imports—missing people are created automatically. Prefill only if you want specific capitalization or to control prefixes/titles.</li>
                </ul>
<?php elseif ($mapTableLower && (strpos($mapTableLower, 'organization') !== false)): ?>
                <p><b>Organizations — use Maintenance buttons</b></p>
                <ul>
                  <li>Use <b>Maintenance → Create/Repair Events Tables</b> first to ensure the events schema exists (attendees_text, host_person_id, host_organization_id, unique meeting_id). Safe to re‑run.</li>
                  <li>Then run <b>Maintenance → Bootstrap Events from Meetings</b> to populate <code>events</code> from ministerial diaries. This pre-fills attendees from mappings/with_text and <b>always adds the diary’s minister</b> as an attendee.</li>
                  <li>Direct CSV import into <code>organizations</code> is usually unnecessary. If you do import, map <b>organization/company → name</b>, avoid id/primary key columns, and leave unrelated columns as <b>Ignore</b>.</li>
                </ul>
<?php elseif ($mapTableLower && $mapTableLower === 'events'): ?>
                <p><b>Events</b></p>
                <ul>
                  <li>Title → <b>title</b>; Date → <b>date</b>; Start → <b>start_time</b>; End → <b>end_time</b>; Location → <b>location</b>; Notes → <b>notes</b>; Attendees (Names/Text) → <b>attendees_text</b>.</li>
                </ul>
<?php else: ?>
                <p>Pick a destination table then upload a CSV. The next step lets you map CSV columns to database columns. Use “Ignore” for columns you do not want imported.</p>
                <ul>
                  <li>Avoid mapping id/primary key columns.</li>
                  <li>Map only columns that exist in the destination table (or choose “Create column” to add a new TEXT column).</li>
                  <li>Use “Truncate” if you want to replace existing rows.</li>
                </ul>
            <?php endif; ?>
          </div>
        </div>
        <script>
        (function(){
          // Initial table from server context (when returning to page with mapping section)
          var initialTable = <?php echo json_encode((string)($ctx['map_table'] ?? '')); ?>;
          var sel = document.querySelector('select[name="dest_table_select"]');
          var custom = document.querySelector('input[name="table"]');
          var subtitleEl = document.getElementById('mapping-instructions-subtitle');
          var contentEl = document.getElementById('mapping-instructions-content');

          // Mapping Instructions bubble appears under the Read-only Query form
          var mapEl = document.getElementById('mapping-instructions');
          var queryForm = document.querySelector('form[action*="/admin/query"]');
          if (mapEl && queryForm && mapEl.previousElementSibling !== queryForm) {
            queryForm.insertAdjacentElement('afterend', mapEl);
          } else if (mapEl && !queryForm) {
            // Fallback: place after the query textarea if form selector fails
            var qta = document.querySelector('textarea[name="query"]');
            if (qta && qta.parentElement) {
              qta.parentElement.insertAdjacentElement('afterend', mapEl);
            }
          }

          function htmlDefault(){
            return '<p>Pick a destination table then upload a CSV. The next step lets you map CSV columns to database columns. Use "Ignore" for columns you do not want imported.</p>'
              + '<ul>'
              + '<li>Do not map id columns.</li>'
              + '<li>Only map columns that exist in the destination table (or choose "Create column" to add a new TEXT column).</li>'
              + '<li>Use "Truncate" if you want to replace existing rows.</li>'
              + '</ul>';
          }

          function htmlDonations(){
            return '<p><b>Importing into donations (and auto-creating donors)</b></p>'
              + '<p><b>General rule</b></p>'
              + '<ul>'
              + '<li>Donors are auto-created/linked in the <code>donors</code> table.</li>'
              + '<li>Candidates are auto-linked via <code>people_id</code> or <code>original_id</code>.</li>'
              + '<li>You just need to map the correct CSV columns.</li>'
              + '</ul>'
              + '<p><b>Case: (year)_donor_information_for_candidate.csv</b></p>'
              + '<ol>'
              + '<li>In <b>Admin → CSV Upload → Table</b>:'
              +   '<ul>'
              +     '<li>Destination table: <b>donations</b></li>'
              +     '<li>Upload file: <b>(year)_donor_information_for_candidate.csv</b></li>'
              +   '</ul>'
              + '</li>'
              + '<li>On Mapping Screen — map columns as follows:</li>'
              + '</ol>'
              + '<p><b>Required / Recommended</b></p>'
              + '<ul>'
              + '<li><code>_(year)CandidateDonations_Id</code> → <b>original_id</b><br><small>Links each donation to the correct <code>candidate_overview</code> row for (year)</small></li>'
              + '<li><code>DateReceived</code> → <b>date</b></li>'
              + '<li><code>DonationAmount</code> → <b>amount</b> <small>(auto‑strips $ and commas)</small></li>'
              + '<li><code>MoneyOrGoodsServices</code> → <b>money_or_goods_services</b></li>'
              + '<li><code>OtherDetail</code> → <b>notes</b></li>'
              + '</ul>'
              + '<p><b>Donor (auto‑created/linked in donors table)</b></p>'
              + '<ul>'
              + '<li><code>DonorName_First</code> → <b>donor_first_name</b></li>'
              + '<li><code>DonorName_Last</code> → <b>donor_last_name</b></li>'
              + '<li><code>CompanyOrOrganisation</code> → <b>donor_org_name</b></li>'
              + '</ul>'
              + '<p><b>Address (builds “location” automatically)</b></p>'
              + '<ul>'
              + '<li><code>Address_Line1</code> → <b>address_line1</b></li>'
              + '<li><code>Address_Line2</code> → <b>address_line2</b></li>'
              + '<li><code>Address_City</code> → <b>address_city</b></li>'
              + '<li><code>Address_PostalCode</code> → <b>address_postalcode</b></li>'
              + '<li><code>Address_Country</code> → <b>address_country</b></li>'
              + '</ul>'
              + '<p><b>Ignore (leave unmapped)</b></p>'
              + '<ul>'
              + '<li><code>PartADonationEntry_Id</code></li>'
              + '<li><code>DateRangeFinishDate</code></li>'
              + '<li><code>AdditionalDateReceived</code>, <code>AdditionalDateReceived2..6</code></li>'
              + '<li><code>Contributions</code> <small>(optional: map to notes if you want the text)</small></li>'
              + '<li><code>DonorName_Prefix</code></li>'
              + '<li>Any system fields (<code>donor_id</code>, <code>candidate_person_id</code>, <code>candidate_overview_id</code>, <code>created_at</code>)</li>'
              + '<li><code>CandidateDonations2023Test_Id</code> <small>(not used for 2011–2020)</small></li>'
              + '</ul>'
              + '<p><b>Click “Import with Mapping”.</b> Optional: tick <b>Truncate table before insert</b> for a clean reload.</p>'
              + '<p><b>What Happens Automatically</b></p>'
              + '<ul>'
              + '<li><b>Donors:</b> Auto‑created/linked using donor_first_name/donor_last_name or donor_org_name. A <code>normalized_name</code> prevents duplicate donors. If a donor is a person, a matching record is also ensured in <code>people</code>.</li>'
              + '<li><b>Candidate linking priority:</b>'
              +   '<ol>'
              +     '<li><code>people_id</code> / <code>candidate_person_id</code> (not in your CSV)</li>'
              +     '<li>Candidate first/last name (if present)</li>'
              +     '<li><code>original_id</code> + <code>year</code> (preferred)</li>'
              +   '</ol>'
              + '</li>'
              + '<li><b>Year:</b> Taken from <code>DateReceived</code> where possible; otherwise inferred from filename/header (2011).</li>'
              + '<li><b>Location:</b> Built automatically from the mapped address fields if no single location column is provided.</li>'
              + '<li><b>Insert:</b> Creates rows in <code>donations</code> with year, date, amount, money_or_goods_services, notes, location, donor_id, candidate_person_id, candidate_overview_id.</li>'
              + '<li><b>Re‑imports:</b> Uses INSERT (not upsert). If you re‑run, use <b>Truncate</b> first for a clean reload.</li>'
              + '</ul>'
              + '<p><b>Tips</b></p>'
              + '<ul>'
              + '<li>Never map <code>_(year)CandidateDonations_Id</code> to <code>people_id</code>. Always map it to <code>original_id</code>.</li>'
              + '<li>If a row’s <code>original_id</code> doesn’t match any <code>candidate_overview</code> for (year):'
              +   '<ul>'
              +     '<li>The donation and donor still insert.</li>'
              +     '<li><code>candidate_overview_id</code> remains NULL.</li>'
              +   '</ul>'
              + '</li>'
              + '</ul>'
              + '<p><b>Optional Verification Queries</b></p>'
              + '<pre>-- Check newly created donors\nSELECT id, first_name, last_name, org_name \nFROM donors \nORDER BY id DESC LIMIT 10;\n\n-- Donations linked to a person\nSELECT COUNT(*) \nFROM donations \nWHERE year = 2011 \n  AND candidate_person_id IS NOT NULL;\n\n-- Donations linked to candidate_overview\nSELECT COUNT(*) \nFROM donations \nWHERE year = 2011 \n  AND candidate_overview_id IS NOT NULL;</pre>';
          }
          function htmlCandidateOverview(){
            return '<p>Candidate Overview uses an enhanced importer and ignores the manual mapping grid.</p>'
              + '<ul>'
              + '<li>Ensure your CSV has candidate <b>first</b> and <b>last</b> name, <b>party</b>, and <b>electorate</b> headers (common variants are detected automatically).</li>'
              + '<li><b>year</b> is inferred from the file or headers; include it if available.</li>'
              + '<li><b>original_id</b> (e.g. 2011candidatedonations_id or candidatedonations2023test_id) helps link to donations later.</li>'
              + '<li>Other totals (part_a, total_donations, total_expenses, etc.) are optional.</li>'
              + '</ul>'
              + '<p>Why ignore? Manual mapping is not used here—the importer reads known headers directly.</p>';
          }

          function htmlMeetings(){
            return '<p><b>Importing Ministerial Diaries into meetings Table</b></p>'
              + '<p><b>General Rule</b></p>'
              + '<ul>'
              + '<li>Use the <b>AI Name Finder</b> first to enrich your CSV.</li>'
              + '<li>Then import into <b>meetings</b>.</li>'
              + '<li>The importer auto‑creates/links people for <b>minister</b> and <b>attendees</b>.</li>'
              + '</ul>'
              + '<p><b>Step 1 — Enrich CSV with AI Name Finder</b></p>'
              + '<ul>'
              + '<li><b>Tool:</b> AI Name Finder</li>'
              + '<li><b>Mode:</b> Ministerial Diaries CSV → enrich + flag attendees</li>'
              + '</ul>'
              + '<p><b>Input CSV headers (expected)</b></p>'
              + '<ul>'
              + '<li>Minister</li>'
              + '<li>Date</li>'
              + '<li>Schedule Time</li>'
              + '<li>Title</li>'
              + '<li>Type</li>'
              + '<li>Portfolio</li>'
              + '<li>Location</li>'
              + '<li>Notes</li>'
              + '<li>With/Attendees</li>'
              + '</ul>'
              + '<p><b>Output (enriched CSV includes extra columns)</b></p>'
              + '<ul>'
              + '<li><code>Attendees_Text</code>: normalized text of attendees</li>'
              + '<li><code>Attendees_Names</code>: AI‑flagged person names, semicolon‑separated</li>'
              + '</ul>'
              + '<p><b>Step 2 — Import Enriched CSV into meetings</b></p>'
              + '<ol>'
              + '<li>In <b>Admin → CSV Upload → Table</b>: Destination table: <b>meetings</b></li>'
              + '<li>On Mapping Screen — map columns as follows:</li>'
              + '</ol>'
              + '<p><b>Required mappings</b></p>'
              + '<ul>'
              + '<li><code>Date</code> → <b>date</b></li>'
              + '<li><code>Title</code> → <b>title</b></li>'
              + '<li><code>Type</code> → <b>type</b></li>'
              + '<li><code>Portfolio</code> → <b>portfolio</b></li>'
              + '<li><code>Location</code> → <b>location</b></li>'
              + '<li><code>Notes</code> → <b>notes</b></li>'
              + '<li><code>Attendees_Text</code> → <b>with_text</b></li>'
              + '</ul>'
              + '<p><b>Leave as Ignore</b></p>'
              + '<ul>'
              + '<li><code>Minister</code> → <b>Ignore</b> (importer derives <code>minister_person_id</code> automatically)</li>'
              + '<li><code>Attendees_Names</code> → <b>Ignore</b> (importer reads and upserts people automatically)</li>'
              + '</ul>'
              + '<p><b>Time handling</b></p>'
              + '<ul>'
              + '<li>If only <code>Schedule Time</code> is present (e.g., "9:30 AM - 10:00 AM"), leave unmapped — importer parses into <code>start_time</code>/<code>end_time</code>.</li>'
              + '<li>If you have <code>Start_Time</code> and <code>End_Time</code> columns, map them directly.</li>'
              + '</ul>'
              + '<p><b>What Happens Automatically</b></p>'
              + '<ul>'
              + '<li><b>Minister linking:</b> importer resolves <code>minister_person_id</code> from <code>Minister</code> (or AI ai_first_name/ai_last_name) and creates the person if missing.</li>'
              + '<li><b>Attendees linking:</b> importer reads <code>Attendees_Names</code>, splits to first/last, upserts into <code>people</code>, and links attendees to the meeting.</li>'
              + '</ul>';
          }

          function htmlPeople(){
            return '<p><b>Importing into the people table</b></p>'
              + '<p><b>General rule</b></p>'
              + '<ul>'
              + '<li>Use destination table: <b>people</b></li>'
              + '<li>Map only the name fields.</li>'
              + '<li>Leave other columns as <b>Ignore</b>.</li>'
              + '</ul>'
              + '<p><b>Steps</b></p>'
              + '<ol>'
              + '<li>In <b>Admin → CSV Upload → Table</b>:'
              +   '<ul>'
              +     '<li>Destination table: <b>people</b></li>'
              +     '<li>Upload your CSV</li>'
              +   '</ul>'
              + '</li>'
              + '<li>On Mapping Screen (recommended):'
              +   '<ul>'
              +     '<li><code>First_Name</code> (or <code>First Name</code>) → <b>first_name</b></li>'
              +     '<li><code>Last_Name</code> (or <code>Last Name</code>) → <b>last_name</b></li>'
              +     '<li><code>Prefix</code> / <code>CandidateName_Prefix</code> (if present) → <b>prefix</b> (or choose “Create column \'prefix\' (TEXT)” if needed)</li>'
              +     '<li><code>Electorate</code> → <b>Ignore</b></li>'
              +     '<li><code>Party</code> → <b>Ignore</b></li>'
              +   '</ul>'
              + '</li>'
              + '<li>Click <b>Import with Mapping</b>.</li>'
              + '</ol>'
              + '<p><b>What happens</b></p>'
              + '<ul>'
              + '<li>Each row inserts one person with an auto-generated <code>people.id</code>.</li>'
              + '<li>These records can be linked later by <code>candidate_overview</code>.</li>'
              + '<li>Matching is case-insensitive on <code>first_name</code> + <code>last_name</code>.</li>'
              + '<li>If a person is missing, <code>candidate_overview</code> will create them automatically.</li>'
              + '</ul>'
              + '<p><b>Avoiding duplicates</b></p>'
              + '<ul>'
              + '<li>Generic importer uses <code>INSERT IGNORE</code>; without a <code>UNIQUE</code> constraint, exact duplicates may slip through.</li>'
              + '</ul>'
              + '<p>Optional checks (Admin → Read-only Query):</p>'
              + '<pre>-- Find potential duplicates (case-insensitive)\nSELECT \n  UPPER(first_name) AS fn, \n  UPPER(last_name) AS ln, \n  COUNT(*) AS c\nFROM people\nGROUP BY UPPER(first_name), UPPER(last_name)\nHAVING COUNT(*) > 1\nORDER BY c DESC;</pre>'
              + '<p>Optional uniqueness enforcement (after cleaning duplicates):</p>'
              + '<pre>ALTER TABLE people \nADD UNIQUE idx_people_name (first_name, last_name);</pre>'
              + '<p><b>Notes</b></p>'
              + '<ul>'
              + '<li><b>Electorate</b> and <b>Party</b> are not part of <code>people</code>.'
              +   '<ul>'
              +     '<li>Import them separately:'
              +       '<ul>'
              +         '<li><code>parties</code>: map <b>Party</b> → <b>name</b></li>'
              +         '<li><code>electorates</code>: map <b>Electorate</b> → <b>name</b></li>'
              +       '</ul>'
              +     '</li>'
              +   '</ul>'
              + '</li>'
              + '<li>Prefill <code>people</code> only if you want specific capitalization or to control prefixes/titles—the <code>candidate_overview</code> importer creates missing people automatically.</li>'
              + '</ul>';
          }

          function htmlOrganizations(){
            return '<p><b>Organizations — use Maintenance buttons</b></p>'
              + '<ul>'
              + '<li>Use <b>Maintenance → Create/Repair Events Tables</b> first to ensure events schema exists (attendees_text, host_person_id, host_organization_id, unique meeting_id). Safe to re‑run.</li>'
              + '<li>Then run <b>Maintenance → Bootstrap Events from Meetings</b> to populate events and attendees from ministerial diaries; this also adds the diary’s minister as an attendee.</li>'
              + '<li>Direct CSV import into <code>organizations</code> is usually unnecessary. If you do import, map organization/company → <b>name</b>, avoid id columns, and leave unrelated columns as <b>Ignore</b>.</li>'
              + '</ul>';
          }
          function htmlStgOverview2023(){
            return '<p><b>stg_overview_2023 — What this is</b></p>'
              + '<ul>'
              + '<li>Temporary <b>staging table</b> used to load the 2023 candidate donations CSV before running the Maintenance backfill.</li>'
              + '<li>The <b>Maintenance → Backfill 2023 original_id</b> action copies <code>candidatedonations2023test_id</code> into <code>candidate_overview.original_id</code> (year 2023) so donations can link.</li>'
              + '</ul>'
              + '<p><b>When and why to use it</b></p>'
              + '<ol>'
              + '<li>Use when preparing the site to link 2023 donations → candidates.</li>'
              + '<li>Load <b>candidate_csv/2023_candidate_donations.csv</b> into <b>stg_overview_2023</b>.</li>'
              + '<li>Then run <b>Maintenance → Backfill 2023 original_id</b> to safely fill <code>candidate_overview.original_id</code> for 2023.</li>'
              + '</ol>'
              + '<p><b>Expected/Helpful columns in stg_overview_2023</b></p>'
              + '<ul>'
              + '<li><code>candidatedonations2023test_id</code> <small>(required to copy into candidate_overview.original_id)</small></li>'
              + '<li><code>candidatename_first</code>, <code>candidatename_last</code></li>'
              + '<li><code>party</code>, <code>electorate</code></li>'
              + '</ul>'
              + '<p><b>How to load the CSV</b></p>'
              + '<ol>'
              + '<li><b>Import CSVs from Server</b> (recommended)'
              +   '<ul>'
              +     '<li>Place the file under <code>data/candidate_csv/2023_candidate_donations.csv</code> on the server.</li>'
              +     '<li>Choose the file and set Target Table to <b>stg_overview_2023</b> (create if missing).</li>'
              +   '</ul>'
              + '</li>'
              + '<li><b>CSV Upload → Table</b> (alternative)'
              +   '<ul>'
              +     '<li>Destination table: <b>stg_overview_2023</b></li>'
              +     '<li>Ensure the columns listed above are present; you may <b>Create column (TEXT)</b> if needed.</li>'
              +     '<li>It is fine to leave unrelated columns as <b>Ignore</b>.</li>'
              +   '</ul>'
              + '</li>'
              + '</ol>'
              + '<p><b>After loading</b></p>'
              + '<ol>'
              + '<li>Go to <b>Maintenance</b> and click <b>Backfill 2023 original_id</b>.</li>'
              + '<li>Review the result message: it shows before/after counts and updated rows per matching step.</li>'
              + '<li>Optional: once backfill finishes, you can <b>Truncate</b> <code>stg_overview_2023</code> to keep things tidy.</li>'
              + '</ol>'
              + '<p><b>Notes & troubleshooting</b></p>'
              + '<ul>'
              + '<li>The backfill is <b>idempotent</b>: it only fills <code>NULL</code> values and can be re-run safely.</li>'
              + '<li>If no rows are updated, confirm staging columns exist (case-insensitive): <code>candidatename_first</code>, <code>candidatename_last</code>, <code>party</code>, <code>electorate</code>, and <code>candidatedonations2023test_id</code>.</li>'
              + '<li>You can re-run after improving the staging file; only <code>NULL</code> <code>original_id</code> values will be filled.</li>'
              + '</ul>';
          }
          function htmlGeneric(name){
            return '<p>Importing into <b>' + name + '</b>.</p>'
              + '<ul>'
              + '<li>Map only columns that exist in the table or use "Create column" to add new TEXT columns.</li>'
              + '<li>Avoid mapping primary key/id columns.</li>'
              + '<li>Use "Ignore" for columns that are informational only.</li>'
              + '</ul>';
          }

          function getChosen(){
            // Re-query each time in case DOM nodes change
            var s = document.querySelector('select[name="dest_table_select"]');
            var c = document.querySelector('input[name="table"]');
            var v = '';

            // 1) custom text input takes precedence
            if (c && typeof c.value === 'string' && c.value.trim() !== '') {
              v = c.value.trim();
            } else if (s) {
              // 2) select value, else fallback to selected option's text (strip " (rowcount)" etc.)
              var raw = (typeof s.value === 'string' && s.value.trim() !== '') ? s.value.trim() : '';
              if (!raw) {
                var opt = s.options && s.selectedIndex >= 0 ? s.options[s.selectedIndex] : null;
                var txt = opt && opt.textContent ? opt.textContent.trim() : '';
                var m = txt.match(/^[A-Za-z0-9_.]+/);
                if (m) raw = m[0];
              }
              v = raw;
            }

            // 3) server-provided initial table (when returning from mapping step)
            if (!v && initialTable) v = initialTable;

            return (v || '').toLowerCase();
          }

          function render(){
            if (!contentEl) return;
            var chosen = getChosen();
            if (subtitleEl) {
              subtitleEl.innerHTML = '';
              subtitleEl.append('Mapping instructions for ', (function(){
                var b = document.createElement('b');
                b.textContent = chosen || 'none selected';
                return b;
              })());
            }
            if (!chosen) { contentEl.innerHTML = htmlDefault(); return; }
            if (chosen.indexOf('donation') !== -1) { contentEl.innerHTML = htmlDonations(); return; }
            if (chosen.indexOf('meetings') !== -1) { contentEl.innerHTML = htmlMeetings(); return; }
            if (chosen.indexOf('people') !== -1) { contentEl.innerHTML = htmlPeople(); return; }
            if (chosen.indexOf('candidate_overview') !== -1) { contentEl.innerHTML = htmlCandidateOverview(); return; }
            if (chosen.indexOf('stg_overview_2023') !== -1) { contentEl.innerHTML = htmlStgOverview2023(); return; }
            if (chosen.indexOf('organization') !== -1) { contentEl.innerHTML = htmlOrganizations(); return; }
            contentEl.innerHTML = htmlGeneric(chosen);
          }

          if (sel) { 
            sel.addEventListener('change', render); 
            sel.addEventListener('input', render); 
            sel.addEventListener('click', render);
          }
          if (custom) custom.addEventListener('input', render);

          // Defensive: also listen at document level in case events are missed/rebound
          document.addEventListener('change', function(e){
            var t = e.target;
            if (t && t.matches && (t.matches('select[name="dest_table_select"]') || t.matches('input[name="table"]'))) {
              render();
            }
          }, true);
          document.addEventListener('input', function(e){
            var t = e.target;
            if (t && t.matches && (t.matches('select[name="dest_table_select"]') || t.matches('input[name="table"]'))) {
              render();
            }
          }, true);

          // Fallback: poll for selection changes (handles browser/UI quirks)
          var __lastChosen = getChosen();
          setInterval(function(){
            var now = getChosen();
            if (now !== __lastChosen) {
              __lastChosen = now;
              render();
            }
          }, 300);

          // Kick once after DOM settles
          setTimeout(render, 0);
        })();
        </script>
<?php
}
