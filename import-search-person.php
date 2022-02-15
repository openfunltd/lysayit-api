<?php

include(__DIR__ . '/LYLib.php');
include(__DIR__ . '/config.php');
include(__DIR__ . '/Parser.php');
$fp = fopen('person.csv', 'r');
$columns = fgetcsv($fp);
$columns[0] = 'term';
while ($rows = fgetcsv($fp)) {
    $values = array_combine($columns, $rows);
    $id = "{$values['term']}-{$values['name']}";
    $data = [
        'term' => $values['term'],
        'name' => $values['name'],
        'type' => 0,
        'extra' => json_encode([
            $values['term'],
            $values['name'],
            $values['picUrl'],
            $values['party'],
        ]),
    ];
    LYLib::dbBulkInsert('person', $id, $data);
}
LYLib::dbBulkCommit();
