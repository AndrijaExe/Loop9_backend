<?php

declare(strict_types=1);

namespace App\Application;

use App\Adapter\AI\AiProviderClient;
use App\Model\Message\Message;

final class SendToAi
{
	public function __construct(private readonly AiProviderClient $aiProviderClient)
	{
	}

	/**
	 * @param array<string, mixed> $runtimeContext
	 */
	public function __invoke(string $playerMessage, array $runtimeContext = []): Message
	{
		$trimmed = trim($playerMessage);

		if ($trimmed === '') {
			throw new \InvalidArgumentException('Message cannot be empty.');
		}

		return $this->aiProviderClient->ask($trimmed, $runtimeContext);
	}
}
