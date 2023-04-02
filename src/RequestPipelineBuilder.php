<?php
declare(strict_types=1);

namespace Elephox\Web;

use Elephox\Collection\ArrayList;
use Elephox\Collection\EmptySequenceException;
use Elephox\DI\Contract\Resolver;
use Elephox\Support\Contract\ExceptionHandler;
use Elephox\Web\Contract\RequestPipelineEndpoint;
use Elephox\Web\Contract\WebMiddleware;
use InvalidArgumentException;

class RequestPipelineBuilder
{
	/**
	 * @var ArrayList<WebMiddleware> $pipeline
	 */
	private ArrayList $pipeline;

	public function __construct(
		private RequestPipelineEndpoint $endpoint,
		private readonly Resolver $resolver,
	) {
		/** @var ArrayList<WebMiddleware> */
		$this->pipeline = new ArrayList();
	}

	/**
	 * @param class-string<WebMiddleware>|WebMiddleware $middleware
	 */
	public function push(WebMiddleware|string $middleware): self
	{
		if (is_string($middleware)) {
			$concreteMiddleware = $this->resolver->instantiate($middleware);
			if (!($concreteMiddleware instanceof WebMiddleware)) {
				throw new InvalidArgumentException("Given middleware '$middleware' does not implement " . WebMiddleware::class);
			}
		} else {
			$concreteMiddleware = $middleware;
		}

		$this->pipeline->add($concreteMiddleware);

		return $this;
	}

	/**
	 * @param class-string<WebMiddleware>|null $className
	 */
	public function pop(?string $className = null): WebMiddleware
	{
		$predicate = $className === null ? null : static fn (WebMiddleware $middleware): bool => $middleware instanceof $className;

		return $this->pipeline->pop($predicate);
	}

	public function endpoint(RequestPipelineEndpoint $endpoint): self
	{
		$this->endpoint = $endpoint;

		return $this;
	}

	public function exceptionHandler(WebMiddleware&ExceptionHandler $exceptionHandler): self
	{
		try {
			/** @var int $key */
			$key = $this->pipeline->firstKey(static fn (WebMiddleware $middleware): bool => $middleware instanceof ExceptionHandler);

			$this->pipeline->put($key, $exceptionHandler);
		} catch (EmptySequenceException) {
			$this->pipeline->add($exceptionHandler);
		}

		return $this;
	}

	public function build(): RequestPipeline
	{
		return new RequestPipeline($this->endpoint, $this->pipeline);
	}
}
