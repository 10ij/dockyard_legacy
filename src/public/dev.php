<?php
require_once '../includes/docker.php';

$docker = new Docker();

// test all funcs of the lib
var_dump($docker->list());
var_dump($docker->start('flaskstat'));
var_dump($docker->logs('flaskstat', 10));
var_dump($docker->stop('flaskstat'));
var_dump($docker->inspect('flaskstat'));