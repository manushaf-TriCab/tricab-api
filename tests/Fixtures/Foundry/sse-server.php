<?php

// A stand-in for Foundry's streaming endpoint, for CurlFoundryStreamClientTest
// under `php -S`. It answers in deliberately awkward pieces: CRLF line endings,
// a frame split across writes, a comment line and a ping.
declare(strict_types=1);

if (($_SERVER['HTTP_X_SCENARIO'] ?? '') === 'reject') {
    http_response_code(529);
    header('Content-Type: application/json');
    echo '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}';

    return;
}

if (($_SERVER['HTTP_X_SCENARIO'] ?? '') === 'hang') {
    // Accepts the request and then says nothing, as a stalled model call does.
    sleep(2);

    return;
}

header('Content-Type: text/event-stream');
$body = json_decode((string) file_get_contents('php://input'), true);
$echo = is_array($body) && ($body['stream'] ?? false) === true ? 'stream-true' : 'stream-missing';

$pieces = [
    ": comment\r\n\r\n",
    "event: message_start\r\ndata: {\"type\":\"message_start\",\"message\":{\"usage\":{\"input_tokens\":3}}}\r\n\r\n",
    "event: content_block_delta\r\ndata: {\"type\":\"content_block_delta\",\"index\":0,",
    "\"delta\":{\"type\":\"text_delta\",\"text\":\"" . $echo . "\"}}\r\n\r\nevent: ping\r\ndata: {\"type\": \"ping\"}\r\n\r\n",
    "event: message_stop\r\ndata: {\"type\":\"message_stop\"}\r\n\r\n",
];
foreach ($pieces as $piece) {
    echo $piece;
    flush();
    usleep(20_000);
}
