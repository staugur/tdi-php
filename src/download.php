<?php
/*
    CrawlHuabanTdi-PHP
    ~~~~~~~~~~~~~~~~~~~~

    花瓣网、堆糖网下载程序的远端下载服务。

    download接口，仅支持post请求。

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

// 不支持命令行
if (php_sapi_name() === "cli") {
    make_response('Command-line mode access is not allowed');
}

// Only allow POST requests
if (strtoupper($_SERVER['REQUEST_METHOD']) != 'POST') {
    make_response('Only POST requests are allowed');
}

// 验证令牌
signature_required(TOKEN);

// 下载目录
$DOWNLOADPATH = __DIR__.'/downloads';
if (!is_dir($DOWNLOADPATH)) {
    mkdir($DOWNLOADPATH);
}

// 获取POST的数据
$json_params = file_get_contents("php://input");
if (strlen($json_params) > 0 && isValidJSON($json_params)) {
    $data = json_decode($json_params, true);
    if (is_array($data) && isset($data['uifnKey']) && isset($data['site']) && isset($data['board_id']) && isset($data['uifn']) && isset($data['board_pins']) && isset($data['etime']) && isset($data['MAX_BOARD_NUMBER']) && isset($data['CALLBACK_URL'])) {
        $uifn = $data['uifn'];
        $etime = intval($data["etime"]);
        // 存入缓存数据
        $rc = getRedisConnect(REDIS);
        $pipe = $rc->multi(Redis::PIPELINE);
        $pipe->hMSet($uifn, array('etime'=>$etime, 'CALLBACK_URL'=>$data['CALLBACK_URL'], 'board_pins'=>$data['board_pins'], 'MAX_BOARD_NUMBER'=>$data['MAX_BOARD_NUMBER'], 'board_id'=>$data['board_id'], 'site'=>$data['site'], 'uifnKey'=>$data['uifnKey']));
        $pipe->expireAt($uifn, $etime + 86400 * 7);
        try {
            $pipe->exec();
            $rc->close();
            // 执行成功，将任务放到队列中
            require_once './vendor/autoload.php';
            // 设置resque的redis连接信息
            Resque::setBackend(REDIS);
            $args = array(
                'downloadDir'=>$DOWNLOADPATH,
                'uifn'=>$uifn,
                'diskLimit'=> isset($data['DISKLIMIT']) ? floatval($data['DISKLIMIT']) : 0,
                'timeout'=> isset($data['TIMEOUT']) ? intval($data['TIMEOUT']) : 7200
            );
            // 入列
            Resque::enqueue('default', 'DownloadBoard', $args);
            // 返回响应
            make_response('', 0, false, array('code'=>0));
        } catch (Exception $e) {
            make_response('Failure to provide services');
        }
    } else {
        make_response('Invalid param');
    }
}
