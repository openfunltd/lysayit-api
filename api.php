<?php

include(__DIR__ . '/init.inc.php');
include(__DIR__ . '/APIDispatcher.php');

APIDispatcher::dispatch($_SERVER['REQUEST_URI']);
