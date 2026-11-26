<?php declare(strict_types=1);

use DG\Imap\Mailbox;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test('parses mailbox specification', function () {
	$mailbox = new Mailbox('{imap.gmail.com:993/imap/ssl}', 'user', 'password');
	Assert::with($mailbox, function () {
		Assert::same('imap.gmail.com', $this->host);
		Assert::same(993, $this->port);
		Assert::true($this->ssl);
		Assert::same('INBOX', $this->folder);
	});

	$mailbox = new Mailbox('{example.com}Sent', 'user', 'password');
	Assert::with($mailbox, function () {
		Assert::same('example.com', $this->host);
		Assert::same(143, $this->port);
		Assert::false($this->ssl);
		Assert::same('Sent', $this->folder);
	});

	$mailbox = new Mailbox('{example.com/ssl}', 'user', 'password');
	Assert::with($mailbox, function () {
		Assert::same(993, $this->port);
		Assert::true($this->ssl);
	});

	$mailbox = new Mailbox('{example.com:0}', 'user', 'password');
	Assert::with($mailbox, function () {
		Assert::same(0, $this->port); // a given port is kept even when it is falsy
	});
});


test('the certificate is verified unless /novalidate-cert says otherwise', function () {
	Assert::with(new Mailbox('{example.com/ssl}', 'user', 'password'), function () {
		Assert::true($this->validateCert);
	});
	Assert::with(new Mailbox('{example.com/ssl/novalidate-cert}', 'user', 'password'), function () {
		Assert::false($this->validateCert);
	});
	Assert::with(new Mailbox('{example.com/ssl/validate-cert}', 'user', 'password'), function () {
		Assert::true($this->validateCert); // the c-client default, accepted
	});
	Assert::exception(
		fn() => new Mailbox('{example.com/ssl/validate-cert/novalidate-cert}', 'user', 'password'),
		DG\Imap\Exception::class,
		'Flags /validate-cert and /novalidate-cert contradict each other',
	);
});


test('flags are matched whole and case-insensitively', function () {
	Assert::with(new Mailbox('{example.com/sslx}', 'user', 'password'), function () {
		Assert::false($this->ssl); // not /ssl
		Assert::same(143, $this->port);
	});
	Assert::with(new Mailbox('{example.com/imap/SSL/NoValidate-Cert}', 'user', 'password'), function () {
		Assert::true($this->ssl);
		Assert::same(993, $this->port);
		Assert::false($this->validateCert);
	});
});


test('rejects invalid mailbox specification', function () {
	Assert::exception(
		fn() => new Mailbox('imap.gmail.com', 'user', 'password'),
		DG\Imap\Exception::class,
		"Invalid mailbox specification 'imap.gmail.com'",
	);
});


test('getFolders decodes UTF-7 names and handles a name sent as a literal', function () {
	[$connection] = scriptedConnection(implode('', [
		"* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n",
		"* LIST (\\HasNoChildren \\Trash) \"/\" \"Ko&AWE-\"\r\n",
		"* LIST (\\Noselect) \"/\" {15}\r\nFolder \"quoted\"\r\n",
		"T1 OK done\r\n",
	]));
	$mailbox = new Mailbox('{example.com}', 'user', 'password');
	Assert::with($mailbox, function () use ($connection) {
		$this->connection = $connection;
	});

	$folders = $mailbox->getFolders();
	Assert::same(['\HasNoChildren'], $folders['INBOX']);
	Assert::same(['\HasNoChildren', '\Trash'], $folders['Koš']);
	Assert::same(['\Noselect'], $folders['Folder "quoted"']);
	Assert::same('Koš', $mailbox->getSpecialFolder('\Trash'));
});


test('a failed connect() leaves the previous session alone', function () {
	[$connection] = scriptedConnection("T1 OK NOOP completed\r\n");
	$mailbox = new Mailbox('{127.0.0.1:0}', 'user', 'password'); // port 0 fails without a network round trip
	Assert::with($mailbox, function () use ($connection) {
		$this->connection = $connection;
	});

	Assert::exception(
		fn() => $mailbox->connect(),
		DG\Imap\Exception::class,
		'Cannot connect to 127.0.0.1:0%a%',
	);
	Assert::same($connection, $mailbox->getConnection()); // the mailbox still holds it
	Assert::same([], $connection->command('NOOP')); // and it was not taken down
});


