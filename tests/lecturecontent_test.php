<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/*
AI Placement Plugin for Moodle Competencies

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS"
BASIS. CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER
INCLUDING, BUT NOT LIMITED TO, WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM
USE OF THE MATERIAL. CARNEGIE MELLON UNIVERSITY DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM
PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.

Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact
permission@sei.cmu.edu for full terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution. Please see
Copyright notice for non-US Government use and distribution.

This Software includes and/or makes use of Third-Party Software each subject to its own license.

DM26-0017
*/

declare(strict_types=1);

namespace aiplacement_competency;

use aiplacement_competency\external\add_cm_competency;
use aiplacement_competency\local\content\filetext;
use aiplacement_competency\local\content\label_source;
use aiplacement_competency\local\content\resolver;
use aiplacement_competency\local\content\resource_source;
use aiplacement_competency\local\content\transcripts;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for reading lecture transcripts, chapter files and the labels that carry the video.
 *
 * @package    aiplacement_competency
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(filetext::class)]
#[CoversClass(resource_source::class)]
#[CoversClass(label_source::class)]
#[CoversClass(transcripts::class)]
#[CoversClass(add_cm_competency::class)]
final class lecturecontent_test extends \advanced_testcase {
    /** A WebVTT transcript with rolling duplicate captions, a note and styling tags. */
    private const TRANSCRIPT = "WEBVTT\n\nNOTE made by a machine\n\n1\n00:00:01.000 --> 00:00:03.000\n" .
        "Welcome to <b>risk assessment</b>.\n\n2\n00:00:03.000 --> 00:00:05.000\nWelcome to risk assessment.\n\n" .
        "3\n00:00:05.000 --> 00:00:09.000\nFirst we list the assets.\n";

    /** The intro of a lecture label: a video and no words. */
    private const VIDEO = '<video src="lecture.mp4"></video>';

