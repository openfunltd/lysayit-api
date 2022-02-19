<?php

ini_set('memory_limit', '2G');
include(__DIR__ . '/LYLib.php');
include(__DIR__ . '/config.php');
include(__DIR__ . '/Parser.php');
include(__DIR__ . '/S3Lib.php');

error_log("抓取歷屆委員名單");

$fp = fopen('php://temp', 'rw');
fputs($fp, LYLib::getPersonList());
fseek($fp, 0, SEEK_SET);
$columns = fgetcsv($fp);
$columns[0] = 'term';

$person_data = [];
while ($rows = fgetcsv($fp)) {
    $values = array_combine($columns, $rows);
    $person_data[$values['term'] . '-' . $values['name']] = 
        [$values['term'], $values['name'], $values['picUrl'], $values['partyGroup']];
}

error_log("更新最近一年資料");
$oldt = $oldp = 0;
$list_files = [];
for ($ym = time(); $ym > time() - 86400 * 365; $ym = strtotime('-1 month', $ym)) {
    list($term, $period) = LYLib::getTermPeriodByDate($ym);
    if ($oldt != $term or $oldp != $period) {
        $oldt = $term;
        $oldp = $period;

        $content = LYLib::getListFromTermPeriod($term, $period);
        $target = sprintf(__DIR__ . "/list/%02d%02d.csv", $term, $period);
        file_put_contents($target, $content);
        $list_files[] = $target;
    }
}

$meet_info = new StdClass;

error_log("抓取 DOC 檔");
// 抓取沒有的 doc 檔
foreach ($list_files as $file) {
    $fp = fopen($file, 'r');
    $columns = fgetcsv($fp);
    if (strpos($columns[0], 'comYear') === false) {
        error_log("skip {$file}");
        continue;
    }
    $columns[0] = 'comYear';
    $docfull = array();
    while ($rows = fgetcsv($fp)) {
        $values = array_map('trim', array_combine($columns, $rows));
        unset($values['']);
        $docfilename = basename($values['docUrl']);
        $meet_info->{str_replace('.doc', '', $docfilename)} = $values;
        if (!array_key_Exists($docfilename, $docfull)) {
            $docfull[$docfilename] = $values['docUrl'];
        } else if ($docfull[$docfilename] != $values['docUrl']) {
            throw new Exception("{$values['docUrl']}");
        }
        if (file_exists("txtfile/{$docfilename}") and filesize("txtfile/{$docfilename}")) {
            continue;
        }
        if (file_exists("docfile/{$docfilename}") and filesize("docfile/{$docfilename}")) {
            continue;
        }
        error_log($values['docUrl']);
        system(sprintf("wget -O %s %s", escapeshellarg("tmp.doc"), escapeshellarg($values['docUrl'])));
        rename("tmp.doc", "docfile/{$docfilename}");
    }
}

foreach ($meet_info as $meet_id => $meet_data) {
    try {
        LYLib::parseTxtFile($meet_id . ".doc");
    } catch (Exception $e) {
        readline("$meet_id error " . $e->getMessage());
        continue;
    }
    $file = __DIR__ . "/txtfile/{$meet_id}.doc";
    if (!file_exists($file)) {
        continue;
    }
    $info = Parser::parse(file_get_contents($file));
    foreach ($info->votes as $vote) {
        $vote_id = "{$meet_id}-{$vote->line_no}";
        $data = [
            'meet_id' => $meet_id,
            'term' => $meet_data['term'],
            'line_no' => $vote->line_no,
        ];
        foreach (['贊成', '反對', '棄權'] as $c) {
            $data[$c] = $vote->{$c};
            unset($vote->{$c});
        }
        unset($vote->line_no);
        $data['extra'] = json_encode($vote, JSON_UNESCAPED_UNICODE);

        LYLib::dbBulkInsert('vote', $vote_id, $data);
    }
    if (!property_exists($info, 'title') or ($info->title != '國是論壇' and !strpos($info->title, '會議紀錄'))) {
        continue;
    }
    if (!intval($meet_data['meetingDate']) and preg_match('/中華民國(\d+)年(\d+)月(\d+)日/', $info->{'時間'}, $matches)) {
        $meet_data['meetingDate'] = sprintf("%03d%02d%02d", $matches[1], $matches[2], $matches[3]);
    }
    foreach ($info as $k => $v) {
        if (in_array($k, array('person_count', 'blocks', 'block_lines', 'persons'))) {
            continue;
        }
        $meet_data[$k] = $v;
    }
    $data = [
        'title' => $meet_data['title'],
        'term' => $meet_data['term'],
        'sessionPeriod' => $meet_data['sessionPeriod'],
        'date' => 19110000 + intval($meet_data['meetingDate']),
        'extra' => json_encode($meet_data),
    ];

    LYLib::dbBulkInsert('meet', $meet_id, $data);

    $blocks = $info->blocks;
    $block_lines = $info->block_lines;;

    foreach ($blocks as $idx => $block) {
        if (strpos($block[0], '：')) {
            list($speaker, $block[0]) = explode('：', $block[0], 2);
        } else {
            $speaker = '';
        }
        $speaker = preg_replace('/（.*）/', '', $speaker);
        if (array_key_exists(intval($term) . '-' . str_replace('委員', '', $speaker), $person_data) and $d = $person_data[intval($term) . '-' . str_replace('委員', '', $speaker)]) {
            $speaker = $d[1];
        }
        $lineno = $block_lines[$idx];
        $data = [
            'meet_id' => $meet_id,
            'term' => $meet_data['term'],
            'lineno' => $lineno,
            'speaker' => $speaker,
            'content' => $block,
        ];
        LYLib::dbBulkInsert('speech', "{$meet_id}-{$lineno}", $data);
    }
}
LYLib::dbBulkCommit();
