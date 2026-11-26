<?php declare(strict_types=1);

use DG\Imap\{Connection, Mailbox, Message};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// Builds a mailbox talking to a scripted server instead of the network, and returns the server
// end too, so the test can read what the client sent. The server ends are kept in a static
// registry, otherwise GC collects them and the socket closes.
function scriptedMailbox(string $script): array
{
	static $servers = [];

	[$client, $server] = stream_socket_pair(
		DIRECTORY_SEPARATOR === '\\' ? STREAM_PF_INET : STREAM_PF_UNIX,
		STREAM_SOCK_STREAM,
		STREAM_IPPROTO_IP,
	);
	$servers[] = $server;
	// connect() is bypassed, the connection is handed to the mailbox directly, so the dialogue starts with the greeting
	fwrite($server, "* OK ready\r\n" . $script);

	$mailbox = new Mailbox('{example.com/ssl}', 'user', 'pass');
	Assert::with($mailbox, function () use ($client) {
		$this->connection = Connection::fromStream($client);
	});
	return [$mailbox, $server];
}


/** Reads what the client sent to the server. */
function sentCommands($server): array
{
	stream_set_blocking($server, false);
	$data = stream_get_contents($server) ?: '';
	return preg_split('~\r\n~', trim($data), -1, PREG_SPLIT_NO_EMPTY);
}


/** Builds one FETCH response carrying the header fields as a literal, with the given items around it. */
function fetchResponse(int $seq, string $before, string $headers, string $after = ''): string
{
	$headers = crlf($headers) . "\r\n";
	return "* $seq FETCH ($before BODY[HEADER.FIELDS (SUBJECT FROM DATE)] {" . strlen($headers) . "}\r\n$headers$after)\r\n";
}


function getUid(Message $message): int
{
	return Assert::with($message, fn() => $this->uid);
}


test('lists messages with UID, size and subject, whatever the order of the items', function () {
	[$mailbox, $server] = scriptedMailbox(
		fetchResponse(1, 'UID 7 RFC822.SIZE 2048', "Subject: First\n")
		. fetchResponse(2, 'UID 9', "Subject: Second RFC822.SIZE 999\n", ' RFC822.SIZE 512')
		. "T1 OK FETCH completed\r\n",
	);
	$messages = $mailbox->getMessages();

	Assert::count(2, $messages);
	Assert::same(7, getUid($messages[0]));
	Assert::same(2048, $messages[0]->getSize());
	Assert::same('First', $messages[0]->getSubject());

	// the subject comes first on the line, yet only the FETCH envelope counts
	Assert::same(9, getUid($messages[1]));
	Assert::same(512, $messages[1]->getSize());
	Assert::same('Second RFC822.SIZE 999', $messages[1]->getSubject());

	Assert::same(
		['T1 UID FETCH 1:* (UID RFC822.SIZE BODY.PEEK[HEADER.FIELDS (SUBJECT FROM DATE)])'],
		sentCommands($server),
	);
});


test('a size the server leaves out is null, even when a header mentions one', function () {
	[$mailbox] = scriptedMailbox(
		fetchResponse(1, 'UID 7', "Subject: RFC822.SIZE 999\n")
		. "T1 OK FETCH completed\r\n",
	);
	[$message] = $mailbox->getMessages();

	Assert::same(7, getUid($message));
	Assert::null($message->getSize());
});
