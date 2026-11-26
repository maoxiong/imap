<?php declare(strict_types=1);

namespace DG\Imap;

use function strlen;


/**
 * Low-level IMAP protocol client over a TCP/TLS socket.
 * @internal
 */
final class Connection
{
	private const ChunkSize = 8192;

	/** @var ?resource */
	private $stream;
	private int $tagCount = 0;

	/** @var ?list<string> */
	private ?array $capabilities = null;

	/** the most bytes one response may make the client read, a guard against a broken or hostile server */
	private int $maxResponseSize = 256 * 1024 * 1024;

	/** bytes read of the current response */
	private int $received = 0;


	/**
	 * @param  resource  $stream  connected socket, positioned before the server greeting
	 */
	private function __construct($stream)
	{
		$this->stream = $stream;
		$this->readResponse(); // server greeting
	}


	/**
	 * @throws Exception  Failed to connect to the server.
	 */
	public static function connect(string $host, int $port, bool $ssl, float $timeout = 30.0): self
	{
		$stream = @stream_socket_client( // @ is escalated to exception
			($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port,
			$errorCode,
			$errorMessage,
			$timeout,
		);
		if (!$stream) {
			throw new Exception("Cannot connect to $host:$port: $errorMessage");
		}

		stream_set_timeout($stream, (int) ceil($timeout));
		return new self($stream);
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
		$this->received = 0;
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


	/**
	 * Reads in chunks, because fgets() and fread() allocate their whole length argument up front,
	 * so a length the server controls must never reach them.
	 */
	private function readLine(): string
	{
		$line = '';
		do {
			$size = min($this->maxResponseSize - $this->received, self::ChunkSize);
			if ($size <= 0) {
				$this->tooLarge();
			}
			$chunk = @fgets($this->getStream(), $size + 1); // @ is escalated to exception
			if ($chunk === false || (!str_ends_with($chunk, "\n") && strlen($chunk) < $size)) { // a partial line means timeout or EOF mid-line
				throw new Exception('Cannot read from server (connection closed or timed out)');
			}
			$this->received += strlen($chunk);
			$line .= $chunk;
		} while (!str_ends_with($chunk, "\n"));

		return $line;
	}


	private function readBytes(int $count): string
	{
		if ($count > $this->maxResponseSize - $this->received) {
			$this->tooLarge();
		}

		$data = '';
		while (($remaining = $count - strlen($data)) > 0) {
			$chunk = @fread($this->getStream(), min($remaining, self::ChunkSize)); // @ is escalated to exception
			if ($chunk === false || $chunk === '') {
				throw new Exception('Cannot read from server (connection closed or timed out)');
			}
			$data .= $chunk;
		}

		$this->received += $count;
		return $data;
	}


	/**
	 * The stream is stuck in the middle of the response, so reusing it would read data as protocol.
	 */
	private function tooLarge(): never
	{
		$this->disconnect();
		throw new Exception("Server response exceeds {$this->maxResponseSize} bytes");
	}
}
