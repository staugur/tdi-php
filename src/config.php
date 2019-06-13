<?php

// 配置文件；版本号
define('VERSION', '0.1.0');

// REDIS connection information in the DSN-style format: redis://[user:password]@host[:port/db]
define('REDIS', 'redis://:XingkaOps@127.0.0.1:6379/1');

// Verification token filled in at the time of registration at the central end
define('TOKEN', 'test');

// Set this service status: ready or tardy
define('STATUS', 'ready');

// Alarm mail box
define('ALARMEMAIL', '');
