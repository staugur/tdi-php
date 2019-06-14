#!/bin/bash
#
#启动resque异步任务队列进程
#

#项目代码根目录
RUNDIR=$(cd $(dirname $0); pwd)
cd $RUNDIR

#准备环境
if [ -r online_preboot.sh ]; then
    source ./online_preboot.sh
fi

if [ -z "$PHP_CLI" ]; then
    PHP_CLI="/usr/bin/env php"
fi
PHP_RESQUE="${RUNDIR}/vendor/bin/resque"
#export VVERBOSE=1 # for debugging
export REDIS_BACKEND=$($PHP_CLI -r "require './config.php'; echo REDIS;")
export APP_INCLUDE=${RUNDIR}/qf.php
export QUEUE=default
export COUNT=1
export BLOCKING=TRUE
export INTERVAL=5
export PIDFILE=${RUNDIR}/logs/resque.pid
export LOGFILE=${RUNDIR}/logs/resque.log
[ -d ${RUNDIR}/logs ] || mkdir -p ${RUNDIR}/logs
[ -d ${RUNDIR}/downloads ] || mkdir -p ${RUNDIR}/downloads

get_pid() {
    if [ -f $PIDFILE ]; then
        cat $PIDFILE
    fi
}

run() {
    #前台启动
    unset PIDFILE
    $PHP_CLI $PHP_RESQUE
}

start() {
    local PID=$(get_pid)
    if [ ! -z $PID ]; then
        echo "php-resque($PID) is running."
        echo "You should stop it before you start."
        return
    fi
    touch $PIDFILE
    echo "Starting php-resque..."
    nohup $PHP_CLI $PHP_RESQUE >> $LOGFILE 2>&1 &
}

stop() {
    local PID=$(get_pid)
    if [ -z $PID ]; then
        echo "php-resque is not running."
        return
    fi
    echo "Stopping php-resque..."
    kill -QUIT $PID
    sleep 1
    while [ -x /proc/${PID} ]
    do
        kill -TERM $PID
        sleep 1
    done
    rm -f $PIDFILE
}

status() {
    local PID=$(get_pid)
    if [ ! -z $PID ]; then
        echo "php-resque($PID) is running."
    else
        echo "php-resque is not running."
    fi
}

case "$1" in
    run)
        run
        ;;

    start)
        start
        ;;

    stop)
        stop
        ;;

    restart)
        stop
        start
        ;;

    status)
        status
        ;;

    *)
        echo -n "Usage: $0 {run|start|stop|restart|status}"
        exit 1
        ;;
esac

exit 0
