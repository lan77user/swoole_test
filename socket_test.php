<?php

    //  线上给伟衍的测试程序，这个代码放在/data/socket/socket/swoole/test文件夹
    include '/data/socket/socket/swoole/test/PDOconnection.class.php';   //  华南服务器
    // include '/socket/PDOconnection.class.php';   //  本地服务器
    // 给伟衍的监测设备主动发命令
    class Server{

        /**
         * redis服务
         */
        private static $redis = false;

        /**
         * 默认监听地址
         */
        const LISTENING_ADDRESS = '0.0.0.0';

        const DATE = '20171129';

        /**
         * 特定的下行命令的生存时间
         * 在此时间范围内 相同的来自前端的上行命令将不再重复执行
         * 在此时间范围内 设备的上行控制命令将被正确执行（如果SEQ正确）
         * 如果设备回复 则本下行命令会被提前消除
         */
        const COMMAND_SURVIVAL_TIME = 120;

        //  每5秒侦测一次心跳
        const HEARTBEAT_CHECK_INTERVAL = 5;

        //  超过50秒设备没回复就断开
        const HEARTBEAT_IDLE_TIME = 20;

        /**
         * 监测设备要回复的几种命令和参数集合
         */
        public $MONITOR_COMMOND = [
            0 => ['SP' => '60,120,180,240,300'],
            1 => ['SV' => '0,1'],
            // 2 => ['UD' => 'http://down.nongbotech.cn/Application.hex|TestVer'],
        ];

        // CDiveceID:ControlName|Arguments,Seq\n
        public $CONTROLLER = [
            0 => ['name' => 'SP','arg' => ['60','120','180','240','300']],
            1 => ['name' => 'SV','arg' => ['0,1']],
            // 2 => ['name' => 'UD','arg' => ['http://down.nongbotech.cn/Application.hex|TestVer','http://down.nongbotech.cn/Application.hex|Ver2']],
            // 3 => ['name' => 'IP','arg'  => ['192.168.1.126|1209','192.168.1.126|1208']],
        ];


        public function __construct() {

            $this -> server = new swoole_server('0.0.0.0', 1208, SWOOLE_BASE, SWOOLE_SOCK_TCP);
            $this -> server -> on('connect',[$this,'onConnect']);
            $this -> server -> on('receive',[$this,'onReceive']);
            $this -> server -> on('close',[$this,'Close']);

            $this -> server -> set([
                'heartbeat_check_interval' => self::HEARTBEAT_CHECK_INTERVAL,
                'heartbeat_idle_time' => self::HEARTBEAT_IDLE_TIME,
                'max_conn' => 100,
                'max_request' => 20,
                'daemonize' => 1
            ]);

            $config = [
                'db_address' => 'ip',
                'db_name' => 'dbname',
                'db_uname' => 'user_name',
                'db_pwd' => 'password',
            ];
            db::connect($config);

            // 后端监听
            // $this -> server -> listen(self::LISTENING_ADDRESS, 1209, SWOOLE_SOCK_TCP) -> set(['package_max_length' => 2046]);

            $this -> server -> start();
        }


        /**
         * redis单例
         */
        private static function redisInstance(){

            if(!(self::$redis instanceof redis)){
                self::$redis = new redis;
                self::$redis -> connect(self::LISTENING_ADDRESS, 6366);
            }
            return self::$redis;
        }


        // 支持硬件和网页连接
        public  function onConnect($server,$fd,$from_id) {

            echo $fd . '号用户连上拉' . PHP_EOL;
        }



        /**
         * 判断是监测设备发来数据还是控制设备登陆
         */
        public function onReceive($server, $fd, $reactor_id, $data) {

            $data = trim(str_replace("\r\n", "\n", $data));
            $colon = strpos($data, ':');        //  通过冒号判断是控制发来的命令还是监测发来的命令
            $check = self::dataStyleCheck($data, $dataList);
            $device_sn = substr($data, 1,strpos($data, ':') - 1);
            if($colon > 0) {       //  控制设备

                //  登记设备登录命令,一登录就解析各个口状态并不断发送控制命令
                if($check){
                
                    if(substr($data, 0,1) == 'D') {

                        $device_sn = substr($data, 1,strpos($data, ':') - 1);
                        $command = substr($data, 0,strpos($data, ':'));
                        $count = $this -> get($command); //  登录次数
                        
                        $portStatus = $this -> getPortStatus(substr($data, -1));

                        if(!$count || $count >= (strlen($portStatus) / 2)){
                            $this -> set($command,1);
                        }else{
                            self::redisInstance() -> incr($command);
                        }

                        $this -> setFD($fd,$device_sn);   //  登记设备
                        $this -> controlDevice($fd,$data);
                    }else{
                        $this -> exec('INSERT INTO nb_control_log (device_sn,device_type,command,status,remark) VALUES ("' . $device_sn . '","2","' . $data . '","8","控制设备发来的错误命令")');
                    }
                }else if(!$check){     //  回复命令和上报命令,只需要记录日志即可

                    $this -> receiveCommand($fd,$data,$dataList);
                    
                }
            }else {    //  监测设备发送数据
                $this -> monitor($fd,$data);
            }
        }

// -------------------------------监测设备开始--------------------------

        // 监测设备格式分解
        public function monitor($fd,$data = '') {

            // $data = 'D17081TEST001,stream=nongbo,data=02050000000000005f000000de5a0401a501,seq=2003' . "\n" . 'n=UD,seq=2001,state=6' . "\n";
            // $data = 'D17081TEST001,stream=nongbo,data=02050000000000005f000000de5a0401a501,seq=2001' . "\n";
            if(!empty($data) && $this -> monitorCheck($data,$dataList)) {

                $this -> exec('INSERT INTO nb_monitor_log (device_sn,command,remark) VALUES ("' . $dataList['device_sn'] . '","' . $data . '","监测设备发来的正常命令")');
                $this -> setFD($fd,$dataList['device_sn']);
                $command = $this -> createMonitorCommand($dataList);
                if($command) {
                    // sleep(1);       //  延时一秒发送命令
                    $this -> server -> send($fd,$command);
                    // $this -> server -> after(1000,$this -> server -> send($fd,$command));
                }
                $this -> exec('INSERT INTO nb_monitor_log (device_sn,command,remark) VALUES ("' . $dataList['device_sn'] . '","' . $command . '","发送给监测设备的命令")');
            }else{
                $arr = explode(',', $data);
                $device_sn = empty(substr($arr[0], 1)) ? '' : substr($arr[0], 1);
                $this -> exec('INSERT INTO nb_monitor_log (device_sn,command,remark) VALUES ("' . $device_sn . '","' . $data . '","监测设备发来的错误命令")');
                $command = 'Wrong Command';
                $this -> server -> send($fd,$command);
            }
        }


        //  监测设备控制格式分解
        private static function monitorCheck($data,&$dataList) {

            if(!strpos($data, 'stream=') || !strpos($data, 'data=') || !strpos($data, 'seq=')){
                return 0;
            }
            $data = str_replace(' ', '', $data);
            $arr = array_map('trim', explode("\n", $data));
            foreach ($arr as $k => $v) {
                 if(empty($v)){
                   unset($arr[$k]); 
                 } 
            }            
            
            if(count($arr) <= 2){  // 正常的回复命令，包括发送数据过来和回复
                //  设备发来的传感器信息
                $info = array_map('trim',explode(',', $arr[0]));
                
                $dataList = array(
                    'device_sn' => substr($info[0], 1),
                    'seq' => substr($info[3], strpos($info[3], '=') + 1),
                    'streamName' => substr($info[1], strpos($info[1], '=') + 1),
                    'command' => $data,
                );
                if(count($arr) == 2) { //  说明设备有回复上一条命令的执行状态

                    $reply = array_map('trim',explode(',', $arr[1]));
                    $dataList['reply']['n'] = substr($reply[0], 2);
                    $dataList['reply']['seq'] = substr($reply[1], 4);
                    $dataList['reply']['state'] = substr($reply[2], 6);
                }
            }else{
                return 0;
            }
            
            return 1;
        }


        // 生成要发送给监测设备的命令
        public function createMonitorCommand($dataList){
            
            $n = 'UD';   // 命令名称
            // $a = 'http://down.nongbotech.cn/Pro2Application.hex|pro2_v3_170930';     // 命令的参数
            $a = 'http://119.28.21.53:908/Pro2Application.hex|pro2_v3_170930';     // 命令的参数
           
            if(isset($dataList['reply'])) {  //  这里有回复上一条命令
                $state = $dataList['reply']['state']; //  命令的执行状态
                // state=0 //执行成功,只回复上报数据的部分
                // state=1 //执行失败,需要重发命令
                // state=2 //不允许执行
                // state=3 //命令参数错误
                // state=4 //命令错误，不支持命令
                // state=5 //上次已经执行过此命令
                // state=6 //其它错误
                if($state == 0){ 
                    $command = '$ack@' . $dataList['device_sn'] . ':$E=' . $dataList['streamName'] . ',$seq=' . $dataList['seq'] . "\n";
                    $res = $this -> getCommandBySeq($dataList['reply']['seq']);
                    if($res[0]['status'] == 0){
                        $this -> exec('UPDATE nb_send_command SET status = 1 WHERE seq = "' . $dataList['reply']['seq'] . '" AND id = ' . $res[0]['id']);
                    }

                }else if($state == 1){   //  这里注意要重发
                    $res = $this -> getCommandBySeq($dataList['reply']['seq'],$dataList['device_sn']);
                    $command = $res[0]['command'];
                }else{
                    $this -> exec('UPDATE nb_send_command SET status = ' . $state . ' WHERE seq = "' . $dataList['reply']['seq'] . '" AND control_type = ' . $dataList['reply']['n']);
                    $command = '$ack@' . $dataList['device_sn'] . ':$E=' . $dataList['streamName'] . ',$seq=' . $dataList['seq'] . "\n";
                }

            }else {  //  这里仅仅发来了监测数据,所以要开始下发控制命令

                //  控制命令的seq不要随机，要按设备递增1
                $count = $this -> query('SELECT COUNT(*) c FROM nb_send_command WHERE device_sn = "' . $dataList['device_sn'] . '"');
                $seq = $count[0]['c'] + 1;
                $control = 'C' . $dataList['device_sn'] . ':seq=' . $seq . ',n=' . $n . ',a=' . $a;

                $this -> query('INSERT INTO nb_send_command (device_sn,command,seq,control_type) VALUES ("' . $dataList['device_sn'] . '","' . $control . '","' . $seq . '","' . $n . '")');
                $reply = '$ack@' . $dataList['device_sn'] . ':$E=' . $dataList['streamName'] . ',$seq=' . $dataList['seq'] . "\n";
                $command = $control . $reply;
            }
            return $command;
            
        }


        /**
         * 生成监测设备的redis队列,如果已经有了此命令，则不生成
         * TC1709112CTRL789-2-3-0
         * @return intval
         */
        private function getCommandBySeq($seq,$device_sn){

            return $this -> query('SELECT id,command,status FROM nb_send_command WHERE seq = ' . $seq . ' AND device_sn = "' . $device_sn . '"');
        }



        /**
         * 生成监测设备控制命令的时候，得到一个seq
         * @return intval
         */
        private function getMonitorSeq($command, &$seq){

            if(false == ($seq = self::redisInstance() -> get($command))){
                $seq = mt_rand(1000, 9999);
                $time = explode(' ', microtime());
                $realTime = $time[1]+$time[0];
                $this -> set($command,$seq);
                return 1;
            }
            return 0;
        }

// -----------------------------监测设备结束-----------------------------




// -----------------------------控制设备开始-----------------------------
        /**
         * 上行的回复命令和上报命令(不包含登录),验证数据合法性
         */
        private static function dataStyleCheck($data, &$dataInList, $len = 4){

            if(!strpos($data, ',') || !strpos($data, ':') || !strpos($data, '|')){
                return 1;
            }
            $data = str_replace(' ', '', $data);
            $dataInList = array_map('trim', explode(',', $data));
            if(count($dataInList) < $len) return 1;
            if(!is_numeric($dataInList[1])) return 1;
            if(!is_numeric($dataInList[2])) return 1;
            if(!is_numeric($dataInList[3])) return 1;
            return 0;
        }


        // // 收到登录命令,发送一些控制命令出去
        // public function controlDevice($fd,$data) {

        //     $start = strpos($data, ':');
        //     $command = substr($data, 0,$start);
        //     $device_sn = substr($data, 1,$start - 1);
        //     $mode = substr($data, $start + 1,1);
        //     $portStatus = $this -> getPortStatus(substr($data, -1));
            
        //     $a = 'UD';            //  控制命令名称
        //     $n = 'http://down.nongbotech.cn/Pro2Application.hex|pro2_v3_170930';           //  控制命令参数
        //     $command = 'C' . $device_sn . ':' . $a . '|' . $n;
        //     // $tenAM = strtotime(date('Ymd 10:30:00'));
        //     // $fourPM = strtotime(date('Ymd 16:00:00'));

        //     // //  每天早上10：30-16：00发送普通的开关控制命令
        //     // if($tenAM < time() && time() < $fourPM) {   
        //     //     $count = self::get($command);
        //     //     $port = $count * 2 - 1;   //  要操作的口
        //     //     $node = substr($portStatus, $port - 1,1);
        //     //     $node == '0' ? $controlName = '1' : $controlName = '2';
        //     //     $command = 'C' . $device_sn . ':' . $controlName . '|' . $port . '|0';
        //     // }else {
        //     //     $command = 'C' . $device_sn . ':SP|200';
        //     // }

        //     $this -> exec('INSERT INTO nb_control_log (device_sn,device_type,command,status,remark) VALUES ("' . $device_sn . '","2","' . $data .  '","8","控制设备设备登录")');
        //     $this -> fetchNewTCcommand($command,$seq);
        //     $seq = substr($seq, 0,4);
        //     $command = $command . ',' . $seq . "\n";
        //     $this -> server -> send($fd, $command);
            
        // }


        // /**
        //  * 收到控制设备的命令
        //  * @author Lan77 2018-01-17
        //  */
        // public function receiveCommand($fd,$data,$dataList){

        //     //  判断设备回复的这条命令是否在redis里存在
        //     //  判断头字母是C还是F获得回复状态ReplyState
        //     $head = substr($dataList[0], 0,1);
        //     $head == 'C' ? $replyState = $dataList[2] : $replyState = $dataList[3];

        //     $command = $dataList[0];
        //     $seq = $dataList[1];
            
        //     //  获得命令类型
        //     $start = strpos($command, ':') + 1;
        //     $line = strpos($command, '|');
        //     $commandType = substr($command, $start,$line - $start);
        //     $device_sn = substr($command, 1,$start - 2);
        //     $arg = substr($command, $line + 1);

        //     //  发送命令的时间
        //     $sendTime = substr($this -> get($command),strpos($this -> get($command), '_') + 1);
        //     //  如果设备回复的命令是服务器之前发出去的
        //     if(self::isTCcommandSuitable($command,$seq)){

        //         //  获得回复用时
        //         $getTime = explode(' ',microtime());
        //         $receiveTime = $getTime[1] + $getTime[0];
        //         $usedTime = $receiveTime - $sendTime;

        //         //  记录已回复的命令的设备
        //         $this -> set('REPLY_' . $fd,$fd);
        //         $this -> exec('INSERT INTO nb_control_log (device_sn,device_type,command,control_name,arg,status,used_time,remark) VALUES ("' . $device_sn . '","2","' . $command . '","' . $commandType . '","' . $arg . '","' . $replyState . '","' . $usedTime . '","控制设备设备正常回复")');
        //     }else{
        //         $this -> exec('INSERT INTO nb_control_log (device_sn,device_type,command,control_name,arg,status,remark) VALUES ("' . $device_sn . '","2","' . $data . '","' . $commandType . '","' . $arg . '","' . $replyState . '","控制设备设备主动回复")');
        //     }
        // }

        //  连接数据库并执行sql语句
        private function query($sql) {

            $res = db::query($sql);
            if($res) {
                return $res;
            }else {
                return [];
            }
        }


        //  连接数据库并执行sql语句
        private function exec($sql) {

            db::exec($sql);
            return 1;
        }

// --------------------------------控制设备结束----------------------------
// -----------------------------设置redis相关-----------------------------

        //  记住硬件设备的fd号码,记住设备是否在线
        private function setFD($fd,$device){
            return self::redisInstance() -> set(intval($fd),$device);
        }  

        //  设置某个key
        private function set($key,$val){
            return self::redisInstance() -> set($key,$val);
        } 


        //  获得某个key
        private function get($key){
            return self::redisInstance() -> get($key);
        } 


        //  获得某个key,模糊匹配
        private function keys($key){
            return self::redisInstance() -> keys($key);
        }

        //  删除某个key
        private function del($key){
            return self::redisInstance() -> delete($key);
        } 

        //  获得redis所有
        private function getAll(){
            return self::redisInstance() -> keys('*');
        } 


        //  删掉redis所有
        private function flush(){
            return self::redisInstance() -> flushall();
        }


        /**
         * 判断本次命令是否可执行 如果可执行 那么记录本次命令
         * TC1709112CTRL789-2-3-0
         * @return intval
         */
        private function fetchNewTCcommand($TCcommand, &$seq){

            if(false == ($seq = self::redisInstance() -> get($TCcommand))){
                $seq = mt_rand(1000, 9999);
                $time = explode(' ', microtime());
                $realTime = $time[1]+$time[0];
                $this -> set($TCcommand,$seq . '_' . $realTime);
                return 1;
            }
            return 0;
        }


        /**
         * 检查当前命令下是否存在可用队列,有此命令，return true，没有命令，return false
         */
        private function isTCcommandSuitable($TCcommand, $deviceSeq){

            $getCommand = $this -> get($TCcommand);
            $seq = substr($getCommand,0,4);
            if(false != $seq){
                // echo $seq . '-' . $deviceSeq . "\n";
                if($seq != $deviceSeq){
                    return false;
                }
                // 使设备只能回复一次本条命令
                self::redisInstance() -> delete($TCcommand);
                return true;
            }
            return false;
        }

// -------------------------------redis结束-------------------------------

        //  获得设备各个口的情况
        private function getPortStatus($num) {

            $status = strrev(sprintf("%04d",decbin($num)));
            return $status;
        }


        public function sendCommandToDevice($fd,$command){
            $this -> server -> send($fd,$command);
            return true;
        }


        //  客户端断开或者服务器主动关闭时候触发
        public function Close($server,$fd,$reactorId) {

            echo $fd . 'Close' . PHP_EOL;
            $device_sn = self::get($fd);
            self::del($fd);   

            //  只有客户端主动断开的连接才记录,超时和自己主动断开都算客户的动作
            if($reactorId >= 0) {

                // 没有回复就直接断开的设备
                if(self::get('REPLY_' . $fd) == false){

                    //  找出这个设备的所有命令，全部删除
                    $command = $this -> keys('C' . $device_sn . '*');
                    $value = '';
                    
                    foreach ($command as $k => $v) {
                        $start = strpos($v, ':') + 1;
                        $line = strpos($v,'|');

                        $commandType = substr($v, $start,$line - $start);
                        $arg = substr($v, $line + 1);
                        $value .= '("' . $device_sn . '",2,"' . $v . '","' . $commandType . '","' . $arg . '","8","设备未回复命令,主动断开"),';
                        $this -> del($v);
                    }
                    $value = rtrim($value,',');
                    $this -> exec('INSERT INTO nb_control_log (device_sn,device_type,command,control_name,arg,status,remark) VALUES ' . $value);
                }else{     
                    //  回复完命令后设备主动断开
                    $this -> del('REPLY_' . $fd);
                    $this -> exec('INSERT INTO nb_control_log (device_sn,device_type,status,remark) VALUES ("' . $device_sn . '","2","8","设备已回复命令,主动断开")');
                }
            } 
            
            
        }



        public function getEqual($str) {
            return (strpos($str, '=') + 1);
        }
    }

    $server = new Server();


