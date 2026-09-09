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

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS" BASIS.
CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER INCLUDING, BUT NOT LIMITED TO,
WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM USE OF THE MATERIAL. CARNEGIE MELLON UNIVERSITY
DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.

Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact permission@sei.cmu.edu for full terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution. Please see Copyright notice for non-US Government use and distribution.

This Software includes and/or makes use of Third-Party Software each subject to its own license.

DM26-0017
*/

declare(strict_types=1);

namespace aiplacement_competency;

use aiplacement_competency\local\content\book_source;
use aiplacement_competency\local\content\lesson_source;
use aiplacement_competency\local\content\resolver;
use aiplacement_competency\local\utils;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the per-module content resolver.
 *
 * The resolver is the single authority on what an activity contributes to a
 * classification request, so these tests cover both how a source is found and
 * what the two callers (the request itself, and the check that decides whether
 * to offer the Classify button) get back from it.
 *
 * @package    aiplacement_competency
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(resolver::class)]
#[CoversClass(book_source::class)]
#[CoversClass(lesson_source::class)]
final class contentresolver_test extends \advanced_testcase {
    /**
     * Reset the database between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Some module generators save editor files, which no guest may do.
        $this->setAdminUser();
    }

    /**
     * Empties an activity's intro.
     *
     * The module generators refuse an empty intro and substitute one of their
     * own, so an activity that has none has to be made after the fact.
     *
     * @param \stdClass $cm The course module whose intro to clear.
     */
    private function clear_intro(\stdClass $cm): void {
        global $DB;

        $DB->set_field($cm->modname, 'intro', '', ['id' => $cm->instance]);
    }

