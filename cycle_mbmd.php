<?php

chdir(dirname(__FILE__) . '/../');

include_once('./config.php');
include_once('./lib/loader.php');
include_once('./lib/threads.php');

set_time_limit(0);

$db = new mysql(DB_HOST, '', DB_USER, DB_PASSWORD, DB_NAME);

include_once('./load_settings.php');
include_once(DIR_MODULES . 'control_modules/control_modules.class.php');

$ctl = new control_modules();

include_once('./lib/mbmd.class.php');

echo date('H:i:s') . ' Running ' . basename(__FILE__) . PHP_EOL;

$latest_ping         =  0;
$latest_cycle_check  =  0;
$cycle_check_period  =  25;

$ws_ping_period      =  60;
$tcp_ping_period     =  20;
$cycle_debug         =  false;
$debmes_debug        =  false;
$extended_logging    =  false;

echo date('H:i:s') . ' Init MBMD cycle' . PHP_EOL;
echo date('H:i:s') . ' Cycle debug - ' . ($cycle_debug ? 'yes' : 'no') . PHP_EOL;
echo date('H:i:s') . ' DebMes debug - ' . ($debmes_debug ? 'yes' : 'no') . PHP_EOL;
echo date('H:i:s') . ' Extended debug - ' . ($extended_logging ? 'yes' : 'no') . PHP_EOL;
echo date('H:i:s') . " Ping period - $tcp_ping_period seconds" . PHP_EOL;
echo date('H:i:s') . " Websocket check period - $ws_ping_period seconds" . PHP_EOL;

// Создаем управляющий сокет, который будет принимать команды от МДМ.
$controlSocket = stream_socket_server('tcp://127.0.0.1:3015', $errno, $errstr);
if ($controlSocket === false) {
   echo date('H:i:s') . ' Control socket - FAILED' . PHP_EOL;
   echo date('H:i:s') . " Connect error: $errno $errstr" . PHP_EOL;
   $db->Disconnect();
   exit;
} else {
   echo date('H:i:s') . ' Control socket - OK' . PHP_EOL;
}

$tvList = [
   '1' => [
      'ID' => '1',
      'IP' => '127.0.0.1',
      'SOCKET' => new mbmdSocket('127.0.0.1', 8080, $cycle_debug)
   ]
];

