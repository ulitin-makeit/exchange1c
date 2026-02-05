<?php

namespace Brs\Exchange1C\LogHandlers;

use Bitrix\Main\Web\Json;
use Monolog\Handler\AbstractProcessingHandler;
use Brs\Log\Model\Orm\ExchangeErrorTable;

class ExchangeErrorHandler extends AbstractProcessingHandler
{
	protected function write(array $record)
	{
		\Bitrix\Main\Loader::includeModule('brs.log');
		$trace = $record['context']['TRACE'];
		unset($record['context']['TRACE']);
		ExchangeErrorTable::add([
			'MESSAGE' => $record['message'] ?? 'Ошибка',
			'DATA' => Json::encode($record['context'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),
			'TRACE' => $trace
		]);
	}
}