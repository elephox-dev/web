<?php
declare(strict_types=1);

namespace Elephox\Web;

use Elephox\Configuration\ConfigurationManager;
use Elephox\Configuration\Contract\Configuration;
use Elephox\Configuration\Contract\ConfigurationBuilder as ConfigurationBuilderContract;
use Elephox\Configuration\Contract\ConfigurationManager as ConfigurationManagerContract;
use Elephox\Configuration\Contract\Environment;
use Elephox\Configuration\LoadsDefaultConfiguration;
use Elephox\DI\Contract\ServiceCollection as ServiceCollectionContract;
use Elephox\DI\ServiceCollection;
use Elephox\Http\Contract\Request as RequestContract;
use Elephox\Http\Contract\ResponseBuilder;
use Elephox\Http\Response;
use Elephox\Http\ResponseCode;
use Elephox\Support\Contract\ErrorHandler;
use Elephox\Support\Contract\ExceptionHandler;
use Elephox\Web\Contract\RequestPipelineEndpoint;
use Elephox\Web\Contract\WebEnvironment;
use Elephox\Web\Middleware\DefaultExceptionHandler;
use Elephox\Web\Middleware\FileExtensionToContentType;
use Elephox\Web\Middleware\ServerTimingHeaderMiddleware;
use Elephox\Web\Middleware\StaticContentHandler;
use Elephox\Web\Routing\RequestRouter;
use Throwable;

/**
 * @psalm-consistent-constructor
 */
class WebApplicationBuilder
{
	use LoadsDefaultConfiguration;

	public static function create(
		?ServiceCollectionContract $services = null,
		?ConfigurationManagerContract $configuration = null,
		?WebEnvironment $environment = null,
		?RequestPipelineBuilder $pipeline = null,
	): static {
		$configuration ??= new ConfigurationManager();
		$environment ??= new GlobalWebEnvironment();
		$services ??= new ServiceCollection();

		$defaultEndpoint = new class implements RequestPipelineEndpoint {
			public function handle(RequestContract $request): ResponseBuilder
			{
				return Response::build()->responseCode(ResponseCode::BadRequest);
			}
		};
		$pipeline ??= new RequestPipelineBuilder($defaultEndpoint, $services->resolver());

		$services->addSingleton(Environment::class, instance: $environment);
		$services->addSingleton(WebEnvironment::class, instance: $environment);
		$services->addSingleton(Configuration::class, instance: $configuration);

		return new static(
			$configuration,
			$environment,
			$services,
			$pipeline,
		);
	}

	public function __construct(
		public readonly ConfigurationManagerContract $configuration,
		public readonly WebEnvironment $environment,
		public readonly ServiceCollectionContract $services,
		public readonly RequestPipelineBuilder $pipeline,
	) {
		// Load .env, .env.local
		$this->loadDotEnvFile();

		// Load config.json, config.local.json
		$this->loadConfigFile();

		// Load .env.{$ENVIRONMENT}, .env.{$ENVIRONMENT}.local
		$this->loadEnvironmentDotEnvFile();

		// Load config.{$ENVIRONMENT}.json, config.{$ENVIRONMENT}.local.json
		$this->loadEnvironmentConfigFile();

		$this->addDefaultMiddleware();

		$this->addDefaultExceptionHandler();
	}

	protected function getEnvironment(): WebEnvironment
	{
		return $this->environment;
	}

	protected function getServices(): ServiceCollectionContract
	{
		return $this->services;
	}

	protected function getPipeline(): RequestPipelineBuilder
	{
		return $this->pipeline;
	}

	protected function getConfigurationBuilder(): ConfigurationBuilderContract
	{
		return $this->configuration;
	}

	protected function addDefaultMiddleware(): void
	{
		$this->pipeline->push(new ServerTimingHeaderMiddleware('pipeline'));
		$this->pipeline->push(new FileExtensionToContentType());
		$this->pipeline->push(new StaticContentHandler($this->getEnvironment()->getWebRoot()));
	}

	public function addDefaultExceptionHandler(): void
	{
		$handler = new DefaultExceptionHandler();

		$this->getServices()->addSingleton(ExceptionHandler::class, instance: $handler);
		$this->pipeline->exceptionHandler($handler);
	}

	public function build(): WebApplication
	{
		$configuration = $this->configuration->build();
		$this->services->addSingleton(Configuration::class, instance: $configuration, replace: true);

		$builtPipeline = $this->pipeline->build();
		$this->services->addSingleton(RequestPipeline::class, instance: $builtPipeline, replace: true);

		if ($this->services->has(ExceptionHandler::class)) {
			set_exception_handler(function (Throwable $exception): void {
				$this->services->requireService(ExceptionHandler::class)
					->handleException($exception)
				;
			});
		}

		if ($this->services->has(ErrorHandler::class)) {
			set_error_handler(
				function (int $severity, string $message, string $file, int $line): bool {
					return $this->services->requireService(ErrorHandler::class)
						->handleError($severity, $message, $file, $line)
					;
				},
			);
		}

		return new WebApplication(
			$this->services,
			$configuration,
			$this->environment,
			$builtPipeline,
		);
	}

	public function setRequestRouterEndpoint(?RequestRouter $router = null): RequestRouter
	{
		$router ??= new RequestRouter($this->services);
		$this->services->addSingleton(RequestRouter::class, instance: $router, replace: true);
		$this->pipeline->endpoint($router);

		return $router;
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T>|string $name
	 *
	 * @psalm-suppress InvalidReturnType psalm is unable to verify T as the return type
	 *
	 * @return T
	 */
	public function service(string $name): object
	{
		/** @var T */
		return $this->services->require($name);
	}
}
