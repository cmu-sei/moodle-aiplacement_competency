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
- **Short candidate lists**: frameworks with hundreds of statements (DCWF, NICE) are narrowed by keyword match to the closest 80 before the model chooses.
- **Fewer, better tags**: tags only what the activity teaches, capped per classification (default 3), with ATT&CK and D3FEND only where the content really explains the technique.
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
1. The user clicks **Classify Text**
2. The plugin reads the activity description (intro editor content)
3. The user selects a competency framework and levels
4. The browser calls the AJAX webservice:
   - `aiplacement_competency_classify_text`
5. Server-side, the placement runs `core_ai\aiactions\generate_text` with an instruction prompt that asks for **JSON-only** output.
   Content is read from the activity itself (see Content sources), split into sections of about 12,000 characters, and each section is classified against the closest candidates in the framework. Sections' answers are merged, ranked by how many sections back each tag, and trimmed to the cap.
6. The UI renders results, the reason for each, and any warnings, and offers **Regenerate**, **Copy**, and **Apply** actions.

### Content sources
Each activity type contributes the text its author wrote. New in this version: **label** (captions attached to it, plus the transcript and chapter file activities that follow it in the same section, up to the next label) and **file** (`.vtt` transcripts and chapter lists, `.txt`, `.md`, `.html`, `.csv`, and `.pdf` when `pdftotext` is configured). Files and headings that look like solution guides (`solution`, `walkthrough`, `answer key`, `write-up`) are skipped.

### Settings
Site administration → AI → Placements → Competency:

| Setting | Default | Meaning |
| --- | --- | --- |
| Most sections read | 6 | Long content is read in sections of about 12,000 characters, each a model request; more are sampled evenly. |
| Time budget (seconds) | 40 | No further section or verification request starts after this, to stay inside the web server's 60 second limit. |
| Most tags per framework | 3 | The most competencies one classification returns. |
| Candidates shown to the model | 80 | Frameworks with more competencies than this are narrowed to the closest keyword matches. |
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