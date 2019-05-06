<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/5/24
 * Time: 下午11:16
 */

namespace EasySwoole\Http\AbstractInterface;

use App\Container\Container;
use EasySwoole\EasySwoole\Config;
use EasySwoole\FastCache\Cache;
use EasySwoole\Http\Message\Status;
use EasySwoole\Http\Request;
use EasySwoole\Http\Response;
use EasySwoole\Http\Session\SessionDriver;
use EasySwoole\Utility\SnowFlake;
use EasySwoole\Validate\Validate;
use PhpParser\Node\Stmt\Static_;

abstract class Controller
{
    private $request;
    private $response;
    private $actionName;
    public $session;
    private $sessionDriver = SessionDriver::class;
    private $allowMethods = [];
    private $defaultProperties = [];
    protected $middleware = []; //继承的中间件
    private $container; //容器对象
    protected $middlewareExcept = []; //中间件要排除的方法
    private $csrf_token = 'csrf_token'; //csrf_token

    function __construct()
    {
        //支持在子类控制器中以private，protected来修饰某个方法不可见
        $list = [];
        $ref = new \ReflectionClass(static::class);
        $public = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);
        foreach ($public as $item) {
            array_push($list, $item->getName());
        }
        $this->allowMethods = array_diff($list,
            [
                '__hook', '__destruct',
                '__clone', '__construct', '__call',
                '__callStatic', '__get', '__set',
                '__isset', '__unset', '__sleep',
                '__wakeup', '__toString', '__invoke',
                '__set_state', '__clone', '__debugInfo'
            ]
        );
        //获取，生成属性默认值
        $ref = new \ReflectionClass(static::class);
        $properties = $ref->getProperties();
        foreach ($properties as $property) {
            //不重置静态变量
            if (($property->isPublic() || $property->isProtected()) && !$property->isStatic()) {
                $name = $property->getName();
                $this->defaultProperties[$name] = $this->$name;
            }
        }
        //获取容器单例
        $this->container = Container::getInstance();
    }

    abstract function index();

    protected function gc()
    {
        // TODO: Implement gc() method.
        if ($this->session instanceof SessionDriver) {
            $this->session->writeClose();
            $this->session = null;
        }
        //恢复默认值
        foreach ($this->defaultProperties as $property => $value) {
            $this->$property = $value;
        }
    }

    protected function actionNotFound(?string $action): void
    {
        $this->response()->withStatus(Status::CODE_NOT_FOUND);
    }

    protected function afterAction(?string $actionName): void
    {
    }

    protected function onException(\Throwable $throwable): void
    {
        throw $throwable;
    }

    protected function onRequest(?string $action): ?bool
    {
        //设置csrf_token
        $this->setCsrfToken();

        //调用中间件，全局排除的方法不验证
        if (!empty($this->middleware) && !in_array(Static::class . '\\' . $action, $this->middlewareExcept)) {

            foreach ($this->middleware as $pipe) {

                $ins = $this->container->get($pipe);

                //中间件局部排除的方法，不验证
                if (in_array(Static::class . '\\' . $action, $ins->getExcept())) {
                    continue;
                }

                if (!$ins->exec($this->request, $this->response, $this->session)) {
                    $this->writeJson(200, '中间件验证失败', sprintf("中间件%s:验证失败,原因：%s", $pipe, $ins->getError()));
                    return false;
                }

            }
        }

        return true;
    }

    /**
     * 设置csrf_token
     */
    public function setCsrfToken()
    {
        $session = $this->session();
        $session->start();
        $csrf_token = $session->get('csrf_token');
        //csrf_token已存在则不重复设置
        empty($csrf_token) && $session->set('csrf_token', SnowFlake::make(16));
    }

    protected function getActionName(): ?string
    {
        return $this->actionName;
    }

    public function __hook(?string $actionName, Request $request, Response $response): ?string
    {
        $forwardPath = null;
        $this->request = $request;
        $this->response = $response;
        $this->actionName = $actionName;
        try {
            if ($this->onRequest($actionName) !== false) {
                if (in_array($actionName, $this->allowMethods)) {
                    $forwardPath = $this->$actionName();
                } else {
                    $forwardPath = $this->actionNotFound($actionName);
                }
            } else {
                $this->response->end();
            }
        } catch (\Throwable $throwable) {
            //若没有重构onException，直接抛出给上层
            $this->onException($throwable);
        } finally {
            try {
                $this->afterAction($actionName);
            } catch (\Throwable $throwable) {
                $this->onException($throwable);
            } finally {
                try {
                    $this->gc();
                } catch (\Throwable $throwable) {
                    $this->onException($throwable);
                }
            }
        }
        if (is_string($forwardPath)) {
            return $forwardPath;
        }
        return null;
    }

    protected function request(): Request
    {
        return $this->request;
    }

    protected function response(): Response
    {
        return $this->response;
    }

    protected function writeJson($statusCode = 200, $result = null, $msg = null)
    {
        if (!$this->response()->isEndResponse()) {
            $data = Array(
                "code" => $statusCode,
                "result" => $result,
                "msg" => $msg
            );
            $this->response()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->response()->withHeader('Content-type', 'application/json;charset=utf-8');
            $this->response()->withStatus($statusCode);
            return true;
        } else {
            return false;
        }
    }

    protected function json(): ?array
    {
        return json_decode($this->request()->getBody()->__toString(), true);
    }

    protected function xml($options = LIBXML_NOERROR, string $className = 'SimpleXMLElement')
    {
        //禁止引用外部xml实体
        libxml_disable_entity_loader(true);
        return simplexml_load_string($this->request()->getBody()->__toString(), $className, $options);
    }

    protected function validate(Validate $validate)
    {
        return $validate->validate($this->request()->getRequestParam());
    }

    protected function session(\SessionHandlerInterface $sessionHandler = null): SessionDriver
    {
        if ($this->session == null) {
            $class = $this->sessionDriver;
            $this->session = new $class($this->request, $this->response, $sessionHandler);
        }
        return $this->session;
    }

    protected function sessionDriver(string $sessionDriver): Controller
    {
        $this->sessionDriver = $sessionDriver;
        return $this;
    }
}
