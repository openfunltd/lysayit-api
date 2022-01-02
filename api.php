<?php

include(__DIR__ . '/init.inc.php');
if (!preg_match('#/api/([^/]+)(.*)#', $_SERVER['REQUEST_URI'], $matches)) {
    readfile(__DIR__ . '/notfound.html');
    exit;
}
$method = strtolower($matches[1]);
if ($method == 'meet') {
    $obj = API::query('/meet/_search?size=10000');
    $records = array();
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        $record->id = $hit->_id;
        $record->extra = json_decode($record->extra);
        $record->api_url = 'https://' . $_SERVER['HTTP_HOST'] . '/api/speech/' . $hit->_id;
        $records[] = $record;
    }
    echo json_encode($records);
    exit;
} else if ($method == 'speech') {
    list(, $id) = explode('/', $matches[2]);
    $cmd = [
        'query' => array(
            'match' => array('meet_id' => $id),
        ),
        'sort' => 'lineno',
        'size' => 10000,
    ];
    $obj = API::query('/speech/_search', 'GET', json_encode($cmd));
    $records = array();
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        unset($record->meet_id);
        $records[] = $record;
    }
    echo json_encode($records, JSON_UNESCAPED_UNICODE);
    exit;
} elseif ($method == 'speaker') {
    list(, $id) = explode('/', $matches[2]);
    $id = urldecode($id);
    $cmd = [
        'query' => array(
            'match' => array('speaker' => $id),
        ),
        'size' => 10000,
    ];
    $obj = API::query('/speech/_search', 'GET', json_encode($cmd));
    $records = array();
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        $records[] = $record;
    }
    echo json_encode($records, JSON_UNESCAPED_UNICODE);
    exit;
} else {
    readfile(__DIR__ . '/notfound.html');
    exit;
}
