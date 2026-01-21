<?php
/*
 * Part of the "furqansiddiqui/secp256k1-socket-php" package.
 * @link https://github.com/furqansiddiqui/secp256k1-socket-php
 */

declare(strict_types=1);

namespace FurqanSiddiqui\Crypto\Secp256k1\Server;

use Charcoal\Base\Support\ErrorHelper;
use Charcoal\Console\Ansi\AnsiDecorator;
use FurqanSiddiqui\Crypto\Secp256k1\Exception\InvalidConfigException;
use FurqanSiddiqui\Crypto\Secp256k1\Exception\Secp256k1SocketException;
use FurqanSiddiqui\Crypto\Secp256k1\Exception\SocketCreateException;
use FurqanSiddiqui\Crypto\Secp256k1\Exception\WorkerSpawnException;

final class Secp256k1Server
{
    /** @var resource|null */
    private $socket = null;
    private ?array $workers = [];
    private bool $terminated = false;

    /**
     * @throws InvalidConfigException
     * @throws SocketCreateException
     */
    public function __construct(
        public readonly string $socketFilepath,
        public readonly int    $workersCount = 6,
        public readonly bool   $useAnsiEscapeCodes = true
    )
    {
        if ($this->workersCount < 1) {
            throw new InvalidConfigException("Workers count must be greater than 0");
        }

        // Socket Filepath Check
        if (strlen($this->socketFilepath) > 100) {
            throw new InvalidConfigException("Socket file path too long");
        }

        error_clear_last();
        $dir = dirname($this->socketFilepath);
        if (!@is_dir($dir) || !@is_writable($dir)) {
            throw new InvalidConfigException("Socket file directory is not writable",
                previous: ErrorHelper::lastErrorToRuntimeException());
        }

        // Delete stale IPC unix socket file
        if (file_exists($this->socketFilepath)) {
            if (filetype($this->socketFilepath) !== "socket") {
                throw new SocketCreateException("Existing socket file is not a socket file");
            }

            if (!@unlink($this->socketFilepath)) {
                throw new SocketCreateException("Failed to remove existing socket file",
                    previous: ErrorHelper::lastErrorToRuntimeException());
            }
        }

        // Setup PCNTL Signal Handlers
        pcntl_signal(SIGTERM, [$this, "terminate"]);
        pcntl_signal(SIGINT, [$this, "terminate"]);
        pcntl_signal(SIGHUP, [$this, "terminate"]);
        pcntl_signal(SIGQUIT, [$this, "terminate"]);
        pcntl_signal(SIGALRM, [$this, "terminate"]);

        // Create IPC socket server
        $this->socket = @stream_socket_server("unix://" . $this->socketFilepath, $errNo, $errStr);
        if (!$this->socket) {
            throw new SocketCreateException(
                sprintf("Failed to create stream socket; %s (Code: %d)", $errStr, $errNo),
                previous: ErrorHelper::lastErrorToRuntimeException()
            );
        }

        $this->writeLog(sprintf("{green}Secp256k1 Server{/} started with PID {magenta}%d{/}",
            getmypid()));
    }

    /**
     * @return never
     * @throws Secp256k1SocketException
     * @throws WorkerSpawnException
     */
    public function run(): never
    {
        // Create Workers
        for ($i = 0; $i < $this->workersCount; $i++) {
            $this->spawnWorkerProcess();
        }

        while (true) {
            if (!$this->workers) {
                break;
            }

            pcntl_signal_dispatch();
            $workerExited = pcntl_wait($status);
            pcntl_signal_dispatch();
            if ($workerExited === -1) {
                $pcntlError = pcntl_get_last_error();
                if ($pcntlError !== PCNTL_EINTR) {
                    throw new Secp256k1SocketException("PCNTL wait failed; " . pcntl_strerror($pcntlError));
                }

                continue;
            }

            if ($workerExited > 0) {
                unset($this->workers[$workerExited]);
                if (!$this->terminated) {
                    $this->spawnWorkerProcess();
                }
            }
        }

        $this->writeLog(sprintf("{green}Secp256k1 Server{/} on PID {red}%d{/} terminated!", getmypid()));
        exit(0);
    }

    /**
     * @return void
     * @throws WorkerSpawnException
     */
    private function spawnWorkerProcess(): void
    {
        $workerPid = pcntl_fork();
        if ($workerPid === -1) {
            throw new WorkerSpawnException("Failed to spawn a worker process");
        } else if ($workerPid === 0) {
            $this->workers = null;
            $this->writeLog(sprintf("{cyan}Secp256k1 Worker{/} spawned with PID {magenta}%d{/}", getmypid()));
            while (true) {
                pcntl_signal_dispatch();
                $client = @stream_socket_accept($this->socket, 1);
                if (!$client) {
                    continue;
                }

                // Todo: read the request
                // Todo: process the request
                // Todo: Logging as per request
                // Todo: Terminate worker process (response/exception)
            }

            // Fail-safe exiting:
            exit(0);
        }

        // Register the worker PID
        $this->workers[$workerPid] = true;
    }

    /**
     * @param int $sigId
     * @return void
     */
    private function terminate(int $sigId): void
    {
        $this->terminated = true;
        if ($this->workers !== null) {
            foreach (array_keys($this->workers) as $workerPid) {
                posix_kill($workerPid, SIGTERM);
            }
        }

        if ($this->workers === null) {
            $this->writeLog(sprintf("{cyan}Secp256k1 Worker{/} on PID {blue}%d{/} terminated: {red}%d{/}",
                getmypid(), $sigId));
            exit(0);
        }
    }

    /**
     * @param string $message
     * @param bool $eol
     * @return void
     */
    private function writeLog(string $message, bool $eol = true): void
    {
        fwrite(STDERR, AnsiDecorator::parse($message, strip: !$this->useAnsiEscapeCodes)
            . ($eol ? PHP_EOL : ""));
    }
}