while (1) {

   if ($extended_logging) {
      if ($cycle_debug) echo date('H:i:s') . ' Cycle start' . PHP_EOL;
   }

   if ((time() - $latest_cycle_check) >= $cycle_check_period) {
      // Сообщаем МДМ, что цикл живой.
      $latest_cycle_check = time();
      setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
   }

   if ((time() - $latest_ping) >= $tcp_ping_period) {
      if (!empty($tvList)) {
         if ($cycle_debug) echo date('H:i:s') . ' Periodic socket availability check.' . PHP_EOL;
         foreach ($tvList as $tv) {
            if ($cycle_debug) echo date('H:i:s') . " Checking {$tv['IP']} [ID{$tv['ID']}] (tcp ping)." . PHP_EOL;
            $tv['SOCKET']->Ping();
         }
      }
      $latest_ping = time();
   }

   $ar_read = null;
   $ar_write = null;
   $ar_ex = null;

   $ar_read[] = $controlSocket;

   if (!empty($tvList)) {
      foreach ($tvList as $tv) {

         if ($tv['SOCKET']->IsOffline()) {
            if ($extended_logging) {
               if ($cycle_debug) echo date('H:i:s') . ' TV ' . $tv['SOCKET']->GetIP() . ' socket status = ' . $tv['SOCKET']->GetStatus() . PHP_EOL;
            }
            continue;
         }

         if ($tv['SOCKET']->IsOnline()) {
            $sendTimeout = time() - $tv['SOCKET']->lastSendMsgTime;
            $rcvTimeout  = time() - $tv['SOCKET']->lastRcvMsgTime;
            //if (($sendTimeout >= $ws_ping_period) && ($rcvTimeout >= $ws_ping_period)) {
               //if ($cycle_debug) echo date('H:i:s') . ' Send websocket ping to ' . $tv['SOCKET']->GetIP() . PHP_EOL;
               //$tv['SOCKET']->WriteData('ping', true, 'ping');
            //} else if (($sendTimeout >= 5 && $sendTimeout < $ws_ping_period) && ($rcvTimeout > $ws_ping_period)) {
            if ($rcvTimeout > $ws_ping_period) {
               if ($cycle_debug) echo date('H:i:s') . ' Close connection on timeout ' . $tv['SOCKET']->GetIP() . PHP_EOL;
               $tv['SOCKET']->Disconnect();
               //$lgwebostv_module->IncomingMessageProcessing('{"type":"ws_close"}', $tv['ID']);
            }
         }

         if ($tv['SOCKET']->GetStatus() == 'DO_CONNECT') {
            // Заданию нужно инициировать соединение.
            // Коннект на 3000 порт ТВ и авторизация по протоколу websocket.
            $socket = $tv['SOCKET']->Connect();
            if ($socket) {
               // После соединения будем пытаться авторизоваться в WS (писать в сокет).
               $ar_write[] = $socket;
            }
         } else if ($tv['SOCKET']->GetStatus() == 'READ_ANSWER') {
            // Задание хочет прочитать ответ из сокета.
            // Получаем ответ на отправленную команду или просто слушаем сообщения от ТВ.
            $socket = $tv['SOCKET']->GetSocket();
            if ($socket) {
               $ar_read[] = $socket;
            }
         } else if ($tv['SOCKET']->GetStatus() == 'WRITE_REQUEST') {
            // Заданию нужно записать запрос в сокет.
            // Отправляем команду на ТВ.
            $socket = $tv['SOCKET']->GetSocket();
            if ($socket) {
               $ar_write[] = $socket;
            }
         }
         if ($extended_logging) {
            if ($cycle_debug) echo date('H:i:s') . ' TV ' . $tv['SOCKET']->GetIP() . ' socket status = |SS:' . $tv['SOCKET']->GetStatus() . '|ST:' . $sendTimeout . '|RT:' . $rcvTimeout . '|' . PHP_EOL;
         }
      }
   }

   if ($extended_logging) {
      if ($cycle_debug) echo date('H:i:s') . ' Start stream_select()' . PHP_EOL;
   }
   // Магия сокетов! :) Ждем, когда ядро ОС нас уведомит о событии, или делаем дежурную итерацию раз в 5 сек.
   if (($num_changed_streams = stream_select($ar_read, $ar_write, $ar_ex, 5)) === false) {
      echo date('H:i:s') . ' Error stream_select()' . PHP_EOL;
   }

   if ($extended_logging) {
      if ($cycle_debug) echo date('H:i:s') . ' Stop stream_select()' . PHP_EOL;
   }

   if (is_array($ar_ex)) {
      echo date('H:i:s') . ' Exeption in one of the sockets' . PHP_EOL;
   }

   // Есть сокеты на запись.
   if (is_array($ar_write)) {
      foreach ($ar_write as $write_ready_socket) {
         // Выясняем, в какой сокет надо писать.
         foreach ($tvList as $tv) {
            if ($write_ready_socket == $tv['SOCKET']->GetSocket()) {
               // Пишем.
               $tv['SOCKET']->WriteHandle();
            }
         }
      }
   }

   // Есть сокеты на чтение.
   if (is_array($ar_read)) {
      foreach ($ar_read as $read_ready_socket) {
         // Пришло соединение на управляющий сокет.
         if ($read_ready_socket == $controlSocket) {
            // Принимаем соединение.
            $csocket = stream_socket_accept($controlSocket);
            // Получаем данные.
            // Тут упрощение - верим локальному клиенту, что он закроет соединение. Иначе надо ставить таймаут.
            if ($csocket) {
               $req = '';
               while (($data = fread($csocket, 8192)) !== '' ) {
                  $req .= $data;
               }
               // Получили данные.
               if ($cycle_debug) echo date('H:i:s') . ' Command from MDM module: ' . trim($req) . PHP_EOL;
               // Передаем полученные данные на обработку и исполнение.
               $lgwebostv_module->ProcessControlCommand(trim($req), $csocket, $tvList, $cycle_debug);
               // Закрываем надежно соединение с управляющим сокетом.
               stream_socket_shutdown($csocket, STREAM_SHUT_RDWR);
               fclose($csocket);
            }
            continue;
         } else {
            // Выясняем, из какого сокета надо читать.
            foreach ($tvList as $tv) {
               if ($read_ready_socket == $tv['SOCKET']->getSocket()) {
                  // Читаем.
                  $msgs = $tv['SOCKET']->ReadHandle($tv['ID']);
                  // Отправляем на обработку в модуль.
                  if (is_array($msgs)) {
                     //echo date('H:i:s') . count($msgs) . PHP_EOL;
                     foreach ($msgs as $msg) {
                        //echo date('H:i:s') . $msg . PHP_EOL;
                        $data = json_decode($msg, true);
                        if (isset($data['Device'])) {
                           if ($data['IEC61850'] == 'Voltage' || $data['IEC61850'] == 'Current' || $data['IEC61850'] == 'Sum') {
                              $params[$data['IEC61850']] = round($data['Value'], 1);
                           }
                        }
                     }
                     if (isset($params)) {
                        //echo date('H:i:s') . ' params = ' . json_encode($params) . PHP_EOL;
                        callMethod('Count.mbmd', $params);
                     }
                  }
                  $msgs = null;
                  $params = null;
               }
            }
         }
      }
   }

   if ($extended_logging) {
      if ($cycle_debug) echo date('H:i:s') . ' Cycle end' . PHP_EOL;
   }

   if (file_exists('./reboot') || isset($_GET['onetime'])) {
      $db->Disconnect();
      // Закрываем все открытые соединения.
      if (!empty($tvList)) {
         foreach ($tvList as $tv) {
            if ($tv['SOCKET']->IsOnline()) {
               $tv['SOCKET']->Disconnect();
            }
         }
      }
      // Закрываем служебный сокет.
      if (is_resource($controlSocket)) {
         stream_socket_shutdown($controlSocket, STREAM_SHUT_RDWR);
         fclose($controlSocket);
      }
      echo date('H:i:s') . ' Stopping by command REBOOT or ONETIME ' . basename(__FILE__) . PHP_EOL;
      exit;
   }
   //sleep(1);
}

echo date('H:i:s') . ' Unexpected close of cycle' . PHP_EOL;

//DebMes('Unexpected close of cycle: ' . basename(__FILE__));
