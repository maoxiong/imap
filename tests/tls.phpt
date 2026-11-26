<?php declare(strict_types=1);

use DG\Imap\Mailbox;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// A real TLS handshake without leaving the machine: fixtures/tls-server.php runs in a child process with
// a self-signed certificate for localhost and 127.0.0.1, valid for 100 years. Regenerate it in fixtures/ with
// openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 36500 -subj "/CN=localhost"
//   -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" -keyout localhost.key -out localhost.crt
$server = proc_open([PHP_BINARY, __DIR__ . '/fixtures/tls-server.php'], [1 => ['pipe', 'w']], $pipes);
$port = (int) fgets($pipes[1]);
// 127.0.0.1, not localhost, which on Windows may try ::1 first and wait for the refusal


try {
	test('the certificate is verified by default, so a self-signed one is refused', function () use ($port) {
		$mailbox = new Mailbox("{127.0.0.1:$port/ssl}", 'user', 'password');
		Assert::exception(
			fn() => $mailbox->connect(),
			DG\Imap\Exception::class,
			"Cannot connect to 127.0.0.1:$port%a?%",
		);
	});


	test('/novalidate-cert accepts it and the session runs over TLS', function () use ($port) {
		$mailbox = new Mailbox("{127.0.0.1:$port/ssl/novalidate-cert}", 'user', 'password');
		$mailbox->connect(); // LOGIN and SELECT
		Assert::same([], $mailbox->getConnection()->command('NOOP'));
		$mailbox->close();
	});

} finally {
	proc_terminate($server);
}
