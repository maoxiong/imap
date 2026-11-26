<?php declare(strict_types=1);

use DG\Imap\Connection;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test('reads untagged lines up to the tagged OK', function () {
	[$connection] = scriptedConnection(implode('', [
		"* 3 EXISTS\r\n",
		"* 0 RECENT\r\n",
		"T1 OK [READ-WRITE] SELECT completed\r\n",
	]));
	Assert::same(["* 3 EXISTS\r\n", "* 0 RECENT\r\n"], $connection->command('SELECT INBOX'));
});


test('inlines a literal and keeps reading the same logical line', function () {
	$body = "Subject: Hello\r\n\r\nbody text\r\n";
	[$connection] = scriptedConnection(implode('', [
		'* 1 FETCH (UID 42 BODY[] {' . strlen($body) . "}\r\n",
		$body,
		")\r\n",
		"T1 OK FETCH completed\r\n",
	]));
	$lines = $connection->command('UID FETCH 42 (BODY.PEEK[])');
	Assert::count(1, $lines);
	Assert::contains($body, $lines[0]);

	[$literal, $rest] = Connection::parseLiteral($lines[0]);
	Assert::same($body, $literal);
	Assert::same("* 1 FETCH (UID 42 BODY[] )\r\n", $rest);
});


test('handles several literals within one response', function () {
	[$connection] = scriptedConnection(implode('', [
		"* 1 FETCH (BODY[HEADER] {5}\r\n",
		'AAAAA',
		" BODY[TEXT] {3}\r\n",
		'BBB',
		")\r\n",
		"T1 OK done\r\n",
	]));
	$lines = $connection->command('FETCH 1 (BODY[])');
	Assert::count(1, $lines);
	Assert::same("* 1 FETCH (BODY[HEADER] AAAAA BODY[TEXT] BBB)\r\n", str_replace(["{5}\r\n", "{3}\r\n"], '', $lines[0]));
});


test('tagged NO and BAD responses throw', function () {
	[$connection] = scriptedConnection("T1 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n");
	Assert::exception(
		fn() => $connection->command('LOGIN "user" "pass"'),
		DG\Imap\Exception::class,
		'Command failed: NO [AUTHENTICATIONFAILED] Invalid credentials',
	);

	[$connection] = scriptedConnection("T1 BAD Could not parse command\r\n");
	Assert::exception(
		fn() => $connection->command('NONSENSE'),
		DG\Imap\Exception::class,
		'Command failed: BAD Could not parse command',
	);
});


test('tags are sequential so responses are not mismatched', function () {
	[$connection] = scriptedConnection("T1 OK first\r\nT2 OK second\r\n");
	Assert::same([], $connection->command('NOOP'));
	Assert::same([], $connection->command('NOOP'));
});


test('unexpected end of stream throws', function () {
	[$connection, $server] = scriptedConnection('');
	stream_socket_shutdown($server, STREAM_SHUT_WR); // EOF for the client; closing would make its write fail first
	Assert::exception(
		fn() => $connection->command('NOOP'),
		DG\Imap\Exception::class,
		'Cannot read from server%a%',
	);
});


test('a partial line at end of stream throws instead of being taken as complete', function () {
	[$connection, $server] = scriptedConnection('T1 OK done'); // missing CRLF
	stream_socket_shutdown($server, STREAM_SHUT_WR);
	Assert::exception(
		fn() => $connection->command('NOOP'),
		DG\Imap\Exception::class,
		'Cannot read from server%a%',
	);
});


test('using a disconnected connection throws', function () {
	[$connection] = scriptedConnection("T1 OK bye\r\n");
	$connection->disconnect();
	Assert::exception(
		fn() => $connection->command('NOOP'),
		DG\Imap\Exception::class,
		'Connection is closed',
	);
	Assert::noError(fn() => $connection->disconnect());
});


test('parseLiteral returns null when there is no literal', function () {
	Assert::null(Connection::parseLiteral("* 1 FETCH (UID 42)\r\n"));
});


/** Reads what the client sent to the server. */
function sentCommands($server): array
{
	stream_set_blocking($server, false);
	$data = stream_get_contents($server) ?: '';
	return preg_split('~\r\n~', trim($data), -1, PREG_SPLIT_NO_EMPTY);
}


/** Asserts that the connection was closed rather than left to go on in plaintext. */
function assertClosed(Connection $connection): void
{
	Assert::exception(fn() => $connection->command('NOOP'), DG\Imap\Exception::class, 'Connection is closed');
}


test('STARTTLS is never skipped: a server that does not offer it is refused', function () {
	[$connection, $server] = scriptedConnection("* CAPABILITY IMAP4rev1 LOGINDISABLED\r\nT1 OK done\r\n");
	Assert::exception(fn() => $connection->startTls(), DG\Imap\Exception::class, 'Server does not offer STARTTLS');
	Assert::same(['T1 CAPABILITY'], sentCommands($server));
	assertClosed($connection);
});


test('a STARTTLS the server declines closes the connection', function () {
	[$connection] = scriptedConnection("* CAPABILITY IMAP4rev1 STARTTLS\r\nT1 OK done\r\nT2 NO TLS unavailable\r\n");
	Assert::exception(fn() => $connection->startTls(), DG\Imap\Exception::class, 'Command failed: NO TLS unavailable');
	assertClosed($connection);
});


test('data sent after the STARTTLS reply, before the handshake, is refused as an injection', function () {
	[$connection] = scriptedConnection(implode('', [
		"* CAPABILITY IMAP4rev1 STARTTLS\r\n",
		"T1 OK done\r\n",
		"T2 OK Begin TLS negotiation now\r\n",
		"* OK injected in plaintext\r\n", // would otherwise be read as if it came over TLS
	]));
	Assert::exception(fn() => $connection->startTls(), DG\Imap\Exception::class, 'Server sent data before the TLS handshake');
	assertClosed($connection);
});


test('a PREAUTH greeting is refused, the session is authenticated before TLS could start', function () {
	[$client, $server] = stream_socket_pair(
		DIRECTORY_SEPARATOR === '\\' ? STREAM_PF_INET : STREAM_PF_UNIX,
		STREAM_SOCK_STREAM,
		STREAM_IPPROTO_IP,
	);
	fwrite($server, "* PREAUTH logged in as robot\r\n");
	$connection = Connection::fromStream($client);
	Assert::exception(fn() => $connection->startTls(), DG\Imap\Exception::class, 'Server greeting does not allow STARTTLS: * PREAUTH%a%');
	Assert::same([], sentCommands($server));
	assertClosed($connection);
});
