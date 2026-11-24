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
        $this->assertSame("Intro: kept\n\nBody", resolver::drop_solutions("Intro: kept\n\nSolution guide: dropped\n\nBody"));
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

    public function test_a_solution_guide_is_dropped_through_its_following_paragraphs(): void {
        $content = "Learners scan a host.\n\nSolution guide\n\nRun SECRETCOMMAND first.\n\nThen run ANOTHERSECRET.\n\n" .
            "Grading\n\nEach task is worth ten points.";

        $kept = resolver::drop_solutions($content);

        $this->assertStringContainsString('Learners scan a host.', $kept);
        $this->assertStringNotContainsString('SECRETCOMMAND', $kept);
        $this->assertStringNotContainsString('ANOTHERSECRET', $kept);
        $this->assertStringContainsString('Each task is worth ten points.', $kept);
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
}
