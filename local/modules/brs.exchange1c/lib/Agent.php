<?php

namespace Brs\Exchange1c;

use Brs\Exchange1c\Services\AccountingEntryService;

class Agent
{
	public static function AccountingEntry(): string
	{
		AccountingEntryService::sendEntries();

		return __METHOD__ . '();';
	}
}