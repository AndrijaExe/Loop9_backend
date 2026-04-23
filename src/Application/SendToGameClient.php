<?php

declare(strict_types=1);

namespace App\Application;

use App\Model\Message\Message;

final class SendToGameClient
{
	/**
	 * @return array<string, string>
	 */
	public function __invoke(Message $message): array
	{
		return [
			'role' => $message->role(),
			'message' => $message->content(),
			'createdAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
		];
	}
}
