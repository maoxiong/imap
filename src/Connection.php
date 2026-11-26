<?php declare(strict_types=1);

namespace DG\Imap;

use function strlen;


/**
 * Low-level IMAP protocol client over a TCP/TLS socket.
 * @internal
 */
final class Connection
{
	/** TLS versions for both implicit TLS and STARTTLS: PHP's own ssl:// default, set once so that the two never differ */
	private const CryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;

	/** @var ?resource */
	private $stream;
	private int $tagCount = 0;
	private readonly string $greeting;

	/** @var ?list<string> */
	private ?array $capabilities = null;


	/**
	 * @param  resource  $stream  connected socket, positioned before the server greeting
	 */
	private function __construct($stream)
	{
		$this->stream = $stream;
		$this->greeting = $this->readResponse();
	}


	/**
	 * Connects over implicit TLS ($ssl), a plain connection upgraded by STARTTLS ($startTls), or plain TCP.
	 * Without $validateCert the server's certificate is not checked, so anyone in between can read the password.
	 * @throws Exception  Failed to connect to the server.
	 */
	public static function connect(
		string $host,
		int $port,
		bool $ssl,
		float $timeout = 30.0,
		bool $validateCert = true,
		bool $startTls = false,
	): self
	{
		$context = stream_context_create(['ssl' => [
			'crypto_method' => self::CryptoMethod,
			'verify_peer' => $validateCert,
			'verify_peer_name' => $validateCert,
			'peer_name' => $host,
		]]);
		$stream = @stream_socket_client( // @ is escalated to exception
			($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port,
			$errorCode,
			$errorMessage,
			$timeout,
			STREAM_CLIENT_CONNECT,
			$context,
		);
		if (!$stream) {
			throw new Exception("Cannot connect to $host:$port: $errorMessage");
		}

		stream_set_timeout($stream, (int) ceil($timeout));
		$connection = new self($stream);
		if ($startTls) {
			$connection->startTls();
		}

		return $connection;
	}


	/**
	 * Creates a connection over an already opened stream, for testing against a scripted dialogue.
	 * @param  resource  $stream
	 */
	public static function fromStream($stream): self
	{
		return new self($stream);
	}


	/**
	 * Sends a tagged command and returns the untagged response lines, with literals inlined.
	 * @return list<string>
	 * @throws Exception  The server rejected the command.
	 */
	public function command(string $command): array
	{
		$tag = 'T' . ++$this->tagCount;
		$this->write("$tag $command\r\n");
		$lines = [];
		while (true) {
			$line = $this->readResponse();
			if (str_starts_with($line, "$tag ")) {
				return str_starts_with($line, "$tag OK")
					? $lines
					: throw new Exception('Command failed: ' . trim(substr($line, strlen($tag) + 1)));
			}
			$lines[] = $line;
		}
	}


	/**
	 * Upgrades the plain connection to TLS (STARTTLS, RFC 3501). Anything short of a clean upgrade
	 * closes the connection and throws, so the session never goes on in plaintext.
	 * @throws Exception
	 */
	public function startTls(): void
	{
		try {
			if (!preg_match('~^\* OK\b~i', $this->greeting)) { // PREAUTH is authenticated already, STARTTLS comes too late
				throw new Exception('Server greeting does not allow STARTTLS: ' . trim($this->greeting));
			} elseif (!$this->hasCapability('STARTTLS')) {
				throw new Exception('Server does not offer STARTTLS');
			}

			$this->command('STARTTLS');
			$stream = $this->getStream();
			if (stream_get_meta_data($stream)['unread_bytes'] > 0) { // plaintext already buffered would pass for data received over TLS
				throw new Exception('Server sent data before the TLS handshake');
			}

			error_clear_last();
			if (!@stream_socket_enable_crypto($stream, true, self::CryptoMethod)) { // @ is escalated to exception
				throw new Exception('TLS negotiation failed: ' . (error_get_last()['message'] ?? 'unknown error'));
			}

			$this->capabilities = null; // what the server announced before TLS must not be trusted

		} catch (Exception $e) {
			$this->disconnect();
			throw $e;
		}
	}


	/**
	 * Checks whether the server announced the given capability, e.g. 'MOVE' or 'UIDPLUS'.
	 */
	public function hasCapability(string $capability): bool
	{
		if ($this->capabilities === null) {
			$this->capabilities = [];
			foreach ($this->command('CAPABILITY') as $line) {
				if (preg_match('~^\* CAPABILITY (.*)~i', trim($line), $m)) {
					$this->capabilities = preg_split('~\s+~', strtoupper($m[1]), -1, PREG_SPLIT_NO_EMPTY);
				}
			}
		}

		return in_array(strtoupper($capability), $this->capabilities, true);
	}


	/**
	 * Quotes a string for use as a command argument.
	 */
	public static function quote(string $value): string
	{
		if (preg_match('~[\r\n]~', $value)) {
			throw new Exception('Value must not contain line breaks');
		}

		return '"' . addcslashes($value, '"\\') . '"';
	}


	/**
	 * Extracts the first {n} literal from a response line. Returns [literal, line without the literal],
	 * or null if the line contains no literal.
	 * @return ?array{string, string}
	 */
	public static function parseLiteral(string $line): ?array
	{
		if (!preg_match('~\{(\d+)\}\r\n~', $line, $m, PREG_OFFSET_CAPTURE)) {
			return null;
		}

		$offset = $m[0][1] + strlen($m[0][0]);
		$length = (int) $m[1][0];
		return [
			substr($line, $offset, $length),
			substr($line, 0, $m[0][1]) . substr($line, $offset + $length),
		];
	}


	public function disconnect(): void
	{
		if ($this->stream) {
			fclose($this->stream);
			$this->stream = null;
		}
	}


	/**
	 * @return resource
	 */
	private function getStream()
	{
		return $this->stream ?? throw new Exception('Connection is closed');
	}


	private function write(string $data): void
	{
		if (@fwrite($this->getStream(), $data) !== strlen($data)) { // @ is escalated to exception
			throw new Exception('Cannot write to server');
		}
	}


	/**
	 * Reads one logical response line; a {n} literal at the end of a line is followed
	 * by n bytes of data and a continuation of the same logical line.
	 */
	private function readResponse(): string
	{
		$line = $this->readLine();
		while (preg_match('~\{(\d+)\}\r\n$~', $line, $m)) {
			$line .= $this->readBytes((int) $m[1]) . $this->readLine();
		}
		return $line;
	}


	private function readLine(): string
	{
		$line = @fgets($this->getStream()); // @ is escalated to exception
		if ($line === false || !str_ends_with($line, "\n")) { // a partial line means timeout or EOF mid-line
			throw new Exception('Cannot read from server (connection closed or timed out)');
		}
		return $line;
	}


	private function readBytes(int $count): string
	{
		$data = '';
		while (($remaining = $count - strlen($data)) > 0) {
			$chunk = @fread($this->getStream(), $remaining); // @ is escalated to exception
			if ($chunk === false || $chunk === '') {
				throw new Exception('Cannot read from server (connection closed or timed out)');
			}
			$data .= $chunk;
		}

		return $data;
	}
}
