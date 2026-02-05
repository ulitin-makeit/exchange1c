<?php

namespace Brs\Exchange1C\LogHandlers;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Brs\Log\Model\Orm\AccountingEntryLogTable;

class AccountingEntryLogHandler extends AbstractProcessingHandler
{
	protected function write(array $record)
	{
		\Bitrix\Main\Loader::includeModule('brs.log');
		AccountingEntryLogTable::add([
			'ACCOUNT_ENTRY_ID' => $record['context']['ACCOUNT_ENTRY_ID'],
			'STATUS' => $this->getStatus($record['level']),
			'ENTITY' => $record['context']['ENTITY'],
			'HTTP_STATUS' => $record['context']['HTTP_STATUS'],
			'RESPONSE' => $record['context']['RESPONSE'],
			'REQUEST_URL' => $record['context']['REQUEST_URL'],
			'DATA' => $record['context']['DATA'],
			'DEAL_ID' => $record['context']['DEAL_ID']
		]);
	}


	/**
	 * @param int $level
	 * @return void|string
	 */
	protected function getStatus(int $level): string
	{
		switch ($level) {
			case Logger::ERROR: return AccountingEntryLogTable::STATUS_ERROR;
			case Logger::CRITICAL: return AccountingEntryLogTable::STATUS_CRITICAL;
			case Logger::INFO: return AccountingEntryLogTable::STATUS_SUCCESS;
		}
	}
}