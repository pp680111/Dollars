<?php
namespace app\index\controller;
use think\Controller;
use app\index\model\Userdata;
use think\Db;
use app\socketserver\controller\Server;

class Index extends Controller
{
    public function index()
    {
    	$this->assign('formurl',request()->url());
    	return $this->fetch();
    }

    public function enterRoom()
    {   
        return redirect('index/room/index',['username' => request()->param('username')]);
    }
}
?>