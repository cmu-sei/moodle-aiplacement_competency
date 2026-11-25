# AI Placement Plugin for Moodle Competencies

This is an **AI Placement plugin for Moodle** that helps teachers and course designers classify an activity’s description against a selected Competency Framework and then the user has the option to apply the suggested competencies to the course and the activity.

It adds a **“Classify Text”** button to supported course/activity editing pages. When clicked, it opens a side drawer workflow that lets you:
1) Select a competency framework.
2) Select one or more top-level “levels” (taxonomy buckets) within that framework.
3) Run AI classification on the activity's description.
4) Review the suggested competencies.
5) Choose which ones to apply to the course and link to the activity

> Designed to work with Moodle’s AI subsystem and any configured provider that supports the **Generate text** action.

---

## Key features

- **Framework + level constrained classification** to reduce irrelevant suggestions.
- **Structured AI output** (JSON): the model returns competency **idnumbers** with a one line **reason** quoting the content. Anything that is not in the selected framework and levels is dropped on the server, and the reasons are shown in the drawer.
- **Reads lecture content**: WebVTT transcripts and chapter files (`.vtt`, `.trn.vtt`, `.chp.vtt`), text and HTML files, and PDFs (when `pdftotext` is configured). A lecture **label** with an embedded video is read through the transcript file activities next to it in its section.
- **Reads all of it**: long content is sent in sections and the answers merged, rather than cut at the first 20,000 characters.
- **Short candidate lists**: frameworks with hundreds of statements (DCWF, NICE) are narrowed by keyword match to the closest 400 before the model chooses. A framework whose selected levels hold at most that many entries, as ATT&CK and D3FEND usually do, is sent whole.
- **Fewer, better tags**: tags only what the activity teaches, capped per classification (default 4 in all, and 2 from any one category when work roles are used), with ATT&CK and D3FEND only where the content really explains the technique.
- **Optional verification pass**: a checkbox in the drawer has the model review its own tags and remove weak ones.
- **Warnings**: content that does not look like its activity title (a video carrying another lecture's transcript) is flagged before you apply.
- **Refusals are reported as refusals**: when the model declines on safety grounds the drawer says so. Solution guides and walkthroughs are never sent.
- **One-click apply**:
  - Adds selected competencies to the **course**
  - Links them to the **activity module** when editing an existing activity
- **Safe-by-default behavior**:
  - If there’s no configured provider or the placement/action is disabled, the UI won’t appear.
  - If the activity is new (not yet saved), the plugin shows a notice that classification will be available after saving.

---

## Requirements
- Moodle 5.x or above.
- An AI Provider configured in Moodle with **Generate text** enabled for this placement
- Competency frameworks configured in Moodle (Site administration → Competencies)

> This plugin is commonly tested with providers like Ollama and AWS Bedrock, but it is provider-agnostic as long as Moodle’s AI provider supports `generate_text`.

---

## Installation

1. Copy the plugin into your Moodle installation:
   - `ai/placement/competency`
2. Visit **Site administration → Notifications** to complete installation.
3. Ensure the placement is enabled:
   - Site administration → AI → Placements (or equivalent AI settings page)
4. Ensure at least one AI provider is configured and enabled for **Generate text**.

---

## How it works

### Classification workflow
1. The user clicks **Classify Text**, chooses a framework (or gets the default one) and ticks levels: the framework's top level groups, such as Tasks, Knowledge and Skills, or a group of work roles.
2. The drawer calls `aiplacement_competency_classify_start`, which gathers the activity's content, splits it into sections of about 12,000 characters and plans the steps. Nothing is sent to the model yet.
3. The drawer then calls `aiplacement_competency_classify_step` once per step, one model request each, and shows what is happening ("Section 2 of 3: choosing the work roles") with a progress bar. **Cancel** stops between steps, and no single web request outlasts one model call.
4. For each section:
   - **A framework with work roles** (DCWF, NICE), when only categories of statements are ticked: the model first chooses up to 4 work roles the section prepares learners for, then chooses tags from those roles' statements in the ticked categories, which are usually few enough to send whole, together with the 150 closest keyword matches from the ticked categories, so a role it did not choose can still be offered. A section for which no role is usable is matched against the closest keyword matches alone, a list about a third the length of the keyword shortlist, and the result says so.
   - **Otherwise**, a single request chooses from the keyword shortlist (see *Candidates shown to the model*).
5. Tags are merged across sections, ranked by how many sections back them, and cut to the caps: when roles were used, the most from any one category and the most in all, whichever is reached first; otherwise the most in all. If Verify is ticked, one more request double-checks them.
6. The UI renders results, the reason for each grouped by category, the roles considered, and any warnings, and offers **Back**, **Regenerate**, **Copy**, and **Apply**.

`aiplacement_competency_classify_text` still runs a whole classification in one call, within the time budget, for other callers.

### Content sources
Each activity type contributes the text its author wrote. New in this version: **label** (captions attached to it, plus the transcript and chapter file activities that follow it in the same section, up to the next label) and **file** (`.vtt` transcripts and chapter lists, `.txt`, `.md`, `.html`, `.csv`, and `.pdf` when `pdftotext` is configured). Files and headings that look like solution guides (`solution`, `walkthrough`, `answer key`, `write-up`) are skipped.

### Settings
Site administration → AI → Placements → Competency:

| Setting | Default | Meaning |
| --- | --- | --- |
| Default framework | empty | The idnumber or shortname of the framework the Classify drawer pre-selects, such as `DCWF-5.1`. An exact match, case ignored, not a prefix. The field says which framework it picks, or that it matches none. Builders can still choose another. |
| Most work roles chosen per section | 4 | For a framework with work roles, how many the model may choose before choosing tags. |
| Keyword matches beside the roles' statements | 150 | With work roles, the chosen roles' statements are sent with this many of the closest keyword matches from the ticked categories. 0 sends the roles' statements alone. |
| Most sections read | 6 | Long content is read in sections of about 12,000 characters, each a model request; more are sampled evenly. |
| Time budget (seconds) | 40 | No further section or verification request starts once this much time has passed plus the longest request so far, to stay inside the web server's 60 second limit. |
| Most tags per framework | 4 | The most competencies one classification returns. Reviewers kept about two per framework, so this leaves room for a dense lecture. |
| Most tags per category | 2 | With work roles, the most from any one category, together with the total, whichever is reached first. |
| Candidates shown to the model | 400 | Frameworks with more competencies than this are narrowed to the closest keyword matches. A site that saved this page under the old default of 80 keeps 80, as it trades cost for reach; raise it to 400 to reach more of a large framework. |
| Offer a verification pass | on | Adds the verify checkbox to the drawer. |
| Tag a lecture's transcript with its video | on | Applying a competency to a label also applies it to the transcript file activities beside it. |
| PDF conversion time limit (seconds) | 20 | pdftotext is stopped after this long on one file, which is then reported as unreadable. |
| Path to pdftotext | empty | Program from poppler-utils; PDFs are skipped when empty. |

The verification pass uses the same AI provider as classification: Moodle's AI subsystem picks the provider and model per action, not per request, so a different model for the review means configuring a second provider for **Generate text**.

### Applying competencies
When the user clicks **Apply**:
- The plugin opens a modal with checkboxes for the suggested competencies.
- It matches the suggestions back to real competencies in the selected framework (by idnumber/shortname/heuristics).
- It adds them to the course. The server also adds the course link before the activity link, so an activity never stops partway because the course did not list a competency yet.
- If editing an existing activity, it also links them to the module. On a lecture label, the transcript file activities beside it get the same tags.

After applying, the page reloads and shows success/warning/error notices.

---

## AI output format (expected)

The instruction asks the provider to return **JSON only**. Each candidate is offered as `[idnumber] CODE - Name`:

```json
{
  "framework": { "shortname": "FRAMEWORK-SHORTNAME" },
  "levels": ["Level A", "Level B"],
  "matches_title": true,
  "competencies": [
    { "idnumber": "T1586", "reason": "Short sentence quoting the content." }
  ]
}
```

Plain strings (an idnumber or `"CODE - Name"`) are still accepted. `matches_title: false` raises the content warning.

## License

AI Placement Plugin for Moodle Competencies

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS" BASIS.
CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER INCLUDING, BUT NOT LIMITED TO,
WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM USE OF THE MATERIAL. CARNEGIE MELLON UNIVERSITY
DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.

Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact permission@sei.cmu.edu for full terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution. Please see Copyright notice for non-US Government use and distribution.

This Software includes and/or makes use of Third-Party Software each subject to its own license.

DM26-0017