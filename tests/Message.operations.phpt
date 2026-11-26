<?php declare(strict_types=1);

use DG\Imap\{Connection, Mailbox, Message};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// A mailbox with a single message talking to a scripted server. It returns the server end too,
// so the test can read what the client sent and check the exact wording of the commands.
function scriptedMessage(string $capabilities, string $script): array
{
	static $servers = [];

	[$client, $server] = stream_socket_pair(
		DIRECTORY_SEPARATOR === '\\' ? STREAM_PF_INET : STREAM_PF_UNIX,
		STREAM_SOCK_STREAM,
		STREAM_IPPROTO_IP,
	);
	$servers[] = $server;
	fwrite($server, "* OK ready\r\n* CAPABILITY IMAP4rev1 $capabilities\r\nT1 OK CAPABILITY\r\n" . $script);

	$mailbox = new Mailbox('{example.com/ssl}', 'user', 'pass');
	Assert::with($mailbox, function () use ($client) {
		$this->connection = Connection::fromStream($client);
	});

	// asks for the capabilities up front (command T1), so that the order of the following
	// commands does not depend on when the library asks for them
	$mailbox->getConnection()->hasCapability('IMAP4rev1');

	$message = new Message($mailbox, $mailbox->getGeneration(), 42, ['subject' => 'Test']);
	return [$message, $mailbox, $server];
}


/** Reads what the client sent to the server. */
function sentCommands($server): array
{
	stream_set_blocking($server, false);
	$data = stream_get_contents($server) ?: '';
	return preg_split('~\r\n~', trim($data), -1, PREG_SPLIT_NO_EMPTY);
}


/** Builds the server's answer to a FETCH of message 42, the data sent as a literal. */
function fetchResponse(string $tag, string $item, string $data): string
{
	return "* 1 FETCH (UID 42 $item {" . strlen($data) . "}\r\n$data)\r\n$tag OK FETCH\r\n";
}


const HeaderBlock = "Message-ID: <abc@example.com>\r\nSubject: Test\r\n\r\n";


test('delete() removes this message alone and immediately when the server supports UIDPLUS', function () {
	[$message, , $server] = scriptedMessage('UIDPLUS', "T2 OK STORE\r\nT3 OK EXPUNGE\r\n");
	$message->delete();

	Assert::same(
		['T2 UID STORE 42 +FLAGS.SILENT (\Deleted)', 'T3 UID EXPUNGE 42'],
		array_slice(sentCommands($server), 1),
	);
});


test('without UIDPLUS only expunging the whole folder is left', function () {
	[$message, , $server] = scriptedMessage('MOVE', "T2 OK STORE\r\nT3 OK EXPUNGE\r\n");
	$message->delete();

	Assert::same(
		['T2 UID STORE 42 +FLAGS.SILENT (\Deleted)', 'T3 EXPUNGE'],
		array_slice(sentCommands($server), 1),
	);
});


test('moveTo() uses MOVE and converts the folder name to modified UTF-7', function () {
	[$message, , $server] = scriptedMessage('MOVE UIDPLUS', "T2 OK MOVE\r\n");
	$message->moveTo('[Gmail]/Koš');

	Assert::same(['T2 UID MOVE 42 "[Gmail]/Ko&AWE-"'], array_slice(sentCommands($server), 1));
});


test('without MOVE support the message travels via COPY and expunge', function () {
	[$message, , $server] = scriptedMessage('UIDPLUS', "T2 OK COPY\r\nT3 OK STORE\r\nT4 OK EXPUNGE\r\n");
	$message->moveTo('Archiv');

	Assert::same(
		['T2 UID COPY 42 "Archiv"', 'T3 UID STORE 42 +FLAGS.SILENT (\Deleted)', 'T4 UID EXPUNGE 42'],
		array_slice(sentCommands($server), 1),
	);
});


test('trash() finds the trash folder by the server attribute', function () {
	[$message, , $server] = scriptedMessage('MOVE UIDPLUS', implode("\r\n", [
		'* LIST (\HasNoChildren) "/" "INBOX"',
		'* LIST (\HasNoChildren \Trash) "/" "[Gmail]/Ko&AWE-"',
		'T2 OK LIST',
		'T3 OK MOVE',
		'',
	]));
	$message->trash();

	Assert::same(
		['T2 LIST "" "*"', 'T3 UID MOVE 42 "[Gmail]/Ko&AWE-"'],
		array_slice(sentCommands($server), 1),
	);
});


