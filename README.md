# Tdi-php
花瓣网、堆糖网下载油猴脚本的远程下载服务(Tdi for PHP).

此程序相当于`CrawlHuaban`(中心端)的成员，用户选择远端下载后会由中心端选择一个成员提供给用户，减少中心端压力。

Python版本的仓库地址是：https://github.com/staugur/tdi

Node.js版本的仓库地址是：https://github.com/staugur/tdi-node

Golang版本的仓库地址是：https://github.com/staugur/tdi-go

## 部署：

1. 要求： PHP7+和Redis
2. 下载： `git clone https://github.com/staugur/tdi-php && cd tdi-php/src`
3. 依赖： 必需扩展curl、zip、pcntl、redis，可选扩展proctitle
4. 配置： 即config.php，需要参考config.sample.php(重命名或复制一份)，必填项是REDIS和TOKEN
5. 启动： 
    - 启动php-fpm进程：例如`service php-fpm start`
    - 启动队列处理进程：sh online_rq.sh start # 若需前台启动，将start换成run即可


## 更多文档：

[点击查看文档](https://docs.saintic.com/tdi-php/ "点击查看部署及使用文档")，关于普通部署、使用手册、注意事项等问题。

若上述地址异常，备用地址是：[https://saintic-docs.readthedocs.io/tdi-php/](https://saintic-docs.readthedocs.io/tdi-php/)


## Nginx参考：
```
server {
    listen 80;
    server_name 域名;
    root /path/to/tdi-php/src/;
    index index.html index.htm index.php;
    client_max_body_size 10M;
    client_body_buffer_size 128k;
    location / {
        try_files $uri $uri/ /$uri.php?$query_string;
    }
    location ~ \.php$ {
        set $denyAccess 1;
        if ($uri ~* /(ping|download)) {
            set $denyAccess 0;
        }
        if ($denyAccess) {
            return 404;
        }
        try_files $uri = 404;
        include fastcgi.conf;
        # PHP-FPM监听端口或socket
        fastcgi_pass 127.0.0.1:9000;
    }
}
```
