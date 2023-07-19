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
]);

LYLib::createIndex('person', [
    "date_detection" => false,
]);

LYLib::createIndex('speech', [
    "date_detection" => false,
]);

LYLib::createIndex('vote', [
    "date_detection" => false,
]);