test('archive() prefers \Archive and falls back to \All', function () {
	[$message, , $server] = scriptedMessage('MOVE UIDPLUS', implode("\r\n", [
		'* LIST (\All) "/" "[Gmail]/V&AWE-echny zpr&AOE-vy"',
		'T2 OK LIST',
		'T3 OK MOVE',
		'',
	]));
	$message->archive();

	Assert::same('T3 UID MOVE 42 "[Gmail]/V&AWE-echny zpr&AOE-vy"', sentCommands($server)[2]);

	[$message, , $server] = scriptedMessage('MOVE UIDPLUS', implode("\r\n", [
		'* LIST (\Archive) "/" "Archiv&AOE-"',
		'* LIST (\All) "/" "Vsechno"',
		'T2 OK LIST',
		'T3 OK MOVE',
		'',
	]));
	$message->archive();
	Assert::same('T3 UID MOVE 42 "Archiv&AOE-"', sentCommands($server)[2]);
});


test('after the mailbox is closed the message is unusable and nothing reconnects on its own', function () {
	[$message, $mailbox] = scriptedMessage('MOVE UIDPLUS', "T2 OK LOGOUT\r\n");
	$mailbox->close();

	Assert::exception(
		fn() => $message->delete(),
		DG\Imap\Exception::class,
		'The mailbox is closed',
	);
});


test('a message from an earlier session is rejected, its UID means nothing anymore', function () {
	[$message, $mailbox] = scriptedMessage('MOVE UIDPLUS', '');
	Assert::with($mailbox, function () {
		$this->generation++; // as if a new connect() had happened
	});

	Assert::exception(
		fn() => $message->delete(),
		DG\Imap\Exception::class,
		'Message comes from an earlier session%a%',
	);
});


test('closing the mailbox drops the folder cache as well', function () {
	[, $mailbox] = scriptedMessage('MOVE UIDPLUS', implode("\r\n", [
		'* LIST (\HasNoChildren \Trash) "/" "Kos"',
		'T2 OK LIST',
		'T3 OK LOGOUT',
		'',
	]));
	Assert::same('Kos', $mailbox->getSpecialFolder('\Trash'));

	$mailbox->close();
	Assert::with($mailbox, function () {
		Assert::null($this->folders);
	});
});


test('with no folder advertised the operation fails loudly instead of guessing a name', function () {
	[$message] = scriptedMessage('MOVE UIDPLUS', "* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\nT2 OK LIST\r\n");
	Assert::exception(
		fn() => $message->trash(),
		DG\Imap\Exception::class,
		'The server advertises no trash folder%a%',
	);

	[$message] = scriptedMessage('MOVE UIDPLUS', "* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\nT2 OK LIST\r\n");
	Assert::exception(
		fn() => $message->archive(),
		DG\Imap\Exception::class,
		'The server advertises no archive folder%a%',
	);
});


test('a message that is no longer in the mailbox cannot be worked with', function () {
	[$message] = scriptedMessage('MOVE UIDPLUS', "T2 OK MOVE\r\n");
	$message->moveTo('Jinam');

	foreach ([fn() => $message->delete(), fn() => $message->trash(), fn() => $message->moveTo('X')] as $operation) {
		Assert::exception($operation, LogicException::class, 'Message is no longer in the mailbox');
	}
});


test('other headers cost a single fetch of the header block, never the body', function () {
	[$message, , $server] = scriptedMessage('MOVE UIDPLUS', fetchResponse('T2', 'BODY[HEADER]', HeaderBlock));
	Assert::same('<abc@example.com>', $message->getHeader('Message-ID'));
	Assert::same('<abc@example.com>', $message->getHeader('Message-ID'));
	Assert::null($message->getHeader('X-Missing'));
	Assert::same(['subject' => 'Test', 'message-id' => '<abc@example.com>'], $message->getHeaders());

	Assert::same(['T2 UID FETCH 42 (BODY.PEEK[HEADER])'], array_slice(sentCommands($server), 1));
});


test('a header known from the listing costs nothing', function () {
	[$message, , $server] = scriptedMessage('MOVE UIDPLUS', '');
	Assert::same('Test', $message->getHeader('Subject'));
	Assert::same([], array_slice(sentCommands($server), 1));
});


test('once the body is downloaded, headers come from it', function () {
	$raw = HeaderBlock . "body\r\n";
	[$message, , $server] = scriptedMessage('MOVE UIDPLUS', fetchResponse('T2', 'BODY[]', $raw));
	Assert::same($raw, $message->getRaw());
	Assert::same('<abc@example.com>', $message->getHeader('Message-ID'));
	Assert::same("body\r\n", $message->getContents());

	Assert::same(['T2 UID FETCH 42 (BODY.PEEK[])'], array_slice(sentCommands($server), 1));
});


