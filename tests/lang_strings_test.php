<?php
declare(strict_types=1);

namespace aiplacement_classifyassist;

defined('MOODLE_INTERNAL') || die();

final class lang_strings_test extends \basic_testcase {
    public function test_pluginname_string_exists(): void {
        $component = 'aiplacement_classifyassist';

        $this->assertTrue(get_string_manager()->string_exists('pluginname', $component));

        $s = get_string('pluginname', $component);
        $this->assertIsString($s);
        $this->assertNotSame('[[pluginname]]', $s);
        $this->assertNotSame('', trim($s));
    }
}