test('a failed folder listing is not cached as an empty one', function () {
	[$connection] = scriptedConnection(implode('', [
		"T1 NO LIST failed\r\n",
		"* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n",
		"T2 OK done\r\n",
	]));
	$mailbox = new Mailbox('{example.com}', 'user', 'password');
	Assert::with($mailbox, function () use ($connection) {
		$this->connection = $connection;
	});

	Assert::exception(
		fn() => $mailbox->getFolders(),
		DG\Imap\Exception::class,
		'Command failed: NO LIST failed',
	);
	Assert::same(['INBOX' => ['\HasNoChildren']], $mailbox->getFolders()); // retried, not served from a poisoned cache
});


test('/tls asks for STARTTLS on the plain port, and /tls-sslv23 is the same', function () {
	foreach (['{example.com/tls}', '{example.com/imap/TLS-SSLv23}'] as $spec) {
		Assert::with(new Mailbox($spec, 'user', 'password'), function () {
			Assert::true($this->startTls);
			Assert::false($this->ssl);
			Assert::same(143, $this->port);
		});
	}

	Assert::with(new Mailbox('{example.com/ssl}', 'user', 'password'), function () {
		Assert::false($this->startTls);
	});
});


test('/ssl and /tls together are refused', function () {
	Assert::exception(
		fn() => new Mailbox('{example.com/ssl/tls}', 'user', 'password'),
		DG\Imap\Exception::class,
		'Flags /ssl and /tls contradict each other%a%',
	);
});


/**
 * A connection to a scripted server with the given greeting, which scriptedConnection() fixes, and the
 * server end of the socket. The server ends are kept in a static registry, otherwise GC closes them.
 */
function greetedConnection(string $greeting, string $script = ''): array
{
	static $servers = [];

	[$client, $server] = stream_socket_pair(
		DIRECTORY_SEPARATOR === '\\' ? STREAM_PF_INET : STREAM_PF_UNIX,
		STREAM_SOCK_STREAM,
		STREAM_IPPROTO_IP,
	);
	$servers[] = $server;
	fwrite($server, $greeting . "\r\n" . $script);
	return [DG\Imap\Connection::fromStream($client), $server];
}


/** Reads what the client sent to the server. */
function sentCommands($server): array
{
	stream_set_blocking($server, false);
	$data = stream_get_contents($server) ?: '';
	return preg_split('~\r\n~', trim($data), -1, PREG_SPLIT_NO_EMPTY);
}


/** Logs in over the given connection the way connect() does after opening the socket. */
function open(Mailbox $mailbox, DG\Imap\Connection $connection): void
{
	Assert::with($mailbox, fn() => $this->open($connection));
}


test('LOGINDISABLED in the greeting refuses the login before anything is sent', function () {
	[$connection, $server] = greetedConnection('* OK [CAPABILITY IMAP4rev1 LOGINDISABLED] ready');
	$mailbox = new Mailbox('{example.com}', 'user', 'password');
	Assert::exception(
		fn() => open($mailbox, $connection),
		DG\Imap\Exception::class,
		'Server does not allow login on this connection (LOGINDISABLED); use /ssl',
	);
	Assert::same([], sentCommands($server)); // no round trip, and above all no LOGIN
	Assert::exception(fn() => $mailbox->getConnection(), DG\Imap\Exception::class, 'The mailbox is closed');
});


test('LOGINDISABLED announced by CAPABILITY refuses the login too', function () {
	[$connection, $server] = greetedConnection('* OK ready', "* CAPABILITY IMAP4rev1 LOGINDISABLED\r\nT1 OK done\r\n");
	$mailbox = new Mailbox('{example.com}', 'user', 'password');
	Assert::exception(
		fn() => open($mailbox, $connection),
		DG\Imap\Exception::class,
		'Server does not allow login on this connection (LOGINDISABLED); use /ssl',
	);
	Assert::same(['T1 CAPABILITY'], sentCommands($server));
	Assert::exception(fn() => $mailbox->getConnection(), DG\Imap\Exception::class, 'The mailbox is closed');
});


test('a server allowing login logs in as before, and its capabilities are read again afterwards', function () {
	[$connection, $server] = greetedConnection('* OK ready', implode("\r\n", [
		'* CAPABILITY IMAP4rev1',
		'T1 OK done',
		'T2 OK LOGIN completed',
		'T3 OK SELECT completed',
		'* CAPABILITY IMAP4rev1 MOVE', // announced only once logged in
		'T4 OK done',
		'',
	]));
	$mailbox = new Mailbox('{example.com}', 'user', 'password');
	open($mailbox, $connection);
	Assert::same($connection, $mailbox->getConnection());
	Assert::true($connection->hasCapability('MOVE'));

	Assert::same(
		['T1 CAPABILITY', 'T2 LOGIN "user" "password"', 'T3 SELECT "INBOX"', 'T4 CAPABILITY'],
		sentCommands($server),
	);
});
