<?php 
	namespace app\socketserver\controller;
	//参考自http://www.cnblogs.com/zhenbianshu/p/6111257.html
	set_time_limit(0);
	date_default_timezone_set('Asia/shanghai');

	class Server
	{
	    const MAX_LISTEN_NUM = 100;
	    const LOG_PATH = __DIR__ . '/server_log.txt';
		private $sockets = [];
		private $master;

		public function __construct($host,$port)
		{	//这里使用try捕捉创建服务端socket中出现的错误
			try{
				$this->master = socket_create(AF_INET,SOCK_STREAM,SOL_TCP);
				//让端口在服务器重启之后能够重用
				socket_set_option($this->master,SOL_SOCKET,SO_REUSEADDR,1);
				//绑定地址和端口
				socket_bind($this->master, $host,$port);
				//调用socket_listen让这个socket从主动socket编程被动socket，使其能接受别的进程的请求，从而变成一个客户端进程
				socket_listen($this->master,self::MAX_LISTEN_NUM);

				$this->log('Server start successful');
				$this->log('Listening at port:' . $port);
			}catch(\Exception $e)  //这里使用\Exception的意思是使用根命名空间里寻找php自己的Exception类而不是在当前命名空间里我们自己定义的Exception类
			{
				$err_code = socket_last_error();
				$err_msg = socket_strerror($err_code);

				//将错误信息传递给错误处理函数进行处理
				$this->error([
					'error_init_server',
					$err_code,
					$err_msg
					]);
			}

			$this->sockets[0] = ['resource'=>$this->master];
			socket_set_nonblock($this->master);

			//开启服务器主循环
			while(true)
			{
				try{
					$this->doServer();
				}
				catch(\Exception $e)
				{
					$this->error([
					'error_do_server',
					$e->getCode(),
					$e->getMessage()
					]);
				}
			}
		}

		private function doServer()
		{
			$write = $exception = null;
			$sockets = array_column($this->sockets, 'resource');
			$read_num = socket_select($sockets,$write,$exception,NULL);
			// select作为监视函数,参数分别是(监视可读,可写,异常,超时时间),返回可操作数目,出错时返回false;
			if($read_num === false)
			{
				$this->error([
					'error_select',
					$err_code = socket_last_error(),
					socket_strerror($err_code)
					]);
				return;

			}

			foreach ($sockets as $socket) {
				//如果是
				if($this->master == $socket)
				{
					//接收客户端连接并存放进socket数组
					$client = socket_accept($this->master);
					self::connect($client);
					continue;
				}
				else{
					//如果读取的是其他已经连接的socket的话就读取数据并处理应答逻辑
					$bytes = @socket_recv($socket,$buffer,2048,0);
					// 当客户端忽然中断时，服务器会接收到一个 8 字节长度的消息（由于其数据帧机制，8字节的消息我们认为它是客户端异常中断消息），服务器处理下线逻辑，并将其封装为消息广播出去
					if($bytes < 9)
					{
						$recv_msg = $this->disconnect($socket);
					}
					else{
						if(!$this->sockets[(int)$socket]['handShake'])
						{
							self::handShake($socket,$buffer);
							continue;
						}
						else{
							$recv_msg = self::parse($buffer);
						}
					}
					//array_unshift($recv_msg,'recv_msg');
					$msg = self::dealMsg($socket,$recv_msg);
					//这里是为了在接收到更换头像的消息的时候不广播信息
					if(!empty($msg))
						$this->boardcast($msg);
				}
			}

		}

		private function connect($socket)
		{
			$socket_info = [
				'resource' => $socket,
				'username' => '',
				'handShake' => false,
				'avatar' => 'bakyura'
			];
			$this->sockets[(int)$socket] = $socket_info;
		}

		private function handShake($socket,$buffer)
		{
			//获取客户端的升级密钥
			//获取sec这段英文第一次出现的地方以后的所有字符串
			$line_with_key = substr($buffer,strpos($buffer,'Sec-WebSocket-Key:') + 18);
			//再把换行符后面的字符截去就是升级密钥的内容了
			$key = trim(substr($line_with_key,0,strpos($line_with_key,"\r\n")));
			//根据一定的算法生成升级key
			$upgradeKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true));
			//服务端响应头文件
			$upgrade_message = "HTTP/1.1 101 Switching Protocols\r\n";
	        $upgrade_message .= "Upgrade: websocket\r\n";
	        $upgrade_message .= "Sec-WebSocket-Version: 13\r\n";
	        $upgrade_message .= "Connection: Upgrade\r\n";
	        $upgrade_message .= "Sec-WebSocket-Accept:" . $upgradeKey . "\r\n\r\n";

	        socket_write($socket,$upgrade_message,strlen($upgrade_message));
	        $this->sockets[(int)$socket]['handShake'] = true;

	        $msg = [
	        	'type' => 'handshake',
	        	'content' => 'done',
	        ];

	        $msg = $this->build(json_encode($msg));
	        socket_write($socket,$msg,strlen($msg));
	        return true;
		}

		//这个是从别人的代码里拷过来的websocket数据帧解析函数，不过这里应该是解析然后截取了数据帧里面的数据部分然后用json_decode还原了，因为原本的代码里面客户端发送的就是json格式的数据
		private function parse($buffer) {
	        $decoded = '';
	        $len = ord($buffer[1]) & 127;
	        if ($len === 126) {
	            $masks = substr($buffer, 4, 4);
	            $data = substr($buffer, 8);
	        } else if ($len === 127) {
	            $masks = substr($buffer, 10, 4);
	            $data = substr($buffer, 14);
	        } else {
	            $masks = substr($buffer, 2, 4);
	            $data = substr($buffer, 6);
	        }
	        for ($index = 0; $index < strlen($data); $index++) {
	            $decoded .= $data[$index] ^ $masks[$index % 4];
	        }
	        
	        return json_decode($decoded, true);
	    }

	    //这个函数是将普通的信息组装成websocket数据帧用的，同样是复制自别人的代码里来的，自己写的话这个略复杂
	    public function build($msg)
	    {
	    	$frame = [];
	        $frame[0] = '81';
	        $len = strlen($msg);
	        if ($len < 126) {
	            $frame[1] = $len < 16 ? '0' . dechex($len) : dechex($len);
	        } else if ($len < 65025) {
	            $s = dechex($len);
	            $frame[1] = '7e' . str_repeat('0', 4 - strlen($s)) . $s;
	        } else {
	            $s = dechex($len);
	            $frame[1] = '7f' . str_repeat('0', 16 - strlen($s)) . $s;
	        }

	        $data = '';
	        $l = strlen($msg);
	        for ($i = 0; $i < $l; $i++) {
	            $data .= dechex(ord($msg{$i}));
	        }
	        $frame[2] = $data;

	        $data = implode('', $frame);

	        return pack("H*", $data);
	    }

		private function disconnect($socket)
		{
			$recv_msg = [
				'type' => 'logout',
				'content' => $this->sockets[(int)$socket]['username']
			];

			unset($this->sockets[(int)$socket]);

			return $recv_msg;
		}
		//这个dealMsg函数是我借鉴的代码的作者他自己用来解析他从client端发送过来的数据并且生成要发送到client的数据的，将来我想用不一样的数据结构的时候就不要这个函数了
		private function dealMsg($socket,$recv_msg)
		{
			$msg_type = $recv_msg['type'];
			$msg_content = $recv_msg['content'];
			$response = [];

			switch ($msg_type)
			{
				case 'login':
					$this->sockets[(int)$socket]['username'] = $msg_content;
					$this->sockets[(int)$socket]['avatar'] = $recv_msg['avatar'];
					$user_list = array_column($this->sockets,'username');
					$avatar_list = array_column($this->sockets,'avatar');
					$response['type'] = 'login';
					$response['content'] = $msg_content;
					$response['user_list'] = $user_list;
					$response['avatar_list'] = $avatar_list;
					break;
				case 'logout':
					$user_list = array_column($this->sockets, 'username');
					$avatar_list = array_column($this->sockets,'avatar');
	                $response['type'] = 'logout';
	                $response['content'] = $msg_content;
	                $response['user_list'] = $user_list;
	                $response['avatar_list'] = $avatar_list;
	                break;
	            case 'user':
	                $uname = $this->sockets[(int)$socket]['username'];
	                $response['type'] = 'user';
	                $response['from'] = $uname;
	                $response['content'] = $msg_content;
	                $response['avatar'] = $this->sockets[(int)$socket]['avatar'];
	                break;
	            case 'changeAvatar':
	            	$this->sockets[(int)$socket]['avatar'] = $msg_content;
	            	$user_list = array_column($this->sockets,'username');
					$avatar_list = array_column($this->sockets,'avatar');
					$response['type'] = 'avatarChange';
					$response['content'] = '';
					$response['user_list'] = $user_list;
					$response['avatar_list'] = $avatar_list;
					break;
			}

			return $this->build(json_encode($response));
		}

		//向所有的client广播收到的信息
		private function boardcast($data)
		{
			foreach($this->sockets as $socket)
			{
				if($socket['resource'] == $this->master)
				{
					continue;
				}
				socket_write($socket['resource'],$data,strlen($data));
			}
		}

		//记录出错信息
		private function error($err)
		{
			$time = date('Y-m-d H:i:s');
			$msg = $time . '   Error type:' . $err[0] . '   error code:' . $err[1] . '   error msg:' . $err[2] . "\r\n"; 
			file_put_contents(self::LOG_PATH,$msg,FILE_APPEND);
		}

		//日志记录
		private function log($msg)
		{
			$time = date('Y-m-d H:i:s');
			$msg = $time . '   ' . $msg . "\r\n";
			file_put_contents(self::LOG_PATH,$msg,FILE_APPEND);
		}
	}
	$ws = new Server('127.0.0.1',8080);
 ?>