<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'aiplacement_classifyassist_classify_text' => [
        'classname'   => 'aiplacement_classifyassist\\external\\classify_text',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Classify arbitrary text using the configured AI provider.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities'=> 'aiplacement/classifyassist:classify_text',
    ],
    'aiplacement_classifyassist_add_cm_competency' => [
        'classname'   => 'aiplacement_classifyassist\external\add_cm_competency',
        'methodname'  => 'execute',
        'description' => 'Add a competency to a course module.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities'=> 'moodle/competency:coursecompetencyconfigure',
    ],
];
