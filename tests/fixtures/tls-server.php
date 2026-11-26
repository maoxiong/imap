<?php declare(strict_types=1);

// A minimal IMAP server for tls.phpt. In the default mode it speaks implicit TLS; with the argument
// 'starttls' it starts in plaintext, offers STARTTLS and refuses LOGIN until the connection is upgraded.
// It prints the port it listens on, then greets every connection and answers each command until LOGOUT.
// It ends itself after half a minute, so a test that dies without terminating it leaves nothing behind.

$startTls = ($argv[1] ?? '') === 'starttls';
$certificate = [
	'local_cert' => __DIR__ . '/localhost.crt',
	'local_pk' => __DIR__ . '/localhost.key',
];
$server = stream_socket_server(
	($startTls ? 'tcp' : 'tls') . '://127.0.0.1:0',
	$errorCode,
	$errorMessage,
	STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
	stream_context_create(['ssl' => $certificate]),
);
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

	$tls = !$startTls;
	fwrite($client, "* OK test server ready\r\n");
	while (($line = fgets($client)) !== false && preg_match('~^(\S+) (\S+)~', $line, $m)) {
		[, $tag, $command] = $m;
		switch (strtoupper($command)) {
			case 'CAPABILITY':
				$capabilities = $tls ? 'IMAP4rev1' : 'IMAP4rev1 STARTTLS LOGINDISABLED';
				fwrite($client, "* CAPABILITY $capabilities\r\n$tag OK CAPABILITY completed\r\n");
				break;
			case 'STARTTLS':
				fwrite($client, "$tag OK Begin TLS negotiation now\r\n");
				foreach ($certificate as $option => $value) {
					stream_context_set_option($client, 'ssl', $option, $value);
				}
				$tls = (bool) @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER); // @ a client that rejected the certificate
				break;
			case 'LOGIN':
				fwrite($client, $tls ? "$tag OK LOGIN completed\r\n" : "$tag BAD LOGIN only after STARTTLS\r\n");
				break;
			case 'LOGOUT':
				fwrite($client, "* BYE\r\n$tag OK LOGOUT completed\r\n");
				break 2;
			default:
				fwrite($client, "$tag OK $command completed\r\n");
		}

		if (!$tls && strtoupper($command) === 'STARTTLS') {
			break; // the handshake failed
		}
	}
	fclose($client);
}