    /**
     * A one cue WebVTT file.
     *
     * @param string $words What is said.
     * @return string The file content.
     */
    private static function cue(string $words): string {
        return "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\n{$words}";
    }

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Adds a file activity holding the given files.
     *
     * @param \stdClass $course The course.
     * @param array $files File name => content.
     * @return \stdClass The activity record, with cmid.
     */
    private function create_file_activity(\stdClass $course, array $files): \stdClass {
        global $DB;

        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id, 'intro' => '']);
        $context = \context_module::instance($resource->cmid);
        // The generator gives every activity an intro and a file of its own.
        $DB->set_field('resource', 'intro', '', ['id' => $resource->id]);
        $fs = get_file_storage();
        foreach ($files as $name => $content) {
            $fs->delete_area_files($context->id, 'mod_resource', 'content');
        }
        foreach ($files as $name => $content) {
            $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'mod_resource',
                'filearea' => 'content',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $name,
            ], $content);
        }

        return $resource;
    }

    public function test_parse_vtt_leaves_the_spoken_words_once(): void {
        $this->assertSame(
            'Welcome to risk assessment. First we list the assets.',
            filetext::parse_vtt(self::TRANSCRIPT)
        );
    }

    public function test_parse_vtt_lists_the_titles_of_a_chapter_file(): void {
        $chapters = "WEBVTT\n\n00:00:00.000 --> 00:01:00.000\nIntroduction\n\n00:01:00.000 --> 00:05:00.000\nAssets\n";

        $this->assertSame('Chapters: Introduction; Assets', filetext::parse_vtt($chapters, true));
        $this->assertTrue(filetext::is_chapter_file('lecture.chp.vtt'));
        $this->assertFalse(filetext::is_chapter_file('lecture.trn.vtt'));
    }

    public function test_a_file_activity_gives_its_transcript_and_chapters(): void {
        $course = $this->getDataGenerator()->create_course();
        $resource = $this->create_file_activity($course, [
            'lecture.trn.vtt' => self::TRANSCRIPT,
            'lecture.chp.vtt' => "WEBVTT\n\n00:00:00.000 --> 00:01:00.000\nAssets\n",
        ]);
        $cm = get_coursemodule_from_id('resource', $resource->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($resource->cmid);

        $content = resolver::get_content($cm, $context);

        $this->assertStringContainsString('First we list the assets.', $content);
        $this->assertStringContainsString('Chapters: Assets', $content);
        $this->assertTrue(resolver::has_content($cm, $context));
    }

    public function test_a_file_activity_of_images_has_no_content(): void {
        $course = $this->getDataGenerator()->create_course();
        $resource = $this->create_file_activity($course, ['photo.png' => 'not text']);
        $cm = get_coursemodule_from_id('resource', $resource->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($resource->cmid);

        $this->assertFalse(resolver::has_content($cm, $context));
        $this->assertSame('', resolver::get_content($cm, $context));
    }

    public function test_long_transcripts_are_not_cut_short(): void {
        $course = $this->getDataGenerator()->create_course();
        $long = "WEBVTT\n\n";
        for ($i = 0; $i < 3000; $i++) {
            $long .= sprintf("00:00:%02d.000 --> 00:00:%02d.500\nSentence number %d about assets.\n\n", $i % 60, $i % 60, $i);
        }
        $resource = $this->create_file_activity($course, ['long.vtt' => $long]);
        $cm = get_coursemodule_from_id('resource', $resource->cmid, 0, false, MUST_EXIST);

        $content = resolver::get_content($cm, \context_module::instance($resource->cmid));

        $this->assertGreaterThan(20000, \core_text::strlen($content));
        $this->assertStringContainsString('Sentence number 2999', $content);
    }

    public function test_solution_guides_are_not_sent(): void {
        $course = $this->getDataGenerator()->create_course();
        $resource = $this->create_file_activity($course, [
            'lab-walkthrough.txt' => 'SECRETEXPLOIT steps',
            'lab-brief.txt' => 'Learners scan a network.',
        ]);
        $cm = get_coursemodule_from_id('resource', $resource->cmid, 0, false, MUST_EXIST);

        $content = resolver::get_content($cm, \context_module::instance($resource->cmid));

        $this->assertStringContainsString('Learners scan a network.', $content);
        $this->assertStringNotContainsString('SECRETEXPLOIT', $content);
        $this->assertSame("Intro: kept\n\nBody", resolver::drop_solutions("Intro: kept\n\nSolution guide: dropped\n\nBody", true));
    }

    /**
     * A course with two lectures in a section: label, transcript, label, transcript.
     *
     * @return array [course, label1, transcript1, label2, transcript2] as cm records.
     */
    private function create_two_lectures(): array {
        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $generator = $this->getDataGenerator();
        $label1 = $generator->create_module('label', ['course' => $course->id, 'intro' => self::VIDEO]);
        $trn1 = $this->create_file_activity($course, ['one.trn.vtt' => self::cue('FIRSTLECTURE words')]);
        $label2 = $generator->create_module('label', ['course' => $course->id, 'intro' => self::VIDEO]);
        $trn2 = $this->create_file_activity($course, ['two.trn.vtt' => self::cue('SECONDLECTURE words')]);

        return [$course, $label1, $trn1, $label2, $trn2];
    }

    public function test_a_label_reads_the_transcript_next_to_its_video(): void {
        [, $label1, , $label2] = $this->create_two_lectures();

        $expected = [[$label1, 'FIRSTLECTURE', 'SECONDLECTURE'], [$label2, 'SECONDLECTURE', 'FIRSTLECTURE']];
        foreach ($expected as [$label, $has, $hasnot]) {
            $cm = get_coursemodule_from_id('label', $label->cmid, 0, false, MUST_EXIST);
            $context = \context_module::instance($label->cmid);
            $content = resolver::get_content($cm, $context);

            $this->assertStringContainsString($has, $content);
            $this->assertStringNotContainsString($hasnot, $content);
            $this->assertTrue(resolver::has_content($cm, $context));
        }
    }

    public function test_a_label_without_a_transcript_has_no_content(): void {
        $course = $this->getDataGenerator()->create_course();
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id, 'intro' => self::VIDEO]);
        $cm = get_coursemodule_from_id('label', $label->cmid, 0, false, MUST_EXIST);

        $this->assertFalse(resolver::has_content($cm, \context_module::instance($label->cmid)));
    }

    public function test_tags_on_a_label_also_go_to_its_transcript_and_the_course(): void {
        global $DB;
        set_config('enableccompetency', 1);
        [$course, $label1, $trn1, , $trn2] = $this->create_two_lectures();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency(['competencyframeworkid' => $framework->get('id')]);

        // The course does not list the competency yet: the server adds it.
        $this->assertFalse($DB->record_exists('competency_coursecomp', ['courseid' => $course->id]));

        add_cm_competency::execute($label1->cmid, $competency->get('id'));

        $this->assertTrue($DB->record_exists('competency_coursecomp', ['courseid' => $course->id]));
        $this->assertTrue($DB->record_exists('competency_modulecomp', ['cmid' => $label1->cmid]));
        $this->assertTrue($DB->record_exists('competency_modulecomp', ['cmid' => $trn1->cmid]));
        $this->assertFalse($DB->record_exists('competency_modulecomp', ['cmid' => $trn2->cmid]));
    }

    public function test_a_label_does_not_read_a_transcript_the_user_cannot_see(): void {
        global $DB;
        [$course, $label1, $trn1] = $this->create_two_lectures();
        $DB->set_field('course_modules', 'visible', 0, ['id' => $trn1->cmid]);
        $DB->set_field('course_modules', 'visibleold', 0, ['id' => $trn1->cmid]);
        rebuild_course_cache((int)$course->id, true);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $cm = get_coursemodule_from_id('label', $label1->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($label1->cmid);

        $this->assertStringNotContainsString('FIRSTLECTURE', resolver::get_content($cm, $context));
        $this->assertFalse(resolver::has_content($cm, $context));
    }

    public function test_a_plain_text_solution_guide_is_dropped_to_the_end_including_its_sub_headings(): void {
        $content = "Learners scan a host.\n\nSolution guide\n\nRun SECRETCOMMAND first.\n\nEnumeration\n\nThen run ANOTHERSECRET.";

        $kept = resolver::drop_solutions($content);

        $this->assertSame('Learners scan a host.', $kept);
    }

    public function test_a_sentence_that_mentions_a_solution_is_not_a_solution_guide(): void {
        $content = "Design a solution for the network below.\n\nStudents write up their findings: in a short report.\n\n" .
            'Each task is worth ten points.';

        $this->assertSame($content, resolver::drop_solutions($content));
    }

    public function test_an_html_solution_section_is_dropped_from_a_single_line_description(): void {
        $html = '<p>Lab tasks: scan the network and list the hosts you find on it, then report them.</p>' .
            '<h3>Solution guide</h3><p>Run SECRETCOMMAND against the host.</p><h4>Enumeration</h4><p>ANOTHERSECRET</p>' .
            '<h3>Grading</h3><p>Ten points per task.</p>';

        $kept = resolver::drop_solutions($html);

        $this->assertStringContainsString('Lab tasks', $kept);
        $this->assertStringNotContainsString('SECRETCOMMAND', $kept);
        $this->assertStringNotContainsString('ANOTHERSECRET', $kept);
        $this->assertStringContainsString('Ten points per task.', $kept);
    }

    public function test_a_file_that_is_not_utf8_is_still_read(): void {
        $course = $this->getDataGenerator()->create_course();
        $latin1 = mb_convert_encoding('Café réseau: analysez le trafic', 'ISO-8859-1', 'UTF-8');
        $utf16 = "\xFF\xFE" . mb_convert_encoding("WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nUTF16WORDS", 'UTF-16LE', 'UTF-8');
        $resource = $this->create_file_activity($course, ['handout.txt' => $latin1, 'caps.vtt' => $utf16]);
        $cm = get_coursemodule_from_id('resource', $resource->cmid, 0, false, MUST_EXIST);

        $content = resolver::get_content($cm, \context_module::instance($resource->cmid));

        $this->assertStringContainsString('Café réseau', $content);
        $this->assertStringContainsString('UTF16WORDS', $content);
    }

    public function test_a_label_does_not_borrow_the_transcript_of_the_lecture_before_it(): void {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator();
        $generator->create_module('label', ['course' => $course->id, 'intro' => self::VIDEO]);
        $this->create_file_activity($course, ['first.trn.vtt' => self::cue('FIRSTLECTURE words')]);
        $second = $generator->create_module('label', ['course' => $course->id, 'intro' => self::VIDEO]);
        $cm = get_coursemodule_from_id('label', $second->cmid, 0, false, MUST_EXIST);

        $this->assertFalse(resolver::has_content($cm, \context_module::instance($second->cmid)));
    }

    public function test_a_pdf_that_cannot_be_read_is_reported_not_silently_skipped(): void {
        // A program that always fails stands in for a PDF with no text layer.
        set_config('pdftotext', '/bin/false', 'aiplacement_competency');
        $course = $this->getDataGenerator()->create_course();
        $resource = $this->create_file_activity($course, ['handout.pdf' => '%PDF-1.4 not really']);
        $cm = get_coursemodule_from_id('resource', $resource->cmid, 0, false, MUST_EXIST);
        filetext::take_omitted();

        $this->assertSame('', resolver::get_content($cm, \context_module::instance($resource->cmid)));
        $this->assertSame(['handout.pdf'], filetext::take_omitted());
        $this->assertSame([], filetext::take_omitted());
    }

    public function test_tags_are_not_written_to_a_transcript_the_user_may_not_manage(): void {
        global $DB;
        set_config('enableccompetency', 1);
        [$course, $label1, $trn1] = $this->create_two_lectures();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency(['competencyframeworkid' => $framework->get('id')]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability(
            'moodle/competency:coursecompetencymanage',
            CAP_PROHIBIT,
            $roleid,
            \context_module::instance($trn1->cmid)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($teacher);

        add_cm_competency::execute($label1->cmid, $competency->get('id'));

        $this->assertTrue($DB->record_exists('competency_modulecomp', ['cmid' => $label1->cmid]));
        $this->assertFalse($DB->record_exists('competency_modulecomp', ['cmid' => $trn1->cmid]));
    }

    public function test_a_walkthrough_that_opens_with_its_label_is_dropped_to_the_end(): void {
        $content = "Intro text about Kerberos.\n\nWalkthrough: Start by running nmap\n\n" .
            "Then run SECRETEXPLOIT\n\nFinally dump hashes";

        $this->assertSame('Intro text about Kerberos.', resolver::drop_solutions($content));
    }

    public function test_headings_that_only_mention_solutions_are_kept(): void {
        $plain = "Overview of the lab\n\nEndpoint Security Solutions\n\nYou will compare EDR products in detail.";
        $html = '<h1>Lab 4: Designing a Network Solution</h1><p>Build a segmented network.</p>' .
            '<h2>Tasks</h2><p>Configure it.</p>';

        $this->assertSame($plain, resolver::drop_solutions($plain));
        $this->assertSame($html, resolver::drop_solutions($html));
    }

    public function test_a_bold_paragraph_used_as_a_solution_heading_is_dropped_to_the_end(): void {
        $html = '<p>Scan the network.</p><p><strong>Walkthrough</strong></p><p>Run SECRETCOMMAND.</p>';

        $this->assertSame('<p>Scan the network.</p>', resolver::drop_solutions($html));
    }

    public function test_a_solution_inside_a_file_is_dropped(): void {
        $course = $this->getDataGenerator()->create_course();
        $resource = $this->create_file_activity($course, [
            'lab.txt' => "Scan the network.\n\nSolution guide\n\nRun SECRETCOMMAND.",
            'lab.html' => '<p>Review the logs.</p><h3>Solution guide</h3><p>Run OTHERSECRET.</p>',
        ]);
        $cm = get_coursemodule_from_id('resource', $resource->cmid, 0, false, MUST_EXIST);

        $content = resolver::get_content($cm, \context_module::instance($resource->cmid));

        $this->assertStringContainsString('Scan the network.', $content);
        $this->assertStringContainsString('Review the logs.', $content);
        $this->assertStringNotContainsString('SECRETCOMMAND', $content);
        $this->assertStringNotContainsString('OTHERSECRET', $content);
    }

    public function test_utf16_without_a_byte_order_mark_is_read_and_an_empty_result_is_reported(): void {
        $course = $this->getDataGenerator()->create_course();
        $utf16 = mb_convert_encoding("WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nNOBOMWORDS", 'UTF-16LE', 'UTF-8');
        $empty = "WEBVTT\n\nNOTE nothing here\n";
        $resource = $this->create_file_activity($course, ['caps.vtt' => $utf16, 'empty.vtt' => $empty]);
        $cm = get_coursemodule_from_id('resource', $resource->cmid, 0, false, MUST_EXIST);
        filetext::take_omitted();

        $content = resolver::get_content($cm, \context_module::instance($resource->cmid));

        $this->assertStringContainsString('NOBOMWORDS', $content);
        $this->assertSame(['empty.vtt'], filetext::take_omitted());
    }

    public function test_a_description_without_blank_lines_is_cut_at_its_solution_heading(): void {
        $this->assertSame(
            "Intro text about Kerberos.",
            resolver::drop_solutions("Intro text about Kerberos.\nSolution guide\nRun nmap then msfconsole.")
        );
        $html = "<p>Intro about Kerberos.</p>\n<p><strong>Walkthrough:</strong> Start by running nmap.</p>\n" .
            '<p>Then exploit it.</p>';
        $this->assertSame('<p>Intro about Kerberos.</p>', resolver::drop_solutions($html));
        $this->assertSame('<p>Intro.</p>', resolver::drop_solutions('<p>Intro.</p><p>Walkthrough: run SECRET</p>'));
    }

    public function test_a_bold_heading_before_a_heading_tag_solution_is_not_hidden_by_it(): void {
        $html = '<p><strong>Walkthrough</strong></p><p>Run SECRETCOMMAND.</p><h2>Answer key</h2><p>flag</p>' .
            '<h2>Next</h2><p>Keep</p>';

        $this->assertSame('', resolver::drop_solutions($html));
    }

    public function test_numbered_and_named_solution_headings_are_recognised(): void {
        $headings = ['Challenge 1 Solution', 'CTF Writeup', 'Lab 3 Walkthrough', 'Solution to Task 2', 'Task 2 Solution'];
        foreach ($headings as $heading) {
            $this->assertSame('Intro', resolver::drop_solutions("Intro\n\n{$heading}\n\nRun the exploit."), $heading);
        }
    }

    public function test_a_chapter_whose_title_mentions_solutions_is_kept(): void {
        $content = "Page 2 (Endpoint Security Solutions): Compare EDR products.\n\nPage 3 (Solution guide): SECRET steps.\n\n" .
            'Page 4 (Next): keep';

        $kept = resolver::drop_solutions($content, true);

        $this->assertStringContainsString('Endpoint Security Solutions', $kept);
        $this->assertStringContainsString('Page 4', $kept);
        $this->assertStringNotContainsString('SECRET', $kept);
    }

    public function test_a_solution_in_a_page_activity_is_dropped(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => '<p>Review the alerts.</p><h3>Solution guide</h3><p>Run PAGESECRET.</p>',
        ]);
        $cm = get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST);

        $content = resolver::get_content($cm, \context_module::instance($page->cmid));

        $this->assertStringContainsString('Review the alerts.', $content);
        $this->assertStringNotContainsString('PAGESECRET', $content);
    }

    public function test_utf16_big_endian_text_starting_with_a_non_ascii_character_is_read(): void {
        $course = $this->getDataGenerator()->create_course();
        $utf16 = mb_convert_encoding("\u{201C}Lecture about network security.\u{201D} BEWORDS", 'UTF-16BE', 'UTF-8');
        $resource = $this->create_file_activity($course, ['note.txt' => $utf16]);
        $cm = get_coursemodule_from_id('resource', $resource->cmid, 0, false, MUST_EXIST);

        $this->assertStringContainsString('BEWORDS', resolver::get_content($cm, \context_module::instance($resource->cmid)));
    }

    public function test_ordinary_lead_ins_that_use_the_word_solution_are_kept(): void {
        $teaching = "<p>Common web flaws.</p><p>Problem: SQL injection.</p><p>Solution: use parameterised queries.</p>" .
            '<p>Problem: stored XSS.</p>';
        $assignment = "Write-up: submit a PDF by Friday.\nKeep me";

        $this->assertSame($teaching, resolver::drop_solutions($teaching));
        $this->assertSame($assignment, resolver::drop_solutions($assignment));
    }

    public function test_an_entity_encoded_heading_is_found_and_stops_at_the_next_section(): void {
        $html = '<p>Intro.</p><h2>Solution&nbsp;guide</h2><p>Run SECRETCOMMAND.</p><h2>Reading</h2><p>Keep me.</p>';

        $this->assertSame('<p>Intro.</p><h2>Reading</h2><p>Keep me.</p>', resolver::drop_solutions($html));
    }

    public function test_a_lesson_about_solutions_is_not_a_solution_file(): void {
        $this->assertFalse(resolver::is_solution_file('Endpoint Security Solutions.txt'));
        $this->assertTrue(resolver::is_solution_file('lab3-solution.pdf'));
        $this->assertTrue(resolver::is_solution_file('ctf_writeup.txt'));
        $this->assertTrue(resolver::is_solution_file('Challenge 1 Solution.pdf'));
        $this->assertTrue(resolver::is_solution_file('lab-walk-through.txt'));
        $guides = ['Lab3_Solution_Guide.pdf', 'SQLi_Challenge_Walkthrough.pdf', 'Module 3 Answer Key.docx',
            'pcap-analysis-writeup.pdf', 'lab3-walk-through.txt', 'Kerberoasting Lab Solution.pdf'];
        foreach ($guides as $name) {
            $this->assertTrue(resolver::is_solution_file($name), $name);
        }
    }

    public function test_a_pdf_that_is_only_a_solution_guide_is_left_out_without_a_warning(): void {
        $stub = sys_get_temp_dir() . '/pdftotext_stub_' . uniqid();
        file_put_contents($stub, "#!/bin/sh\nprintf 'Solution guide\\nRun SECRET.'\n");
        chmod($stub, 0755);
        set_config('pdftotext', $stub, 'aiplacement_competency');
        $course = $this->getDataGenerator()->create_course();
        $resource = $this->create_file_activity($course, ['handout.pdf' => '%PDF-1.4']);
        $cm = get_coursemodule_from_id('resource', $resource->cmid, 0, false, MUST_EXIST);
        filetext::take_omitted();

        $content = resolver::get_content($cm, \context_module::instance($resource->cmid));
        unlink($stub);

        $this->assertStringNotContainsString('SECRET', $content);
        $this->assertSame([], filetext::take_omitted());
    }

    public function test_a_bold_lead_in_with_text_after_it_is_kept_and_a_bare_bold_solution_ends_at_the_next_heading(): void {
        $lead = '<p>Intro.</p><p><strong>Solution:</strong> use parameterised queries.</p><p>Practice it.</p>';
        $this->assertSame($lead, resolver::drop_solutions($lead));

        $bare = '<p>Intro.</p><p><strong>Solution</strong></p><p>Use parameterised queries.</p>' .
            '<p><strong>Problem</strong></p><p>XSS.</p>';
        $this->assertSame('<p>Intro.</p><p><strong>Problem</strong></p><p>XSS.</p>', resolver::drop_solutions($bare));
    }

    public function test_utf16_text_that_starts_with_a_long_non_latin_run_is_read(): void {
        $course = $this->getDataGenerator()->create_course();
        $text = str_repeat("\u{4E2D}", 10001) . ' LONGBEWORDS';
        $resource = $this->create_file_activity($course, ['long.txt' => mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')]);
        $cm = get_coursemodule_from_id('resource', $resource->cmid, 0, false, MUST_EXIST);

        $this->assertStringContainsString('LONGBEWORDS', resolver::get_content($cm, \context_module::instance($resource->cmid)));
    }

    public function test_a_user_who_cannot_manage_the_course_can_still_tag_an_activity_the_course_already_lists(): void {
        global $DB;
        set_config('enableccompetency', 1);
        [$course, $label1] = $this->create_two_lectures();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency(['competencyframeworkid' => $framework->get('id')]);
        $generator->create_course_competency(['courseid' => $course->id, 'competencyid' => $competency->get('id')]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        // May manage on the activity, not on the course.
        assign_capability(
            'moodle/competency:coursecompetencymanage',
            CAP_PREVENT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );
        assign_capability(
            'moodle/competency:coursecompetencymanage',
            CAP_ALLOW,
            $roleid,
            \context_module::instance($label1->cmid)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($teacher);

        $result = add_cm_competency::execute($label1->cmid, $competency->get('id'));

        $this->assertTrue($result);
        $this->assertDebuggingCalledCount(1);
    }

    public function test_write_up_and_cheat_sheet_headings_run_to_the_end_even_past_a_sub_heading(): void {
        foreach (['Write-up', 'Cheat sheet'] as $heading) {
            $html = "<p>Intro.</p><p><strong>{$heading}</strong></p><p>Step one.</p>" .
                '<p><strong>Enumeration</strong></p><p>SECRET.</p>';

            $this->assertSame('<p>Intro.</p>', resolver::drop_solutions($html), $heading);
        }
    }

    public function test_names_of_lessons_about_solutions_are_read_and_answer_files_are_not(): void {
        $this->assertFalse(resolver::is_solution_file('Lecture2-Endpoint-Security-Solutions.trn.vtt'));
        $this->assertFalse(resolver::is_solution_file('Endpoint Security Solutions 2026.txt'));
        foreach (['Problem Set Solutions.pdf', 'Midterm Exam Solutions.pdf', 'Kerberos Homework Solutions.pdf'] as $name) {
            $this->assertTrue(resolver::is_solution_file($name), $name);
        }
    }
}
