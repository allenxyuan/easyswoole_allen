<?php
/**
 * Created by PhpStorm.
 * User: yuanlj
 * Date: 2019/5/6
 * Time: 14:49
 */

namespace App\HttpController;

use App\Container\Container;
use EasySwoole\Http\AbstractInterface\Controller;
use EasySwoole\Utility\SnowFlake;

abstract class AbstractController extends Controller
{
    protected $middleware = []; //继承的中间件
    private $container; //容器对象
    protected $middlewareExcept = []; //中间件要排除的方法
    private $csrf_token = 'csrf_token'; //csrf_token

    public function __construct()
    {
        parent::__construct();
        //获取容器单例
        $this->container = Container::getInstance();
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
                //执行中间件逻辑
                if (!$ins->exec($this->request(), $this->response(), $this->session())) {
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


}