test('a message of headers only is fetched once, its empty body counts as fetched', function () {
	$raw = "Message-ID: <abc@example.com>\r\nSubject: Test\r\n";
	// the spare answer to T3 makes a second fetch fail the assertion instead of hanging the test
	[$message, , $server] = scriptedMessage('MOVE UIDPLUS', fetchResponse('T2', 'BODY[]', $raw) . fetchResponse('T3', 'BODY[]', $raw));
	Assert::same($raw, $message->getRaw());
	Assert::same($raw, $message->getRaw());
	Assert::same('', $message->getContents());
	Assert::same('', $message->getContents());

	Assert::same(['T2 UID FETCH 42 (BODY.PEEK[])'], array_slice(sentCommands($server), 1));
});


test('a header block fetched on its own does not count as the body', function () {
	$raw = HeaderBlock . "body\r\n";
	[$message, , $server] = scriptedMessage(
		'MOVE UIDPLUS',
		fetchResponse('T2', 'BODY[HEADER]', HeaderBlock) . fetchResponse('T3', 'BODY[]', $raw),
	);
	Assert::same('<abc@example.com>', $message->getHeader('Message-ID'));
	Assert::same($raw, $message->getRaw());

	Assert::same(
		['T2 UID FETCH 42 (BODY.PEEK[HEADER])', 'T3 UID FETCH 42 (BODY.PEEK[])'],
		array_slice(sentCommands($server), 1),
	);
});


test('getRawHeaders() costs a single fetch of the header block, never the body', function () {
	// the spare answer to T3 makes a second fetch fail the assertion instead of hanging the test
	[$message, , $server] = scriptedMessage(
		'MOVE UIDPLUS',
		fetchResponse('T2', 'BODY[HEADER]', HeaderBlock) . fetchResponse('T3', 'BODY[HEADER]', HeaderBlock),
	);
	Assert::same(HeaderBlock, $message->getRawHeaders());
	Assert::same(HeaderBlock, $message->getRawHeaders());

	Assert::same(['T2 UID FETCH 42 (BODY.PEEK[HEADER])'], array_slice(sentCommands($server), 1));
});


test('once the body is in, getRawHeaders() costs nothing and getRaw() starts with it', function () {
	$raw = HeaderBlock . "body\r\n";
	[$message, , $server] = scriptedMessage('MOVE UIDPLUS', fetchResponse('T2', 'BODY[]', $raw));
	Assert::same($raw, $message->getRaw());
	Assert::same(HeaderBlock, $message->getRawHeaders());

	Assert::same(['T2 UID FETCH 42 (BODY.PEEK[])'], array_slice(sentCommands($server), 1));
});


test('the header block is the same bytes whether it came with the headers or with the body', function () {
	$raw = HeaderBlock . "body\r\n";
	[$message] = scriptedMessage(
		'MOVE UIDPLUS',
		fetchResponse('T2', 'BODY[HEADER]', HeaderBlock) . fetchResponse('T3', 'BODY[]', $raw),
	);
	$before = $message->getRawHeaders();
	Assert::same($raw, $message->getRaw());
	Assert::same($before, $message->getRawHeaders());
	Assert::true(str_starts_with($message->getRaw(), $before));
});


test('a message from a mailbox knows its UID', function () {
	[$message] = scriptedMessage('MOVE UIDPLUS', '');
	Assert::same(42, $message->getUid());
});


test('headers fetched before a move stay readable, those never fetched are gone', function () {
	[$message] = scriptedMessage('MOVE UIDPLUS', fetchResponse('T2', 'BODY[HEADER]', HeaderBlock) . "T3 OK MOVE\r\n");
	$message->getHeader('Message-ID');
	$message->moveTo('Jinam');
	Assert::same('<abc@example.com>', $message->getHeader('Message-ID'));
	Assert::same(['subject' => 'Test', 'message-id' => '<abc@example.com>'], $message->getHeaders());
	Assert::same(HeaderBlock, $message->getRawHeaders());

	[$message] = scriptedMessage('MOVE UIDPLUS', "T2 OK MOVE\r\n");
	$message->moveTo('Jinam');
	foreach ([fn() => $message->getHeader('Message-ID'), fn() => $message->getRawHeaders(), fn() => $message->getRaw()] as $operation) {
		Assert::exception($operation, LogicException::class, 'Message is no longer in the mailbox');
	}
});


test('a detached .eml message allows none of the operations', function () {
	$message = Message::fromString("Subject: X\r\n\r\nbody");
	foreach ([fn() => $message->delete(), fn() => $message->trash(), fn() => $message->archive()] as $operation) {
		Assert::exception($operation, LogicException::class, 'Message is not bound to a mailbox');
	}
});
