<?php

include(__DIR__ . '/init.inc.php');
$uri = explode('?', $_SERVER['REQUEST_URI'])[0];
if (!preg_match('#/api/([^/]+)(.*)#', $uri, $matches)) {
    readfile(__DIR__ . '/notfound.html');
    exit;
}
$method = strtolower($matches[1]);
$matches[2] = ltrim($matches[2], '/');
$params = [];
if ($matches[2]) {
    $params = array_map('urldecode', explode('/', $matches[2]));
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
function json_output($obj) {
    echo json_encode($obj, JSON_UNESCAPED_UNICODE);
    exit;
}

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
    json_output($records);
    exit;
} else if ($method == 'searchspeech') {
    $page = max($_GET['page'], 1);
    $cmd = [
        'query' => array(
            'query_string' => [
                'query' => strval($_GET['q']),
            ],
        ),
        'size' => 100,
        'from' => 100 * $page - 100,
    ];
    $obj = API::query('/speech/_search', 'GET', json_encode($cmd));
    $records = new StdClass;
    $records->page = $page;
    $records->total = $obj->hits->total;
    $records->total_page = ceil($obj->hits->total / 100);
    $records->speeches = [];
    $records->meets = [];
    $meets = array();
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        $meets[$record->meet_id] = true;
        $records->speeches[] = $record;
    }
    $cmd = [
        'query' => array(
            'ids' => array('values' => array_keys($meets)),
        ),
        'size' => 10000,
    ];
    $obj = API::query('/meet/_search', 'GET', json_encode($cmd));
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        $record->id = $hit->_id;
        $record->extra = json_decode($record->extra);
        $records->meets[] = $record;
    }
    json_output($records, JSON_UNESCAPED_UNICODE);
    exit;
} else if ($method == 'speech' and $meet_id = $params[0]) {
    $cmd = [
        'query' => array(
            'match' => array('meet_id' => $meet_id),
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
    json_output($records, JSON_UNESCAPED_UNICODE);
    exit;
} elseif ($method == 'speaker' and $speaker = $params[0] and 'meet' == $params[1]) {
    $cmd = [
        'query' => array(
            'term' => array('speaker' => $speaker),
        ),
        'size' => 10000,
    ];
    $obj = API::query('/speech/_search', 'GET', json_encode($cmd));
    $meets = [];
    $records = new StdClass;
    $records->speeches = [];
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        $meets[$record->meet_id] = true;
        $records->speeches[] = $record;
    }

    $cmd = [
        'query' => array(
            'ids' => array('values' => array_keys($meets)),
        ),
        'size' => 10000,
    ];
    $obj = API::query('/meet/_search', 'GET', json_encode($cmd));
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        $record->id = $hit->_id;
        $record->extra = json_decode($record->extra);
        $records->meets[] = $record;
    }
    json_output($records, JSON_UNESCAPED_UNICODE);
    exit;

} elseif ($method == 'speaker' and $speaker  = $params[0]) {
    $cmd = [
        'query' => array(
            'match' => array('speaker' => $speaker),
        ),
        'size' => 10000,
    ];
    $obj = API::query('/speech/_search', 'GET', json_encode($cmd));
    $records = array();
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        $records[] = $record;
    }
    json_output($records, JSON_UNESCAPED_UNICODE);
    exit;
} elseif ($method == 'term' and count($params) == 2 and $params[1] == 'speaker') {

    $cmd = [
        'size' => 10000,
        'query' => array(
            'term' => array('term' => intval($params[0])),
        ),
    ];
    $obj = API::query('/person/_search', 'GET', json_encode($cmd));
    $records = array();
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        unset($record->term);
        $record->extra = json_decode($record->extra);
        $record->meet_count = 0;
        $record->speech_count = 0;
        $records[$record->name] = $record;
    }

    $cmd = [
        'query' => array(
            'term' => array('term' => intval($params[0])),
        ),
        'aggs' => [
            'speaker_agg' => [
                'terms' => [
                    'field' => 'speaker',
                    'size' => 10000,
                ],
                'aggs' => [
                    'meet_count' => [
                        'cardinality' => ['field' => 'meet_id'],
                    ],
                ],
            ],
        ],
        'size' => 0,
    ];
    $obj = API::query('/speech/_search', 'GET', json_encode($cmd));
    foreach ($obj->aggregations->speaker_agg->buckets as $bucket) {
        if (!array_key_exists($bucket->key, $records)) {
            continue;
        }
        $records[$bucket->key]->speech_count = $bucket->doc_count;
        $records[$bucket->key]->meet_count = $bucket->meet_count->value;
    }
    json_output(array_values($records), JSON_UNESCAPED_UNICODE);
    exit;
} else {
    readfile(__DIR__ . '/notfound.html');
    exit;
}