    /**
     * Creates a book and returns what the resolver needs to read it.
     *
     * @param array $record Fields to override on the book instance.
     * @return array [\stdClass $book, \stdClass $cm, \context_module $context]
     */
    private function create_book(array $record = []): array {
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', $record + [
            'course' => $course->id,
            'intro' => '<p>BOOKINTRO</p>',
            'introformat' => FORMAT_HTML,
        ]);

        return [
            $book,
            get_coursemodule_from_id('book', $book->cmid, 0, false, MUST_EXIST),
            \context_module::instance($book->cmid),
        ];
    }

    /**
     * Adds a chapter to a book.
     *
     * @param \stdClass $book The book to add to.
     * @param array $record Fields to override on the chapter.
     * @return \stdClass The chapter record.
     */
    private function add_chapter(\stdClass $book, array $record): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_book')->create_chapter(
            (object)($record + ['bookid' => $book->id])
        );
    }

    /**
     * Creates a lesson and returns what the resolver needs to read it.
     *
     * @param array $record Fields to override on the lesson instance.
     * @return array [\stdClass $lesson, \stdClass $cm, \context_module $context]
     */
    private function create_lesson(array $record = []): array {
        $course = $this->getDataGenerator()->create_course();
        $lesson = $this->getDataGenerator()->create_module('lesson', $record + [
            'course' => $course->id,
            'intro' => '<p>LESSONINTRO</p>',
            'introformat' => FORMAT_HTML,
        ]);

        return [
            $lesson,
            get_coursemodule_from_id('lesson', $lesson->cmid, 0, false, MUST_EXIST),
            \context_module::instance($lesson->cmid),
        ];
    }

    /**
     * Adds a content page to a lesson, at the front of the page chain.
     *
     * @param \stdClass $lesson The lesson to add to.
     * @param string $title The page title.
     * @param string $contents The page contents, as HTML.
     * @return \stdClass The page record.
     */
    private function add_lesson_page(\stdClass $lesson, string $title, string $contents): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_lesson')->create_content($lesson, [
            'title' => $title,
            'contents_editor' => ['text' => $contents, 'format' => FORMAT_HTML, 'itemid' => 0],
            // Every page is inserted at the beginning, so the chain runs opposite to id order.
            'pageid' => 0,
        ]);
    }

    /**
     * A source is found from the module name alone.
     */
    public function test_get_source_is_found_by_module_name(): void {
        [, $cm, $context] = $this->create_book();

        $this->assertInstanceOf(book_source::class, resolver::get_source($cm, $context));
    }

    /**
     * A module with no source of its own resolves to none.
     */
    public function test_get_source_is_null_for_a_module_with_no_source(): void {
        $course = $this->getDataGenerator()->create_course();
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('label', $label->cmid, 0, false, MUST_EXIST);

        $this->assertNull(resolver::get_source($cm, \context_module::instance($label->cmid)));
        $this->assertSame('', resolver::get_content($cm, \context_module::instance($label->cmid)));
    }

    /**
     * An activity with neither an intro nor source content has nothing to classify.
     */
    public function test_has_content_is_false_without_an_intro_or_content(): void {
        [, $cm, $context] = $this->create_book();
        $this->clear_intro($cm);

        $this->assertFalse(resolver::has_content($cm, $context));
        $this->assertFalse(utils::has_module_content((int)$cm->id));
    }

    /**
     * Content with no intro still counts, which is what the button gate got wrong.
     */
    public function test_has_content_is_true_for_content_with_no_intro(): void {
        [$book, $cm, $context] = $this->create_book();
        $this->clear_intro($cm);
        $this->add_chapter($book, ['title' => 'Only chapter', 'content' => '<p>CHAPTERBODY</p>']);

        $this->assertTrue(resolver::has_content($cm, $context));
        $this->assertTrue(utils::has_module_content((int)$cm->id));
    }

    /**
     * An intro alone counts, even for a module with no source.
     */
    public function test_has_content_is_true_for_an_intro_alone(): void {
        [, $cm, $context] = $this->create_book();

        $this->assertTrue(resolver::has_content($cm, $context));
    }

    /**
     * A caller that sends no prompt text gets the saved intro.
     *
     * This is every caller that is not sitting on the activity settings form.
     */
    public function test_augment_prompt_uses_the_saved_intro_when_the_caller_sends_none(): void {
        [$book, $cm, $context] = $this->create_book();
        $this->add_chapter($book, ['title' => 'Only chapter', 'content' => '<p>CHAPTERBODY</p>']);

        $prompt = resolver::augment_prompt('', $cm, $context);

        $this->assertStringContainsString('BOOKINTRO', $prompt);
        $this->assertStringContainsString('CHAPTERBODY', $prompt);
    }

    /**
     * Prompt text the caller sent is kept as sent, unsaved edits included.
     */
    public function test_augment_prompt_keeps_the_prompt_text_as_sent(): void {
        [, $cm, $context] = $this->create_book();

        $prompt = resolver::augment_prompt('UNSAVEDINTRO', $cm, $context);

        $this->assertStringContainsString('UNSAVEDINTRO', $prompt);
        $this->assertStringNotContainsString('BOOKINTRO', $prompt);
    }

    /**
     * Content the caller already sent is not appended a second time.
     */
    public function test_augment_prompt_does_not_repeat_content_the_caller_already_sent(): void {
        [$book, $cm, $context] = $this->create_book();
        $this->add_chapter($book, ['title' => 'Only chapter', 'content' => '<p>CHAPTERBODY</p>']);

        // Stand in for the client scraping the same text off the settings form.
        $scraped = resolver::get_content($cm, $context);
        $this->assertNotSame('', $scraped);

        $prompt = resolver::augment_prompt("UNSAVEDINTRO\n\n" . $scraped, $cm, $context);

        $this->assertSame(1, substr_count($prompt, 'CHAPTERBODY'));
    }

    /**
     * Content longer than the prompt budget is cut, and says that it was.
     */
    public function test_get_content_is_truncated_to_the_prompt_budget(): void {
        [$book, $cm, $context] = $this->create_book();
        $this->add_chapter($book, [
            'title' => 'Long chapter',
            'content' => str_repeat('overlong ', (int)(resolver::MAX_CONTENT_LENGTH / 4)),
        ]);

        $marker = "\n\n[content truncated]";
        $content = resolver::get_content($cm, $context);

        $this->assertStringEndsWith($marker, $content);
        $this->assertLessThanOrEqual(
            resolver::MAX_CONTENT_LENGTH + \core_text::strlen($marker),
            \core_text::strlen($content)
        );
    }

    /**
     * A book contributes its visible chapters, in the order it presents them.
     */
    public function test_book_content_is_visible_chapters_in_page_order(): void {
        [$book, $cm, $context] = $this->create_book();

        // Created first but pushed to page two, so id order and page order differ.
        $this->add_chapter($book, ['title' => 'Second chapter', 'content' => '<p>SECONDBODY</p>', 'pagenum' => 1]);
        $this->add_chapter($book, ['title' => 'First chapter', 'content' => '<p>FIRSTBODY</p>', 'pagenum' => 1]);
        $this->add_chapter($book, [
            'title' => 'A subchapter',
            'content' => '<p>SUBBODY</p>',
            'pagenum' => 3,
            'subchapter' => 1,
        ]);
        $this->add_chapter($book, [
            'title' => 'Hidden chapter',
            'content' => '<p>HIDDENBODY</p>',
            'pagenum' => 4,
            'hidden' => 1,
        ]);

        $content = resolver::get_content($cm, $context);

        $this->assertStringContainsString('Chapter 1 (First chapter): FIRSTBODY', $content);
        $this->assertStringContainsString('Chapter 2 (Second chapter): SECONDBODY', $content);
        // Subchapters are labelled as sections, so the model is not told they are top level.
        $this->assertStringContainsString('Section 3 (A subchapter): SUBBODY', $content);
        $this->assertStringNotContainsString('HIDDENBODY', $content);
        $this->assertLessThan(
            strpos($content, 'SECONDBODY'),
            strpos($content, 'FIRSTBODY'),
            'Chapters should be ordered by pagenum, not by id.'
        );
    }

    /**
     * A lesson contributes its pages in chain order, which is not id order.
     */
    public function test_lesson_content_follows_the_page_chain(): void {
        [$lesson, $cm, $context] = $this->create_lesson();

        // Each page is inserted at the front, so the chain ends up reversed.
        $this->add_lesson_page($lesson, 'Last page', '<p>LASTBODY</p>');
        $this->add_lesson_page($lesson, 'Middle page', '<p>MIDDLEBODY</p>');
        $this->add_lesson_page($lesson, 'First page', '<p>FIRSTBODY</p>');

        $content = resolver::get_content($cm, $context);

        $this->assertStringContainsString('Page 1 (First page): FIRSTBODY', $content);
        $this->assertStringContainsString('Page 2 (Middle page): MIDDLEBODY', $content);
        $this->assertStringContainsString('Page 3 (Last page): LASTBODY', $content);
    }

    /**
     * A page left off the chain is still sent, rather than silently dropped.
     */
    public function test_lesson_content_includes_pages_left_off_the_chain(): void {
        global $DB;

        [$lesson, $cm, $context] = $this->create_lesson();
        $this->add_lesson_page($lesson, 'Chained page', '<p>CHAINEDBODY</p>');

        // A page whose predecessor does not exist is unreachable by a normal walk.
        $DB->insert_record('lesson_pages', (object)[
            'lessonid' => $lesson->id,
            'prevpageid' => -1,
            'nextpageid' => 0,
            'qtype' => 20,
            'qoption' => 0,
            'layout' => 1,
            'display' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'title' => 'Orphan page',
            'contents' => '<p>ORPHANBODY</p>',
            'contentsformat' => FORMAT_HTML,
        ]);

        $content = resolver::get_content($cm, $context);

        $this->assertStringContainsString('CHAINEDBODY', $content);
        $this->assertStringContainsString('ORPHANBODY', $content);
        $this->assertLessThan(
            strpos($content, 'ORPHANBODY'),
            strpos($content, 'CHAINEDBODY'),
            'Unreachable pages should follow the ones in chain order.'
        );
    }

    /**
     * A lesson with no pages contributes nothing beyond its intro.
     */
    public function test_lesson_with_no_pages_has_no_content(): void {
        [, $cm, $context] = $this->create_lesson();

        $this->assertSame('', resolver::get_content($cm, $context));
        $this->assertTrue(resolver::has_content($cm, $context));
    }
}
