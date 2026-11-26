<?php declare(strict_types=1);

use DG\Imap\Message;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// mirrors a real supplier email including its broken 'Content-Type: base64' header
function buildDeliveryMessage(): string
{
	$subject = '=?utf-8?B?' . base64_encode('Dodací list') . '?= =?utf-8?B?' . base64_encode(' číslo 10563113') . '?=';
	$attachment = base64_encode(iconv('UTF-8', 'CP852', 'Dodací list|123|příliš žluťoučký'));
	return crlf(<<<XX
		From: <modemy.diskety@alliance-healthcare.cz>
		To: <robot@example.com>
		Subject: $subject
		Date: Fri, 3 Sep 2021 10:13:56 +0200
		MIME-Version: 1.0
		Content-Type: multipart/mixed; boundary="----MIME delimiter 42"

		------MIME delimiter 42
		Content-Transfer-Encoding: 7bit
		Content-Type: text/plain;
			charset="iso-8859-2"

		Dodaci list:123

		------MIME delimiter 42
		Content-Type: base64;
			name="123.DOD"
		Content-Disposition: attachment; filename="123.DOD"
		Content-Transfer-Encoding: base64

		$attachment

		------MIME delimiter 42--

		XX);
}


test('decodes multi-chunk encoded subject and overview headers', function () {
	$message = Message::fromString(buildDeliveryMessage());
	Assert::same('Dodací list číslo 10563113', $message->getSubject());
	Assert::same('<modemy.diskety@alliance-healthcare.cz>', $message->getFrom());
	Assert::same('2021-09-03 10:13:56', $message->getDate()?->format('Y-m-d H:i:s'));
});


test('splits message into parts and decodes attachment', function () {
	$message = Message::fromString(buildDeliveryMessage());
	Assert::same(2, $message->countParts());

	$text = $message->getPart(0);
	Assert::same("Dodaci list:123\r\n", $text->getContents());
	Assert::same('iso-8859-2', $text->getParameter('CHARSET'));

	$attachment = $message->getPart(1);
	Assert::same('Dodací list|123|příliš žluťoučký', iconv('CP852', 'UTF-8', $attachment->getContents()));
	Assert::same('123.DOD', $attachment->getParameter('name'));
	Assert::same(['NAME' => '123.DOD'], $attachment->getParameters());
});


test('getContents returns raw first part of a multipart message', function () {
	$message = Message::fromString(buildDeliveryMessage());
	Assert::same("Dodaci list:123\r\n", $message->getContents());
});


test('keeps nested multipart as a single part with raw contents', function () {
	$pdf = base64_encode('%PDF-fake');
	$message = Message::fromString(crlf(<<<XX
		Subject: Promo
		Content-Type: multipart/mixed; boundary="OUTER"

		--OUTER
		Content-Type: multipart/alternative; boundary="INNER"

		--INNER
		Content-Type: text/plain; charset="UTF-8"

		text version
		--INNER
		Content-Type: text/html; charset="UTF-8"

		<p>html version</p>
		--INNER--

		--OUTER
		Content-Type: application/pdf; name="a.pdf"
		Content-Transfer-Encoding: base64

		$pdf
		--OUTER--

		XX));
	Assert::same(2, $message->countParts());
	Assert::match('%A%--INNER%A%html version%A%', $message->getPart(0)->getContents());
	Assert::same('%PDF-fake', $message->getPart(1)->getContents());
});


test('decodes quoted-printable part', function () {
	$encoded = quoted_printable_encode(iconv('UTF-8', 'CP1250', 'Žluťoučký kůň'));
	$message = Message::fromString(crlf(<<<XX
		Content-Type: multipart/mixed; boundary="B"

		--B
		Content-Type: text/plain; charset="windows-1250"
		Content-Transfer-Encoding: quoted-printable

		$encoded
		--B--
		XX));
	Assert::same('Žluťoučký kůň', $message->getPart(0)->getContents());
});


test('non-multipart message has no parts', function () {
	$message = Message::fromString(crlf(<<<'XX'
		Subject: Plain
		Content-Type: text/plain

		Hello
		XX));
	Assert::same(0, $message->countParts());
	Assert::same([], $message->getParts());
	Assert::same('Hello', $message->getContents());
	Assert::exception(
		fn() => $message->getPart(0),
		ValueError::class,
		'Invalid part number',
	);
});


test('raw 8-bit subject is returned as-is', function () {
	$message = Message::fromString("Subject: MedPharma – odeslání objednávky\r\n\r\n");
	Assert::same('MedPharma – odeslání objednávky', $message->getSubject());
});


test('From with an encoded-word display name is decoded', function () {
	$message = Message::fromString('From: =?UTF-8?B?' . base64_encode('Žluťoučký kůň') . "?= <kun@example.com>\r\n\r\n");
	Assert::same('Žluťoučký kůň <kun@example.com>', $message->getFrom());
});


test('missing, empty or malformed Date gives null instead of "now"', function () {
	Assert::null(Message::fromString("Subject: X\r\n\r\n")->getDate());
	Assert::null(Message::fromString("Date: not a date\r\n\r\n")->getDate());
	Assert::null(Message::fromString("Date:\r\n\r\n")->getDate()); // an empty value would parse as 'now'
	Assert::null(Message::fromString("Date:    \r\n\r\n")->getDate());
});


