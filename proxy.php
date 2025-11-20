
<?php

const CACHE_DIR = __DIR__ . '/cache';

$options = getopt("", ["port::", "origin::", "clear-cache"]);

if (isset($options['clear-cache'])) {
    clearCache();
    echo "Cache limpiada.\n";
    exit(0);
}

if (!isset($options['port']) || !isset($options['origin'])) {
    echo "Uso:\n";
    echo "  php proxy.php --port=8080 --origin=http://dummyjson.com\n";
    echo "  php proxy.php --clear-cache\n";
    exit(1);
}

$port = (int)$options['port'];
$originBase = rtrim($options['origin'], "/");

if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0777, true);
}

echo "Proxy en puerto {$port}, origin {$originBase}\n";

$server = stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr);

if (!$server) {
    echo "No se pudo iniciar el servidor: $errstr ($errno)\n";
    exit(1);
}

while ($client = @stream_socket_accept($server)) {
    handleClient($client, $originBase);
    fclose($client);
}



function handleClient($client, $originBase)
{
    $request = readHttpRequest($client);
    if (!$request) return;

    [$method, $uri, $protocol, $headers, $body] = $request;

    $originUrl = $originBase . $uri;

    $cacheKey = sha1($method . ":" . $originUrl);


    $cached = getFromCache($cacheKey);
    if ($cached) {
        $cached['headers']['X-Cache'] = 'HIT';
        sendHttpResponse($client, $cached['status'], $cached['headers'], $cached['body']);
        return;
    }

 
    $originResponse = fetchFromOrigin($originUrl);
    if (!$originResponse) {
        sendHttpResponse($client, "HTTP/1.1 502 Bad Gateway", [], "Error desde origin");
        return;
    }

    [$statusLine, $respHeaders, $respBody] = $originResponse;

    saveToCache($cacheKey, $statusLine, $respHeaders, $respBody);


    $respHeaders['X-Cache'] = 'MISS';
    sendHttpResponse($client, $statusLine, $respHeaders, $respBody);
}

function readHttpRequest($client)
{
    $data = '';
    while (!str_contains($data, "\r\n\r\n")) {
        $chunk = fread($client, 1024);
        if ($chunk === false || $chunk === '') break;
        $data .= $chunk;
    }

    $lines = explode("\r\n", $data);
    $requestLine = array_shift($lines);

    if (!$requestLine) return null;

    [$method, $uri, $protocol] = explode(" ", $requestLine);

    $headers = [];
    foreach ($lines as $line) {
        if ($line === '') break;
        if (strpos($line, ":") !== false) {
            [$name, $value] = explode(":", $line, 2);
            $headers[trim($name)] = trim($value);
        }
    }

    return [$method, $uri, $protocol, $headers, ""];
}

function fetchFromOrigin($url)
{
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);

    $response = curl_exec($ch);

    if ($response === false) return null;

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    $headerRaw = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    $headers = [];
    $statusLine = "";

    foreach (explode("\r\n", $headerRaw) as $i => $line) {
        if ($i === 0) {
            $statusLine = $line;
        } elseif (strpos($line, ":") !== false) {
            [$name, $value] = explode(":", $line, 2);
            $headers[$name] = trim($value);
        }
    }

    curl_close($ch);

    return [$statusLine, $headers, $body];
}

function saveToCache($key, $status, $headers, $body)
{
    $data = [
        "status" => $status,
        "headers" => $headers,
        "body" => $body
    ];

    file_put_contents(CACHE_DIR . "/$key.json", json_encode($data));
}

function getFromCache($key)
{
    $file = CACHE_DIR . "/$key.json";

    if (!file_exists($file)) return null;

    $data = json_decode(file_get_contents($file), true);

    return $data;
}

function clearCache()
{
    foreach (glob(CACHE_DIR . "/*.json") as $file) {
        unlink($file);
    }
}

function sendHttpResponse($client, $status, $headers, $body)
{
    $response = $status . "\r\n";

    foreach ($headers as $name => $value) {
        $response .= "$name: $value\r\n";
    }

    $response .= "Content-Length: " . strlen($body) . "\r\n";
    $response .= "Connection: close\r\n\r\n";

    $response .= $body;

    fwrite($client, $response);
}
