<?php

defined('MOODLE_INTERNAL') || die();

use aiplacement_classifyassist\local\utils;

class aiplacement_classifyassist_utils_test extends advanced_testcase {

    protected function setUp(): void {
        $this->resetAfterTest();
    }

    public function test_build_instruction_includes_framework_and_levels_and_dedupes(): void {
        $frameworkid = 42;
        $shortname = 'NICE-1.0.0';
        $levels = ['  Analyze  ', 'Analyze', 'Detect', '', null, 123, 'DETECT', '  detect '];

        $str = utils::build_instruction($frameworkid, $shortname, $levels);

        $this->assertStringContainsString($shortname, $str);

        if (preg_match('/levels?\s*:\s*(.+)/i', $str, $m)) {
            $list = $m[1];

            $tokens = array_filter(array_map('trim', explode(',', $list)), fn($s) => $s !== '');

            $norm = array_map(fn($s) => mb_strtolower($s), $tokens);
            $this->assertSame(['analyze', 'detect'], array_values(array_unique($norm)),
                'Levels list should be deduped and normalized (order Analyze, Detect).');
            $this->assertGreaterThanOrEqual(1, substr_count($list, 'Analyze'));
            $this->assertGreaterThanOrEqual(1, max(substr_count($list, 'Detect'), substr_count($list, 'detect')));
        } else {
            $this->assertStringContainsStringIgnoringCase('Analyze', $str);
            $this->assertStringContainsStringIgnoringCase('Detect', $str);
        }
    }

    public function test_extract_classification_from_plain_json_string_payload(): void {
        $payload = json_encode([
            'framework' => ['shortname' => 'NICE-1.0.0'],
            'levels' => ['Analyze', 'Detect'],
            'competencies' => ['T1119 - Recommend vulnerability remediation strategies', '  ', null, 'T1119 - Recommend vulnerability remediation strategies'],
        ]);

        $out = utils::extract_classification(['generatedcontent' => $payload]);

        $this->assertSame('NICE-1.0.0', $out['frameworkshortname']);
        $this->assertEquals(['Analyze', 'Detect'], $out['levels']);
        $this->assertEquals(['T1119 - Recommend vulnerability remediation strategies'], $out['competencies']);
    }

    public function test_extract_classification_from_array_response_nested_response_string(): void {
        $inner = [
            'response' => json_encode([
                'framework' => ['shortname' => 'MITRE D3FEND'],
                'levels' => ['Harden', 'Detect', 'Harden'],
                'competencies' => ['Network Segmentation', '  ', 'Email Content Filtering'],
            ]),
        ];
        $raw = ['response' => $inner];

        $out = utils::extract_classification($raw);
        $this->assertSame('MITRE D3FEND', $out['frameworkshortname']);
        $this->assertEquals(['Harden', 'Detect'], $out['levels']);
        $this->assertEquals(['Network Segmentation', 'Email Content Filtering'], $out['competencies']);
    }

    public function test_extract_classification_handles_malformed_and_empty_values(): void {
        $out = utils::extract_classification(['generatedcontent' => '{}']);
        $this->assertSame('', $out['frameworkshortname']);
        $this->assertSame([], $out['levels']);
        $this->assertSame([], $out['competencies']);

        $payload = json_encode(['framework' => ['shortname' => '   '], 'levels' => [null, 1, ' '], 'competencies' => [false]]);
        $out = utils::extract_classification(['generatedcontent' => $payload]);
        $this->assertSame('', $out['frameworkshortname']);
        $this->assertSame([], $out['levels']);
        $this->assertSame([], $out['competencies']);
    }

    public function test_extract_classification_allows_array_payload_without_stringification(): void {
        $raw = [
            'generatedcontent' => [
                'framework' => ['shortname' => 'ATT&CK'],
                'levels' => ['Initial Access', 'Execution', 'Execution'],
                'competencies' => ['T1566 - Phishing', 'T1059 - Command and Scripting Interpreter'],
            ]
        ];
        $out = utils::extract_classification($raw);
        $this->assertSame('ATT&CK', $out['frameworkshortname']);
        $this->assertEquals(['Initial Access', 'Execution'], $out['levels']);
        $this->assertEquals(
            ['T1566 - Phishing', 'T1059 - Command and Scripting Interpreter'],
            $out['competencies']
        );
    }
}