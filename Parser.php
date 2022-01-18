<?php

class Parser
{
    public static function matchFirstLine($line)
    {
        if (in_array(str_replace('　', '', trim($line)), array('報告事項', '討論事項'))) {
            return ['category', str_replace('　', '', trim($line))];
        }

        if (preg_match('#^([一二三四五六七八九])、#u', trim($line), $matches)) {
            return ['一', $matches[1]];
        }
        if (preg_match('#^(\([一二三四五六七八九十]+\))#u', trim($line), $matches)) {
            return ['(一)', $matches[1]];
        }
        return false;
    }

    public static function parse($content)
    {
        $blocks = [];
        $block_lines = [];
        $current_block = [];
        $current_line = 1;
        $persons = [];
        $lines = explode("\n", $content);
        $skip = [
            "出席委員", "列席委員", "專門委員", "主任秘書", "決議", "決定", "請假委員", "說明", "列席官員", "註", "※註", "機關﹙單位﹚名稱", "單位", "在場人員", "歲出協商結論", "案由", "備註", "受文者", "發文日期", "發文字號", "速別", "附件", "主旨", "正本", "副本", "列席人員",
        ];
        $idx = 0;
        while (count($lines)) {
            $idx ++;
            $line = array_shift($lines);
            $line = str_replace('　', '  ', $line);
            $line = trim($line, "\n");

            if (strpos($line, '|') === 0) {
                $current_block[] = $line;
                continue;
            }

            if (preg_match('#^[一二三四五六七八]、#u', $line)) {
                $blocks[] = $current_block;
                $block_lines[] = $current_line;
                $current_line = $idx;
                $current_block = ['段落：' . $line];
                continue;
            }

            if (preg_match('#^立法院.*議事錄$#', $line)) {
                $current_block[] = $line;
                while (count($lines)) {
                    $idx ++;
                    $line = array_shift($lines);
                    $line = str_replace('　', '  ', $line);
                    $line = trim($line, "\n");
                    $current_block[] = $line;
                    if (strpos($line, '散會') === 0) {
                        break;
                    }
                }
                continue;
            }
            if (!preg_match('#^([^　 ：]+)：(.+)#u', $line, $matches)) {
                $current_block[] = $line;
                continue;
            }
            $person = $matches[1];
            if (in_array($person, $skip) or strpos($person, '、')) {
                $current_block[] = $line;
                continue;
            }
            if (!array_key_Exists($person, $persons)) {
                $persons[$person] = 0;
            }
            $persons[$person] ++;
            $blocks[] = $current_block;
            $current_block = [$line];
            $block_lines[] = $current_line;
            $current_line = $idx;
        }
        $blocks[] = $current_block;
        $block_lines[] = $current_line;
        $ret = new StdClass;
        $ret->blocks = $blocks;
        $ret->block_lines = $block_lines;
        $ret->person_count = $persons;
        $ret->persons = array_keys($persons);

        while (count($blocks[0])) {
            $line = array_shift($blocks[0]);
            $line = str_replace('　', '  ', $line);
            if (trim($line) == '') {
                continue;
            }
            if (trim($line) == '委員會紀錄') {
                $ret->type = $line;
                continue;
            }
            if (strpos($line, '立法院第') === 0) {
                $ret->title = $line;
                while (trim($blocks[0][0]) != '') {
                    if (strpos(str_replace(' ', '', $blocks[0][0]), '時間') === 0) {
                        break;
                    }
                    $ret->title .= array_shift($blocks[0]);
                }
                continue;
            }
            $columns = array('時間', '地點', '主席');
            mb_internal_encoding('UTF-8');
            foreach ($columns as $c) {
                if (strpos(preg_replace('/[ 　]/u', '', $line), $c) === 0) {
                    $c_len = mb_strlen($c);
                    for ($i = 0; $i < mb_strlen($line); $i ++) {
                        if (in_array(mb_substr($line, $i, 1), array(' ', '　'))) {
                            continue;
                        }
                        $c_len --;
                        if ($c_len == 0) {
                            $ret->{$c} = ltrim(mb_substr($line, $i + 1));
                            break;
                        }
                    }
                    continue 2;
                }
            }
        }
        array_shift($ret->blocks);
        return $ret;
    }
}
