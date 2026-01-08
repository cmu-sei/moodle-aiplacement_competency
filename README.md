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
- **Structured AI output** (JSON) with competencies listed as `"CODE - Name"` or `"Name"`.
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
6. The UI renders results and offers **Regenerate**, **Copy**, and **Apply** actions.

### Applying competencies
When the user clicks **Apply**:
- The plugin opens a modal with checkboxes for the suggested competencies.
- It matches the suggestions back to real competencies in the selected framework (by idnumber/shortname/heuristics).
- It adds them to the course.
- If editing an existing activity, it also links them to the module.

After applying, the page reloads and shows success/warning/error notices.

---

## AI output format (expected)

The instruction asks the provider to return **JSON only**:

```json
{
  "framework": { "shortname": "FRAMEWORK-SHORTNAME" },
  "levels": ["Level A", "Level B"],
  "competencies": [
    "CODE - Name",
    "CODE2 - Name2"
  ]
}
```

## License

AI Placement Plugin for Moodle Competencies

Copyright 2025 Carnegie Mellon University.

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS" BASIS.
CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER INCLUDING, BUT NOT LIMITED TO,
WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM USE OF THE MATERIAL.
CARNEGIE MELLON UNIVERSITY DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.
Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact permission@sei.cmu.edu for full
terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution.
Please see Copyright notice for non-US Government use and distribution.

This Software includes and/or makes use of Third-Party Software each subject to its own license.

DM##-####
