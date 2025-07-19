<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/counter.class.php';

function unmask($payload) {
  $length = ord($payload[1]) & 127;
  if ($length === 126) {
    $masks = substr($payload, 4, 4);
    $data = substr($payload, 8);
  } elseif ($length === 127) {
    $masks = substr($payload, 10, 4);
    $data = substr($payload, 14);
  } else {
    $masks = substr($payload, 2, 4);
    $data = substr($payload, 6);
  }

  $decoded = '';
  for ($i = 0; $i < strlen($data); ++$i) {
    $decoded .= $data[$i] ^ $masks[$i % 4];
  }
  return $decoded;
}

function mask($text) {
  $b1 = 0x81;
  $length = strlen($text);

  if ($length <= 125) {
    $header = pack('CC', $b1, $length);
  } elseif ($length <= 65535) {
    $header = pack('CCn', $b1, 126, $length);
  } else {
    $header = pack('CCNN', $b1, 127, 0, $length);
  }

  return $header . $text;
}

$server = stream_socket_server("tcp://0.0.0.0:8080", $errno, $errstr);
if (!$server) die("Failed: $errstr ($errno)\n");

echo "🟢 WebSocket Server running on ws://localhost:8080\n";

$clients = [];
$counter = new Counter(db_connection: $db_connection, cache_db_connection: $cache_db_connection);

while (true) {
  $read = $clients;
  $read[] = $server;
  $write = $except = [];
  
  if (stream_select($read, $write, $except, 2, 200000)) {
    if (in_array($server, $read)) {
      $conn = stream_socket_accept($server);
      $headers = fread($conn, 1024);

      if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $headers, $matches)) {
        $key = trim($matches[1]);
        $acceptKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $upgrade = "HTTP/1.1 101 Switching Protocols\r\n"
          . "Upgrade: websocket\r\n"
          . "Connection: Upgrade\r\n"
          . "Sec-WebSocket-Accept: $acceptKey\r\n\r\n";

        fwrite($conn, $upgrade);
        $clients[] = $conn;

        // 🔥 Send current counter value to newly connected client
        $value = $counter->get(); // <-- You need to implement this method if not yet
        $msg = json_encode(['counter' => number_format($value)]);
        fwrite($conn, mask($msg));
      }

      unset($read[array_search($server, $read)]);
    }

    foreach ($read as $client) {
      $data = @fread($client, 1024);
      if (!$data) {
        fclose($client);
        unset($clients[array_search($client, $clients)]);
        continue;
      }

      $message = unmask($data);
      if (trim($message) === 'increment') {
        $value = $counter->increment();
        $msg = json_encode(['counter' => number_format($value)]);

        $payload = mask($msg);
        foreach ($clients as $client) {
          @fwrite($client, $payload);
        }
      }
    }
  }
}
