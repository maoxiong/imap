<?php declare(strict_types=1);

// A minimal IMAP server over TLS for tls.phpt. It prints the port it listens on, then greets every
// connection and answers OK to each command until LOGOUT. It ends itself after half a minute, so a test
// that dies without terminating it leaves nothing behind.

$context = stream_context_create(['ssl' => [
	'local_cert' => __DIR__ . '/localhost.crt',
	'local_pk' => __DIR__ . '/localhost.key',
]]);
$server = stream_socket_server('tls://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) {
	exit("Cannot listen: $errorMessage\n");
}

echo substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1), "\n";

$deadline = time() + 30;
while (time() < $deadline) {
	$client = @stream_socket_accept($server, 1); // @ a timeout, or a client that rejected the certificate
	if (!$client) {
		continue;
	}

	fwrite($client, "* OK test server ready\r\n");
	while (($line = fgets($client)) !== false && preg_match('~^(\S+) (\S+)~', $line, $m)) {
		if (strtoupper($m[2]) === 'LOGOUT') {
			fwrite($client, "* BYE\r\n$m[1] OK LOGOUT completed\r\n");
			break;
		}
		fwrite($client, "$m[1] OK $m[2] completed\r\n");
	}
	fclose($client);
}
