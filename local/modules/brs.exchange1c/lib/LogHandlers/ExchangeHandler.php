<?php

namespace Brs\Exchange1C\LogHandlers;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Brs\Log\Model\Orm\ExchangeLogTable;

class ExchangeHandler extends AbstractProcessingHandler
{
	protected function write(array $record)
	{
		\Bitrix\Main\Loader::includeModule('brs.log');
		ExchangeLogTable::add([
			'TYPE' => $record['level'] == Logger::ERROR ? ExchangeLogTable::TYPE_ERROR : ExchangeLogTable::TYPE_INFO,
			'ENTITY' => $record['context']['ENTITY'],
			'HTTP_STATUS' => $record['context']['HTTP_STATUS'],
			'RESPONSE' => $record['context']['RESPONSE'],
			'REQUEST_URL' => $record['context']['REQUEST_URL'],
			'INIT_DATA' => $record['context']['INIT_DATA'],
			'DATA' => $record['context']['DATA'],
			'UID' => $record['context']['UID']
		]);
	}
}