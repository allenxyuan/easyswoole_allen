<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/5/28
 * Time: 下午6:33
 */

namespace EasySwoole\EasySwoole;

use App\RoomActor\RoomActor;
use App\RoomActor\RoomManager;
use App\UserActor\UserActor;
use App\UserActor\UserManager;
use App\Utility\Pool\Predis\PredisPool;
use App\WebSocket\WebSocketEvent;
use App\WebSocket\WebSocketParser;
use EasySwoole\Actor\Actor;
use EasySwoole\Component\Pool\PoolManager;
use EasySwoole\EasySwoole\Swoole\EventRegister;
use EasySwoole\EasySwoole\AbstractInterface\Event;
use EasySwoole\Http\Request;
use EasySwoole\Http\Response;
use EasySwoole\Socket\Dispatcher;
use EasySwoole\Utility\File;

class EasySwooleEvent implements Event
{

    public static function initialize()
    {
        date_default_timezone_set('Asia/Shanghai');

        //加载自定义配置
        // self::loadConf();
        $conf = Config::getInstance();//获取配置文件

        //注册Predis连接池
        PoolManager::getInstance()
            ->register(PredisPool::class, $conf->getConf('REDIS.POOL_MAX_NUM'))
            ->setMinObjectNum((int)$conf->getConf('REDIS.POOL_MIN_NUM'));
    }

    public static function mainServerCreate(EventRegister $register)
    {
        $conf = Config::getInstance();
        $serverType = $conf->getConf('MAIN_SERVER.SERVER_TYPE');

        $swooleServer = ServerManager::getInstance()->getSwooleServer();
        $isDev = Core::getInstance()->isDev();

        //自适应热重启,虚拟机下可以传入disableInotify => true,强制使用扫描式热重启,规避虚拟机无法监听事件刷新
        // $process = (new HotReload('HotReload', ['disableInotify' => false]))->getProcess();
        // $swooleServer->addProcess($process);

        // 注册actor服务
        Actor::getInstance()->register(UserActor::class);
        Actor::getInstance()->register(RoomActor::class);
        Actor::getInstance()
            ->setListenAddress('0.0.0.0')
            ->setListenPort(9600)
            ->setTempDir(EASYSWOOLE_TEMP_DIR);
        Actor::getInstance()->attachServer($swooleServer);

        RoomManager::init();
        UserManager::init();

        //注册onWorkerStart回调事件
        $register->add($register::onWorkerStart, function (\swoole_server $server, int $workerId) use ($serverType) {
            if ($workerId == 0 && $serverType == EASYSWOOLE_WEB_SOCKET_SERVER) {
                //清理聊天室redis数据
                UserManager::cleanData();

                //创建RoomActor
                $roomActorStatus = RoomActor::client()->status();
                if (array_sum($roomActorStatus) == 0) {
                    $roomsConf = Config::getInstance()->getConf('rooms');
                    foreach ($roomsConf as $k => $v) {
                        RoomActor::client()->create([
                            'roomId' => $v,
                            'redisKey' => "room:" . $v
                        ]);
                    }
                }
            }
        });

        //websocket控制器
        $config = new \EasySwoole\Socket\Config();
        $config->setType($config::WEB_SOCKET);
        $config->setParser(new WebSocketParser());

        $dispatch = new Dispatcher($config);
        $register->set(EventRegister::onOpen, [WebSocketEvent::class, 'onOpen']);
        $register->set(EventRegister::onMessage, function (\swoole_websocket_server $server, \swoole_websocket_frame $frame) use ($dispatch) {
            $dispatch->dispatch($server, $frame->data, $frame);
        });
        $register->set(EventRegister::onClose, [WebSocketEvent::class, 'onClose']);

    }

    public static function onRequest(Request $request, Response $response): bool
    {
        $response->withHeader('Content-type', 'application/json;charset=utf-8');
        return true;
    }

    public static function afterRequest(Request $request, Response $response): void
    {

    }

    /**
     * 引用自定义配置文件
     * @throws \Exception
     */
    public static function loadConf()
    {
        $files = File::scanDirectory(EASYSWOOLE_ROOT . '/App/Config');
        if (is_array($files)) {
            foreach ($files['files'] as $file) {
                $fileNameArr = explode('.', $file);
                $fileSuffix = end($fileNameArr);
                if ($fileSuffix == 'php') {
                    Config::getInstance()->loadFile($file);
                }
            }
        }
    }
}