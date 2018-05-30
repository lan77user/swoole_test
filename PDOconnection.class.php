<?php

	/**
	 * PDO 每一次执行语句后就释放连接
	 * 在断开连接时会重新连接
	 * @Auther QiuXiangCheng
	 * @Date 2017/08/03
	 */
	class DB{

		private static $db;

		public static function connect($conf = []){

			try{ 
				echo "Created a new pdo connection.\n";
				if(count($conf) > 0){
					self::$db = new PDO('mysql:host=' . $conf['db_address'] . ';dbname=' . $conf['db_name'], $conf['db_uname'], $conf['db_pwd'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES'UTF8'"]);
				}else{
					self::$db = new PDO('mysql:host=host;dbname=test','user_name','password',[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES'UTF8'"]);
				}
			}catch (PDOException $e){
				echo "Cannot connect to mysql.\n";
			}
		}

		public static function query($sql){

			try{
				if(!($s = self::$db -> query($sql))){
					return false;
				}
				if($rel = $s -> fetchAll(PDO::FETCH_ASSOC)){
					return $rel;
				}
				return false;
			}catch(PDOException $e){
				self::reconnect($sql, '');
				echo 'restart ....' . "\n";
				return false;
			}
		}

		public static function getFields($fields, $sql = ''){

			if($sql == ''){
				return self::getOne($fields);
			}
			try{
				if(false == ($rel = self::$db -> query($sql))){
					return false;
				}
				if(!($tmp = $rel -> fetchAll(PDO::FETCH_ASSOC))){
					return false;
				}
			}catch(PDOException $e){
				self::reconnect($sql, '');
				return false;
			}
			$data = [];
			$f = array_map('trim', explode(',', $fields));
			if(count($f) == 1){
				foreach($tmp as $values){
					$data[] = $values[$f[0]];
				}
			}else if(count($f) == 2){
				foreach($tmp as $values){
					$data[$values[$f[0]]] = $values[$f[1]];
				}
			}else{
				return $tmp;
			}
			return $data;
		}

		/**
		 * 取得一个字段的值 返回字符串
		 */
		private static function getOne($sql, $handle = 'trim'){

			try{
				if(!($rel = self::$db -> query($sql))){
					return false;
				}
				if(!($tmp = $rel -> fetch(PDO::FETCH_ASSOC))){
					return false;
				}
			}catch(Exception $e){
				echo "Connection reseting...\n";
				self::reconnect($sql, '');
				return false;
			}
			if(isset($tmp))
				return $handle($tmp[key($tmp)]);
			return false;
		}

		/**
		 * 执行
		 */
		public static function exec($sql){

			try{
				$r = self::$db -> exec($sql);
				if(stripos($sql, 'insert')){
					return self::$db -> lastinsertid();
				}
			}catch(PDOException $e){
				self::reconnect($sql, '');
				return false;
			}
			return $r;
		}

		/**
		 * 重接
		 */
		private static function reconnect($sql = '', $type){

			self::$db -> setAttribute(PDO::ATTR_PERSISTENT, false);
			self::connect();
			switch($type){
				case 'exec':
					self::exec($sql);
					break;
				case 'query':
					self::query($sql);
					break;
				case 'getFields':
					self::getFields($sql);
					break;
				case 'getOne':
					self::getOne($sql);
					break;
			}
			return 0;
		}

		public static function close() {

			self::$db = null;
		}

		public function __destruct(){

			self::$db = null;
		}
	}