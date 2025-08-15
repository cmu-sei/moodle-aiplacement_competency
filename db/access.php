<?php

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'aiplacement/classifyassist:classify_text' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
        ],
    ],
];
