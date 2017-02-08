<?php 
	namespace app\index\controller;
	use think\Controller;
	class Room extends Controller
	{
		public function index()
		{
			$this->assign('username',request()->param('username'));
			return $this->fetch();
		}
	}
?>