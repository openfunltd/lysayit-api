<?php

include(__DIR__ . '/LYLib.php');
include(__DIR__ . '/config.php');

try {
    LYLib::dropIndex('meet');
    LYLib::dropIndex('person');
    LYLib::dropIndex('speech');
    LYLib::dropIndex('vote');
} catch (Exception $e) {
}
LYLib::createIndex('meet', [
    "date_detection" => false,
    'properties' => [
        'term' => ['type' => 'integer'],
        'sessionPeriod' => ['type' => 'integer'],
        'date' => ['type' => 'integer'],
        'title' => ['type' => 'text'],
    ],
]);

LYLib::createIndex('person', [
    "date_detection" => false,
    'properties' => [
        'term' => ['type' => 'integer'],
        'type' => ['type' => 'integer'],
    ],
]);

LYLib::createIndex('speech', [
    "date_detection" => false,
    'properties' => [
        'term' => ['type' => 'integer'],
        'meet_id' => ['type' => 'keyword'],
        'lineno' => ['type' => 'integer'],
        'speaker' => ['type' => 'keyword'],
        'content' => ['type' => 'text'],
    ],
]);

LYLib::createIndex('vote', [
    "date_detection" => false,
    'properties' => [
        'term' => ['type' => 'integer'],
        'meet_id' => ['type' => 'keyword'],
        'line_no' => ['type' => 'integer'],
        '贊成' => ['type' => 'keyword'],
        '反對' => ['type' => 'keyword'],
        '棄權' => ['type' => 'keyword'],
        'date' => ['type' => 'integer'],
    ],
]);