test('charset wrongly declared on a binary attachment is ignored', function () {
	$binary = "\xFF\xFE\x00binary";
	$encoded = base64_encode($binary);
	$message = Message::fromString(crlf(<<<XX
		Content-Type: multipart/mixed; boundary="B"

		--B
		Content-Type: application/octet-stream; charset="utf-8"; name="a.bin"
		Content-Transfer-Encoding: base64

		$encoded
		--B--
		XX));
	Assert::same($binary, $message->getPart(0)->getContents());
});


test('an illegal byte does not cost the whole text part', function () {
	$text = "valid \xFF\xFE broken";
	$message = Message::fromString(crlf(<<<XX
		Content-Type: multipart/mixed; boundary="B"

		--B
		Content-Type: text/plain; charset="utf-8"

		$text
		--B--
		XX));
	// whether //IGNORE drops the illegal bytes or refuses is up to the iconv implementation,
	// so only the readable text around them is pinned here
	Assert::match('valid%A%broken', $message->getPart(0)->getContents());
});


test('unknown charset falls back to the undecoded bytes', function () {
	$message = Message::fromString(crlf(<<<'XX'
		Content-Type: multipart/mixed; boundary="B"

		--B
		Content-Type: text/plain; charset="x-no-such-charset"

		raw bytes
		--B--
		XX));
	Assert::same('raw bytes', $message->getPart(0)->getContents());
});


test('corrupted base64 throws the library exception', function () {
	$message = Message::fromString(crlf(<<<'XX'
		Content-Type: multipart/mixed; boundary="B"

		--B
		Content-Transfer-Encoding: base64

		!!!not base64!!!
		--B--
		XX));
	Assert::exception(
		fn() => $message->getPart(0)->getContents(),
		DG\Imap\Exception::class,
		'Failed to decode content of message part',
	);
});


test('detached message cannot be deleted', function () {
	$message = Message::fromString("Subject: X\r\n\r\nbody");
	Assert::exception(
		fn() => $message->delete(),
		LogicException::class,
		'Message is not bound to a mailbox',
	);
});


function buildHeaderMessage(): string
{
	$note = '=?utf-8?B?' . base64_encode('Příliš žluťoučký') . '?=';
	return crlf(<<<XX
		Received: from second.example.com
		Received: from first.example.com
		Message-ID: <abc.123@example.com>
		X-Note: $note
		X-Folded: first line
			second line
		Subject: Hello

		body
		XX);
}


test('getHeader() is case-insensitive, unfolds and decodes, and gives null for a missing header', function () {
	$message = Message::fromString(buildHeaderMessage());
	Assert::same('<abc.123@example.com>', $message->getHeader('Message-ID'));
	Assert::same('<abc.123@example.com>', $message->getHeader('message-id'));
	Assert::same('Příliš žluťoučký', $message->getHeader('X-Note'));
	Assert::same('first line second line', $message->getHeader('X-Folded'));
	Assert::null($message->getHeader('X-Missing'));
});


test('a repeated header returns its first occurrence', function () {
	$message = Message::fromString(buildHeaderMessage());
	Assert::same('from second.example.com', $message->getHeader('Received'));
});


test('getHeaders() returns lowercased names and decoded values', function () {
	Assert::same([
		'received' => 'from second.example.com',
		'message-id' => '<abc.123@example.com>',
		'x-note' => 'Příliš žluťoučký',
		'x-folded' => 'first line second line',
		'subject' => 'Hello',
	], Message::fromString(buildHeaderMessage())->getHeaders());
});


test('getRaw() returns the input byte for byte, whatever its line endings', function () {
	$raw = buildHeaderMessage();
	Assert::same($raw, Message::fromString($raw)->getRaw());

	$lf = "Subject: X\n\nline one\nline two\n";
	Assert::same($lf, Message::fromString($lf)->getRaw());
});


test('a message of headers only round-trips and has an empty body', function () {
	foreach (['Subject: X', "Subject: X\r\n"] as $raw) {
		$message = Message::fromString($raw);
		Assert::same($raw, $message->getRaw());
		Assert::same($raw, $message->getRawHeaders());
		Assert::same('', $message->getContents());
		Assert::same('X', $message->getHeader('Subject'));
	}
});


test('getRawHeaders() is the header block up to and including the blank line, whatever the line endings', function () {
	$message = Message::fromString(buildHeaderMessage());
	$headers = $message->getRawHeaders();
	Assert::true(str_starts_with($headers, "Received: from second.example.com\r\n"));
	Assert::true(str_ends_with($headers, "Subject: Hello\r\n\r\n"));
	Assert::same($headers . 'body', $message->getRaw());

	$message = Message::fromString("Subject: X\n\nline one\n");
	Assert::same("Subject: X\n\n", $message->getRawHeaders());
	Assert::same("Subject: X\n\nline one\n", $message->getRaw());
});


test('getContents() of a single-part message is the body without the headers', function () {
	Assert::same('body', Message::fromString(buildHeaderMessage())->getContents());
	Assert::same("line one\nline two\n", Message::fromString("Subject: X\n\nline one\nline two\n")->getContents());
});


test('a detached message has no UID', function () {
	Assert::null(Message::fromString(buildHeaderMessage())->getUid());
});


test('a detached message counts the bytes of its string, so LF-only input comes out smaller', function () {
	$lf = "Subject: X\n\nline one\nline two\n";
	$raw = crlf($lf);
	Assert::same(strlen($raw), Message::fromString($raw)->getSize());
	Assert::same(strlen($raw) - 4, Message::fromString($lf)->getSize());
});
