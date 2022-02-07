<?php

include(__DIR__ . '/LYLib.php');
include(__DIR__ . '/config.php');

LYLib::dbQuery('', 'DELETE');
LYLib::dbQuery('', 'PUT', '{"settings": { "analysis":{ "analyzer":{ "default":{"type":"cjk"} } }} }');
LYLib::dbQuery('/meet/_mapping', 'DELETE', '{}');
LYLib::dbQuery("/meet/_mapping", 'PUT', '{
 "meet" : {
  "date_detection": false,
  "properties" : {
    "title": {"type" : "string", "index":"analyzed", "analyzer":"cjk"},
    "term": {"type": "integer"},
    "sessionPeriod": {"type": "integer"},
    "date": {"type": "integer"},
    "extra" : {"type" : "string"}
  }
 }
}');
LYLib::dbQuery('/person/_mapping', 'DELETE', '');
LYLib::dbQuery("/person/_mapping", 'PUT', '{
 "person" : {
  "date_detection": false,
  "properties" : {
    "term": {"type": "integer"},
    "name": {"type" : "string", "index":"analyzed", "analyzer":"cjk"},
    "type": {"type" : "integer"},
    "extra" : {"type" : "string"}
  }
 }
}');

LYLib::dbQuery("/speech/_mapping", 'DELETE', '');
LYLib::dbQuery("/speech/_mapping", 'PUT', '{
 "speech" : {
  "date_detection": false,
  "properties" : {
    "term": {"type": "integer"},
    "meet_id": {"type": "string"},
    "lineno": {"type" : "integer"},
    "speaker": {"type": "string", "index": "not_analyzed"},
    "content": {"type" : "string", "index":"analyzed", "analyzer":"cjk"},
    "extra" : {"type" : "string"}
  }
 }
}');

LYLib::dbQuery('/vote/_mapping', 'DELETE', '');
LYLib::dbQuery('/vote/_mapping', 'PUT', json_encode([
    'vote' => [
        'date_detection' => false,
        'properties' => [
            'term' => ['type' => 'integer'],
            'meet_id' => ["type" => "string"],
            'line_no' => ['type' => 'integer'],
            '贊成' => ['type' => 'string', 'index' => 'not_analyzed'],
            '反對' => ['type' => 'string', 'index' => 'not_analyzed'],
            '棄權' => ['type' => 'string', 'index' => 'not_analyzed'],
            'extra' => ['type' => 'string'],
        ],
    ],
]));
echo json_encode($ret, JSON_UNESCAPED_UNICODE) . "\n";
