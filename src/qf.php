<?php

ini_set('memory_limit', '256M');
//ini_set('memory_limit', '-1');

// 引入配置文件和工具
require_once './config.php';
require_once './tool.php';

// 异步任务类
class DownloadBoard
{
    public function writeREADME(string $filename, string $content)
    {
        $fn = fopen($filename, "a");
        fwrite($fn, $content);
        fclose($fn);
    }

    public function perform()
    {
        /*
        @param downloadDir str: 画板上层目录，CrawlHuaban插件所在目录，图片直接保存到此目录的`board_id`下
        @param uifn: str: 唯一标识文件名
        @param uifnKey: str: 标识索引
        @param site: int: 站点id 1是花瓣网 2是堆糖网
        @param board_id str int: 画板id
        @param board_pins: list: 画板图片
        @param MAX_BOARD_NUMBER: int: 允许下载的画板数量
        */
        $ALLOWDOWN = true;
        //获取参数
        $downloadDir = $this->args['downloadDir'];
        $uifn = $this->args['uifn'];
        $diskLimit = $this->args['diskLimit'];
        $timeout = $this->args['timeout'];
        set_time_limit($timeout);
        //连接redis
        $rc = getRedisConnect(REDIS);
        $data = $rc->hGetAll($uifn);
        $board_pins = json_decode($data['board_pins'], true);
        $CALLBACK_URL = $data['CALLBACK_URL'];
        $MAX_BOARD_NUMBER = intval($data['MAX_BOARD_NUMBER']);
        $board_id = $data['board_id'];
        $site = intval($data['site']);
        $uifnKey = $data['uifnKey'];
        if (count($board_pins) > $MAX_BOARD_NUMBER) {
            $board_pins = array_splice($board_pins, $MAX_BOARD_NUMBER);
        }
        //创建下载目录并切换
        if (!is_dir($downloadDir)) {
            mkdir($downloadDir);
        }
        //切换到下载目录
        chdir($downloadDir);
        //创建临时画板目录并创建锁文件
        if (!is_dir($board_id)) {
            mkdir($board_id);
        }
        if (diskRate($downloadDir) > $diskLimit) {
            $ALLOWDOWN = false;
            $this->writeREADME($board_id.'/README.txt', "Error board_id: ${board_id}\r\nDisk usage is too high");
        }
        //并发下载图片
        $stime = time();
        if ($ALLOWDOWN === true) {
            Log::info('DownloadBoard starting...');
            foreach (array_chunk($board_pins, 100) as $pics) {
                batchDownloadImages($pics, $board_id, $diskLimit);
                //休息一下
                sleep(3);
            }
            Log::info('DownloadBoard ending...');
        }
        //计算总共下载用时，不包含压缩文件的过程
        $dtime = time() - $stime;
        //定义压缩排除
        $exclude = ["zip", "lock", "tar"];
        # 判断是否有足够的空间可以执行压缩命令
        if (diskRate($downloadDir, 'all')['available'] > getDirSize($downloadDir, $exclude)) {
            //基本判断有足够空间执行压缩
            $zipfilepath = make_tarfile($uifn, $board_id, $exclude);
            Log::info('DownloadBoard make_archive over, path is '.$zipfilepath);
            //检测压缩文件大小
            $size = formatSize(filesize($zipfilepath));
            //删除临时画板目录
            delDir($board_id);
            //回调
            $resp = request($CALLBACK_URL.'?Action=FIRST_STATUS', array('uifn'=>$uifn, 'uifnKey'=>$uifnKey, 'size'=>$size, 'dtime'=>$dtime));
            Log::info($resp);
        } else {
            $errmsg = 'Insufficient disk space to compress';
            Log::error($errmsg);
            throw new RuntimeException($errmsg);
        }
    }
}
