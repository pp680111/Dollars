<?php
namespace app\index\controller;
use think\Controller;

class RoomList extends Controller
{
	public function index()
	{
		//这里获取当前房间列表并且传到模板中显示出来
		return $this->fetch();
	}
}

?>