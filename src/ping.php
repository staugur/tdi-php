<?php
/*
    CrawlHuabanTdi-PHP
    ~~~~~~~~~~~~~~~~~~~~

    花瓣网、堆糖网下载程序的远端下载服务。

    ping接口，仅支持get请求。

    :copyright: (c) 2019 by staugur.
    :license: BSD 3-Clause, see LICENSE for more details.
*/

// 应用入口文件
error_reporting(E_NOTICE);
ini_set('display_errors', 'Off');

// 设置时区和返回头
date_default_timezone_set('Asia/Shanghai');
header('Content-Type:application/json; charset=utf-8');

// 引入配置文件和工具
require_once './config.php';
require_once './tool.php';

// 检测PHP环境
if (version_compare(PHP_VERSION, '7.0.0', '<')) {
    make_response('require PHP > 7.0.0 !');
}

// 验证令牌
signature_required(TOKEN);

// 下载目录
$DOWNLOADPATH = __DIR__.'/downloads';
if (!is_dir($DOWNLOADPATH)) {
    mkdir($DOWNLOADPATH);
}

// 连接redis
$rc = getRedisConnect(REDIS);

$resp = array(
    'code' => 0,
    'version' => VERSION,
    'status' => STATUS,
    'memRate' => memRate(),
    'loadFive' => loadStat(),
    'diskRate' => diskRate($DOWNLOADPATH),
    'timestamp'=> time(),
    'rqcount' => intval($rc->llen('resque:queue:default')),
    'rqfailed' => intval($rc->get('resque:stat:failed')),
    'email' => ALARMEMAIL,
    'lang' => 'PHP'.PHP_VERSION
);

$rc->close();

make_response('', 0, false, $resp);
