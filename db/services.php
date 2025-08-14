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
        'capabilities'=> 'aiplacement/classifyassist:use',
    ],
];
