<?php

namespace cli;

use esp\core\Config;
use mod\Download;
use Swoole\Server;

/**
 *
 * 后台运行时，停止所有工作：
 * ps -eaf |grep "DownloadController" | grep -v "grep"| awk '{print $2}' |xargs kill -9
 *
 * Class DownloadController
 * @package cli
 */
class DownloadController extends BaseController
{
    private $hide;
    private $echo;
    private $pressName;

    public function _init()
    {
        ini_set('default_socket_timeout', -1);
        $this->hide = in_array('hide', $GLOBALS['argv']);
        $this->echo = in_array('echo', $GLOBALS['argv']);
        $this->pressName = get_class($this);
    }


    public function mziAction()
    {
        $port = 9500;
        $_conf = Config::load('config/swoole.ini', 'download');
        $_conf['daemonize'] = $this->hide;
        mk_dir($_conf['log_file']);
        $socket = new Server("0.0.0.0", $port);
        $socket->set($_conf);


        /**
         * 此事件在worker进程终止时发生。在此函数中可以回收worker进程申请的各类资源。
         */
        $socket->on('WorkerStop', function (Server $server, $worker_id) {
            echo "进程退出={$worker_id}\n";
        });

        /**
         * 当worker/task_worker进程发生异常后会在Manager进程内回调此函数。
         * $worker_id 是异常进程的编号
         * $worker_pid 是异常进程的ID
         * $exit_code 退出的状态码，范围是 1 ～255
         */
        $socket->on('WorkerError', function (Server $server, $worker_id, $worker_pid, $exit_code) {
            echo 'WorkerError:';
            print_r(['workID' => $worker_id, 'workPID' => $worker_pid, 'code' => $exit_code]);

            if ($worker_id === 0) {//主进程出错，重启全部
                $server->reload();
            }

        });

        /**
         * 当管理进程启动时调用
         */
        $socket->on('ManagerStart', function (Server $server) {
            swoole_set_process_name("{$this->pressName}Manager");
            echo "ManagerStart\n";
        });


        /**
         * 当管理进程结束时调用
         */
        $socket->on('ManagerStop', function (Server $server) {
            echo "ManagerStop\n";
        });


        /**
         * TCP客户端连接关闭后
         */
        $socket->on('close', function (Server $server, $fd) {
            echo "close={$fd}\n";
        });

        /**
         * Server结束时发生
         */
        $socket->on('shutdown', function (Server $server) {
            echo 'shutdown:' . date('Y-m-d H:i:s') . "\n";
        });


        $socket->on('Receive', function (Server $server, int $fd, int $reactor_id, string $data) {
            echo 'Receive:';
        });

        $socket->on('Packet', function (Server $server, string $data, array $client_info) {
            echo 'Packet:';
        });


        $socket->on('start', function (Server $server) use ($port) {
            swoole_set_process_name("{$this->pressName}Master");
            echo "Port:\t\t{$port}\n";
            echo "PHP :\t\t" . phpversion() . "\n";
            echo "Swoole :\t" . SWOOLE_VERSION . "\n";
            echo "Time:\t\t" . date('Y-m-d H:i:s') . "\n";
            echo "===========================\n";
        });

        /**
         * 2.启动进程=worker_num
         * 其中：
         *  任务进程    =task_worker_num
         *  工作进程    =worker_num
         */
        $socket->on('WorkerStart', function (Server $server, $worker_id) {

            if ($server->taskworker) {
                swoole_set_process_name("{$this->pressName}Task_{$worker_id}");
                echo "任务进程{$worker_id}启动\n";
                return;
            }
            swoole_set_process_name("{$this->pressName}Worker_{$worker_id}");
            echo "工作进程{$worker_id}启动\n";

            if ($worker_id === 0) {

                $server->tick(1000, function () use ($server) {
                    $time = time();
                    $h = intval(date('H', $time));//时
                    $i = intval(date('i', $time));//分
                    $s = intval(date('s', $time));//秒

                    //每5秒执行一次，
                    if (($s % 1 === 0)) {
                        $send = $server->task(['action' => 'download'], -1, function (Server $server, $task_id, $data) {
                            var_dump($data);
                        });
                        if ($send === false) _echo(date('Y-m-d H:i:s') . "\tTask失败：['action' => 'download']\n", 'r', 'w');

                    }

                });

            }
        });

        /**
         * 通道内的消息
         * $server->sendMessage(['action' => 'notify', 'value' => ['lotID' => $lotteryID], 'wait' => $wait * 2], $src_worker_id);
         */
        $socket->on('PipeMessage', function (Server $server, $from_worker_id, $data) {
            if (!is_array($data)) return;
            if (!isset($data['action'])) return;
        });


        $socket->on('task', function (Server $server, $task_id, $src_worker_id, $data) {
            switch ($data['action'] ?? '') {
                case 'reload':
                    return $server->reload();
                    break;

                case 'download'://下载任务
                    $mod = new Download();
                    return $mod->download();
                    break;

                default:
                    echo "未知任务：\n";
                    print_r($data);
                    return false;
            }
        });

        /**
         * Task完成任务时
         */
        $socket->on('finish', function (Server $server, $task_id, $data) {
            if (is_bool($data)) return;
            if (is_array($data)) $data = json_encode($data, 256);
            _echo(date('Y-m-d H:i:s') . "\t任务完成={$task_id}\t{$data}\n", 'g', 'w');
        });

        $socket->start();
    }


}