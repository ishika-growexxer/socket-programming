<?php
/**
 * WebSocket Chat Server (PHP 7.4+)
 * ---------------------------------
 * Broadcast messages between connected clients
 * Handles disconnects properly (no fatal errors)
 */

declare(strict_types=1);

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

class WebSocketServer
{
    private string $host;
    private int $port;
    private $serverSocket;
    private array $clients = [];
    private int $nextId = 1;

    public function __construct(string $host = '0.0.0.0', int $port = 8080)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function start(): void
    {
        $addr = sprintf('tcp://%s:%d', $this->host, $this->port);
        $this->serverSocket = @stream_socket_server($addr, $errno, $errstr);

        if ($this->serverSocket === false) {
            fwrite(STDERR, "❌ Failed to create server: $errstr ($errno)\n");
            exit(1);
        }

        stream_set_blocking($this->serverSocket, false);
        fwrite(STDOUT, "✅ WebSocket Chat Server running at ws://{$this->host}:{$this->port}\n");
        fwrite(STDOUT, "Open index.html and connect from same network\n\n");

        $this->mainLoop();
    }

    private function mainLoop(): void
    {
        while (true) {
            $read = [$this->serverSocket];
            foreach ($this->clients as $client) {
                $read[] = $client['socket'];
            }

            $write = $except = null;
            $numChanged = @stream_select($read, $write, $except, 1);
            if ($numChanged === false) continue;

            if (in_array($this->serverSocket, $read, true)) {
                $this->acceptClient();
                $read = array_diff($read, [$this->serverSocket]);
            }

            foreach ($read as $sock) {
                $this->handleClientMessage($sock);
            }
        }
    }

    private function acceptClient(): void
    {
        $sock = @stream_socket_accept($this->serverSocket, 0);
        if ($sock === false) return;

        stream_set_blocking($sock, false);
        $id = $this->sockId($sock);
        $this->clients[$id] = [
            'socket'     => $sock,
            'handshaked' => false,
            'name'       => "User{$this->nextId}",
            'buffer'     => ''
        ];
        $this->nextId++;
    }

    private function handleClientMessage($sock): void
    {
        $id = $this->sockId($sock);
        if (!isset($this->clients[$id])) return;

        // ✅ Ensure chunk is always string
        $chunk = @fread($sock, 8192);
        if ($chunk === '' || $chunk === false || $chunk === null) {
            $this->disconnectClient($id);
            return;
        }

        if (!$this->clients[$id]['handshaked']) {
            $this->performHandshake($id, $chunk);
            return;
        }

        $this->clients[$id]['buffer'] .= $chunk;
        while ($this->clients[$id]['buffer'] !== '') {
            [$payload, $remaining] = $this->unframe($this->clients[$id]['buffer']);
            if ($payload === '' && $remaining === $this->clients[$id]['buffer']) break;

            $this->clients[$id]['buffer'] = $remaining;
            $this->processPayload($id, trim($payload));
        }
    }

    private function disconnectClient(int $id): void
    {
        if (!isset($this->clients[$id])) return;
        $name = $this->clients[$id]['name'];
        @fclose($this->clients[$id]['socket']);
        unset($this->clients[$id]);
        $this->broadcast("🔴 $name left the chat");
    }

    private function performHandshake(int $id, string $request): void
    {
        if (strpos($request, "\r\n\r\n") === false) return;

        if (!preg_match('/Sec-WebSocket-Key:\s*(.*)\r?\n/i', $request, $m)) {
            $this->disconnectClient($id);
            return;
        }

        $key = trim($m[1]);
        $accept = $this->generateAcceptKey($key);
        $response = "HTTP/1.1 101 Switching Protocols\r\n" .
                    "Upgrade: websocket\r\n" .
                    "Connection: Upgrade\r\n" .
                    "Sec-WebSocket-Accept: $accept\r\n\r\n";

        @fwrite($this->clients[$id]['socket'], $response);
        $this->clients[$id]['handshaked'] = true;

        $name = $this->clients[$id]['name'];
        $this->broadcast("🟢 $name joined the chat");
    }

    private function processPayload(int $id, string $msg): void
    {
        if ($msg === '') return;

        if ($msg[0] === '/') {
            if (str_starts_with($msg, '/name ')) {
                $new = trim(substr($msg, 6));
                if ($new !== '') {
                    $old = $this->clients[$id]['name'];
                    $this->clients[$id]['name'] = $new;
                    $this->broadcast("✏️ $old is now known as $new");
                }
            } elseif (str_starts_with($msg, '/disconnect')) {
                $this->disconnectClient($id);
            }
            return;
        }

        $name = $this->clients[$id]['name'];
        $this->broadcast("[$name] $msg");
    }

    private function broadcast(string $message, ?int $excludeId = null): void
    {
        $frame = $this->frame($message);
        foreach ($this->clients as $id => $client) {
            if (!$client['handshaked']) continue;
            if ($excludeId !== null && $id === $excludeId) continue;
            @fwrite($client['socket'], $frame);
        }
    }

    private function sockId($sock): int { return (int)$sock; }

    private function generateAcceptKey(string $clientKey): string
    {
        $GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
        return base64_encode(sha1($clientKey . $GUID, true));
    }

    private function frame(string $payload, bool $binary = false): string
    {
        $fin = 0x80;
        $opcode = $binary ? 0x2 : 0x1;
        $head = chr($fin | $opcode);

        $len = strlen($payload);
        if ($len <= 125) {
            $head .= chr($len);
        } elseif ($len <= 65535) {
            $head .= chr(126) . pack('n', $len);
        } else {
            $head .= chr(127) . pack('J', $len);
        }
        return $head . $payload;
    }

    private function unframe(?string $data): array // ✅ made nullable
    {
        if ($data === null) return ['', ''];

        $len = strlen($data);
        if ($len < 2) return ['', $data];

        $b1 = ord($data[0]);
        $b2 = ord($data[1]);
        $masked = ($b2 & 0x80) === 0x80;
        $payloadLen = ($b2 & 0x7F);
        $offset = 2;

        if ($payloadLen === 126) {
            if ($len < $offset + 2) return ['', $data];
            $payloadLen = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
        } elseif ($payloadLen === 127) {
            if ($len < $offset + 8) return ['', $data];
            $payloadLen = unpack('J', substr($data, $offset, 8))[1];
            $offset += 8;
        }

        $mask = $masked ? substr($data, $offset, 4) : "\x00\x00\x00\x00";
        if ($masked) $offset += 4;

        if ($len < $offset + $payloadLen) return ['', $data];

        $payload = substr($data, $offset, $payloadLen);
        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < $payloadLen; $i++) {
                $unmasked .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $unmasked;
        }

        $frameLen = $offset + $payloadLen;
        return [$payload, substr($data, $frameLen)];
    }
}

$host = $argv[1] ?? '0.0.0.0';
$port = isset($argv[2]) ? (int)$argv[2] : 8080;

$server = new WebSocketServer($host, $port);
$server->start();
