<?php
declare(strict_types=1);

namespace Elephox\Web\Commands;

use Elephox\Configuration\Contract\Environment;
use Elephox\Configuration\MemoryEnvironment;
use Elephox\Console\Command\CommandInvocation;
use Elephox\Console\Command\CommandTemplateBuilder;
use Elephox\Console\Command\Contract\CommandHandler;
use Elephox\Files\Contract\FileChangedEvent;
use Elephox\Files\FileWatcher;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use ricardoboss\Console;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

readonly class ServeCommand implements CommandHandler
{
	public function __construct(
		private LoggerInterface $logger,
		private Environment $environment,
	) {
	}

	public function configure(CommandTemplateBuilder $builder): void
	{
		$builder
			->setName('serve')
			->setDescription('Starts the PHP built-in webserver for your application')
		;
		$builder->addArgument('host')
			->setDefault($this->environment['SERVER_HOST'] ?? 'localhost')
			->setDescription('Host to bind to')
		;
		$builder->addArgument('port')
			->setDefault($this->environment['SERVER_PORT'] ?? '8000')
			->setDescription('Port to bind to (>=1, <=65535)')
			->setValidator(static fn (mixed $val) => is_string($val) && ctype_digit($val) && $val >= 1 && $val <= 65535 ? true : 'Port must be a number between 1 and 65535')
		;
		$builder->addOption('root', default: null, description: 'Root directory to serve from');
		$builder->addOption('env', default: 'development', description: 'The environment to use (e.g. development, staging or production)');
		$builder->addOption('router', default: null, description: 'The router script to use');
		$builder->addOption('workers', default: 'auto', description: 'How many threads to use for the PHP server (PHP_CLI_SERVER_WORKERS)');
		$builder->addOption('no-reload', description: 'Whether to restart the server upon env file changes');
		$builder->addOption('verbose', 'v', description: 'Whether to print debug output');
	}

	public function handle(CommandInvocation $command): ?int
	{
		$host = $command->arguments->get('host')->string();
		$port = $command->arguments->get('port')->int();
		$root = $command->options->get('root')->nullableString() ?? ($this->environment->root()->directory('public')->path());
		$router = $command->options->get('router')->nullableString() ?? (dirname(__DIR__, 2) . '/data/router.php');
		$noReload = $command->options->get('no-reload')->bool();
		$verbose = $command->options->get('verbose')->bool();

		if (!is_dir($root)) {
			throw new InvalidArgumentException("Root directory ($root) does not exist");
		}

		$documentRoot = realpath($root);
		if (!is_string($documentRoot)) {
			throw new RuntimeException('Unable to resolve document root');
		}

		if ($router === 'null') {
			$router = null;
		} else {
			$router = realpath($router);
			if ($router === false) {
				throw new InvalidArgumentException('Given router file does not exist');
			}
		}

		$environment = $this->getEnvironment(dirname($documentRoot), $command);

		$serverCommand = [(new PhpExecutableFinder())->find(false), '-S', "$host:$port", '-t', $documentRoot];
		if ($router) {
			$serverCommand[] = $router;
		}

		$this->logger->info('Starting PHP built-in webserver on ' . Console::link('http://' . $host . ':' . $port), ['command' => implode(' ', $serverCommand)]);

		$process = $this->startServerProcess($serverCommand, $documentRoot, $environment, $verbose);

		$onEnvFileChanged = function (FileChangedEvent $fileChangedEvent) use (&$process, $serverCommand, $documentRoot, $environment, $verbose): void {
			$this->logger->warning($fileChangedEvent->file()->name() . ' file changed. Restarting server...');

			$environment->loadFromEnvFile($fileChangedEvent->file());

			/** @var Process $process */
			$process->stop();

			usleep(1000 * 1000);

			$process = $this->startServerProcess($serverCommand, $documentRoot, $environment, $verbose);
		};

		$fileWatcher = new FileWatcher();
		$fileWatcher->add(
			$onEnvFileChanged,
			$environment->getDotEnvFileName(),
			$environment->getDotEnvFileName(true),
			$environment->getDotEnvFileName(envName: (string) $environment['APP_ENV']),
			$environment->getDotEnvFileName(true, (string) $environment['APP_ENV']),
		);
		$fileWatcher->poll(false);

		/** @var Process $process */
		while ($process->isRunning()) {
			if (!$noReload) {
				$fileWatcher->poll();
			}

			usleep(500 * 1000);
		}

		$this->logger->warning(sprintf('Server process exited with code %s', $process->getExitCode() ?? '<unknown>'));

		return 0;
	}

	private function startServerProcess(array $serverCommand, string $documentRoot, Environment $environment, bool $verbose): Process
	{
		$process = new Process(
			$serverCommand,
			$documentRoot,
			$environment->asEnumerable()->toArray(),
		);

		$process->start(function (string $type, string $buffer) use ($verbose): void {
			$buffer = trim($buffer);
			foreach (explode("\n", $buffer) as $line) {
				if ($closingBracketPos = strpos($line, ']')) {
					$line = substr($line, $closingBracketPos + 2);
				}

				if (preg_match('/^(?<ip>.+):(?<port>\d{1,5}) (?:(?<action>Accepted|Closing)|\[(?<status>\d{3})]: (?<verb>\S+) (?<path>.*))$/i', $line, $matches)) {
					if (isset($matches['action']) && !empty($matches['action'])) {
						if ($verbose) {
							$this->logger->debug(sprintf('%s connection at %s:%d', $matches['action'], $matches['ip'], $matches['port']));
						}
					} else {
						$this->logger->info(sprintf('%s %s -> %d', $matches['verb'], $matches['path'], $matches['status']), ['ip' => $matches['ip'], 'port' => $matches['port']]);
					}
				} else {
					$this->logger->notice($line);
				}
			}
		});

		$this->logger->info('Server process started', ['pid' => $process->getPid()]);

		return $process;
	}

	private function getEnvironment(string $documentRoot, CommandInvocation $command): MemoryEnvironment
	{
		$envName = $command->options->get('env')->string();
		$workers = $command->options->get('workers')->value;

		$environment = new MemoryEnvironment($documentRoot);
		$envFile = $environment->getDotEnvFileName();
		$environment->loadFromEnvFile($envFile);
		$localEnvFile = $environment->getDotEnvFileName();
		$environment->loadFromEnvFile($localEnvFile);

		if ($envName !== 'null') {
			$environment['APP_ENV'] = $envName;

			$namedEnvFile = $environment->getDotEnvFileName();
			$environment->loadFromEnvFile($namedEnvFile);
			$localNamedEnvFile = $environment->getDotEnvFileName(true);
			$environment->loadFromEnvFile($localNamedEnvFile);
		}

		if (is_string($workers)) {
			if (ctype_digit($workers)) {
				$environment['PHP_CLI_SERVER_WORKERS'] = (int) $workers;
			} elseif ($workers === 'auto') {
				if (PHP_OS_FAMILY === 'Windows') {
					$procCountCommand = 'echo %NUMBER_OF_PROCESSORS%';

					$this->logger->warning('PHP_CLI_SERVER_WORKERS is not supported by PHP on Windows but will be set anyway.');
				} else {
					$procCountCommand = 'nproc';
				}

				exec($procCountCommand, $cores, $code);

				if ($code === 0 && isset($cores[0]) && ctype_digit((string) $cores[0])) {
					$environment['PHP_CLI_SERVER_WORKERS'] = (int) $cores[0];
				} else {
					throw new RuntimeException("Unable to determine number of cores available (used: $procCountCommand)");
				}
			} elseif ($workers !== 'null') {
				throw new InvalidArgumentException('Workers must be a number, "auto" or "null"');
			}
		}

		return $environment;
	}
}
