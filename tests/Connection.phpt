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


/** Lowers the response cap, so that tests need not send hundreds of megabytes. */
function limitResponse(Connection $connection, int $max): void
{
	Assert::with($connection, function () use ($max) {
		$this->maxResponseSize = $max;
	});
}


/** A FETCH response carrying the literal, with the tagged completion. */
function literalResponse(string $data): string
{
	return '* 1 FETCH (BODY[] {' . strlen($data) . "}\r\n$data)\r\nT1 OK done\r\n";
}


test('an announced literal over the cap is refused before anything is read', function () {
	[$connection, $server] = scriptedConnection("* 1 FETCH (BODY[] {300000000}\r\n");
	stream_socket_shutdown($server, STREAM_SHUT_WR); // reading would hit EOF and throw 'Cannot read' instead
	Assert::exception(
		fn() => $connection->command('UID FETCH 1 (BODY.PEEK[])'),
		DG\Imap\Exception::class,
		'Server response exceeds 268435456 bytes',
	);
});


test('a literal length beyond the integer range is refused too', function () {
	[$connection] = scriptedConnection("* 1 FETCH (BODY[] {99999999999999999999}\r\n");
	Assert::exception(
		fn() => $connection->command('UID FETCH 1 (BODY.PEEK[])'),
		DG\Imap\Exception::class,
		'Server response exceeds 268435456 bytes',
	);
});


test('a long line and many short lines count against the cap alike', function () {
	[$connection] = scriptedConnection('* ' . str_repeat('x', 200) . "\r\nT1 OK done\r\n");
	limitResponse($connection, 100);
	Assert::exception(fn() => $connection->command('NOOP'), DG\Imap\Exception::class, 'Server response exceeds 100 bytes');

	[$connection] = scriptedConnection(str_repeat('* ' . str_repeat('x', 16) . "\r\n", 10) . "T1 OK done\r\n");
	limitResponse($connection, 100);
	Assert::exception(fn() => $connection->command('NOOP'), DG\Imap\Exception::class, 'Server response exceeds 100 bytes');
});


test('the cap applies to each response, not to the connection as a whole', function () {
	$untagged = '* ' . str_repeat('x', 44) . "\r\n";
	[$connection] = scriptedConnection("{$untagged}T1 OK done\r\n{$untagged}T2 OK done\r\n");
	limitResponse($connection, 100);
	Assert::same([$untagged], $connection->command('NOOP'));
	Assert::same([$untagged], $connection->command('NOOP'));
});


test('a response of exactly the cap passes and one byte more fails, in a line or in a literal', function () {
	$lineResponse = fn(int $total) => '* ' . str_repeat('x', $total - 16) . "\r\nT1 OK done\r\n";
	Assert::same(100, strlen($lineResponse(100)));
	[$connection] = scriptedConnection($lineResponse(100));
	limitResponse($connection, 100);
	Assert::count(1, $connection->command('NOOP'));

	[$connection] = scriptedConnection($lineResponse(101));
	limitResponse($connection, 100);
	Assert::exception(fn() => $connection->command('NOOP'), DG\Imap\Exception::class, 'Server response exceeds 100 bytes');

	$data = str_repeat('y', 61);
	Assert::same(100, strlen(literalResponse($data)));
	[$connection] = scriptedConnection(literalResponse($data));
	limitResponse($connection, 100);
	Assert::same($data, Connection::parseLiteral($connection->command('UID FETCH 1 (BODY.PEEK[])')[0])[0]);

	Assert::same(101, strlen(literalResponse($data . 'y')));
	[$connection] = scriptedConnection(literalResponse($data . 'y'));
	limitResponse($connection, 100);
	Assert::exception(
		fn() => $connection->command('UID FETCH 1 (BODY.PEEK[])'),
		DG\Imap\Exception::class,
		'Server response exceeds 100 bytes',
	);
});


test('a refused response closes the connection, the stream is stuck mid-response', function () {
	[$connection] = scriptedConnection("* 1 FETCH (BODY[] {300000000}\r\n");
	Assert::exception(fn() => $connection->command('NOOP'), DG\Imap\Exception::class, 'Server response exceeds%a%');
	Assert::exception(fn() => $connection->command('NOOP'), DG\Imap\Exception::class, 'Connection is closed');
});


test('a line and a literal longer than one read chunk come back whole', function () {
	$line = '* ' . str_repeat('x', 20000) . "\r\n";
	[$connection] = scriptedConnection("{$line}T1 OK done\r\n");
	Assert::same([$line], $connection->command('NOOP'));

	$data = str_repeat("abc\r\n", 4000);
	[$connection] = scriptedConnection(literalResponse($data));
	Assert::same($data, Connection::parseLiteral($connection->command('UID FETCH 1 (BODY.PEEK[])')[0])[0]);
});
