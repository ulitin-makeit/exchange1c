<?php

namespace Brs\Exchange1c;

use Bitrix\Main\Web\HttpClient;

abstract class Collector
{
	/** Строка содержащая название сущности для класса Settings, необходимо указывать в унаследованных классах */
	const ENTITY = '';

	/** @var bool обмен можно отключить переведя эту переменную в false */
	protected static bool $exchangeIsActive = true;

	/** @var string|int|array нужно для попадания в лог данных, с которых начался обмен */
	protected static $initData = null;
	protected static string $uid = '';

	public function handler($data1, $data2 = null)
	{
		if (!self::$exchangeIsActive || !static::$exchangeIsActive || Settings\Exchange::exchangeIsActive() != 'Y'
			|| Settings\Exchange::getEntities()[static::ENTITY]['IS_ACTIVE'] != 'Y') {
			return;
		}
		$data = static::prepareHandlerData($data1,$data2);
		if (Settings\Exchange::getEntities()[static::ENTITY]['DEFERRED'] == 'Y' && $data) {
			\CAgent::AddAgent(
				static::class.'::execute('.var_export($data,true).');',
				'brs.exchange1c',
				'Y',
				300,
				"",
				'Y',
				date('d.m.Y H:i:s', mktime(date('H'), date('i')+5, date('s'), date('m'), date('d'), date('Y'))),
			);
			return;
		}
		static::execute($data);
	}

	public function execute($data)
	{
		try {
			static::$initData = var_export($data, true);
			$data = static::getData($data);
			if (!$data) {
				return;
			}
			$data = static::formatData($data);
			static::$uid = $data['UID'] ?? '';
			$data = static::prepareData($data);
			static::sendValues($data);
		} catch (\Throwable $error) {
			$message = $error->getMessage();
			\Monolog\Registry::getInstance('exchange1cError')->error(
				'Обмен 1с(brs.exchange1c)' . ($message ? ', ' : '') . $message,
				[
					'TRACE' => $error->getTraceAsString(),
					'ENTITY' => static::ENTITY,
					'INIT_DATA' => static::$initData,
					'DATA' => $data
				]
			);
		}
	}

	public function sendValues($value)
	{
		if (!Settings\Exchange::getEntities()[static::ENTITY]['URL1C'] || !$value) {
			return;
		}
		$http = new HttpClient(['version' => HttpClient::HTTP_1_1, 'charset' => 'utf-8']);
		$http->setHeader('Content-Type', 'application/json', true);
		$response = $http->post(Settings\Exchange::getEntities()[static::ENTITY]['URL1C'], $value);
		$logData = [
			'ENTITY' => static::ENTITY,
			'HTTP_STATUS' => $http->getStatus(),
			'RESPONSE' => $response,
			'REQUEST_URL' => Settings\Exchange::getEntities()[static::ENTITY]['URL1C'],
			'INIT_DATA' => static::$initData,
			'DATA' => $value,
			'UID' => static::$uid
		];
		if (!$response || $http->getStatus() != 200) {
			\Monolog\Registry::getInstance('exchange1c')->error('Ошибка обмена 1с(brs.exchange1c)', $logData);
		} elseif (Settings\Exchange::logEverything() == 'Y') {
			\Monolog\Registry::getInstance('exchange1c')->info('Обмен 1с успешен(brs.exchange1c)', $logData);
		}
	}

	/** подготовка данных события */
	abstract function prepareHandlerData($data1, $data2 = null);

	/** запрос из базы недостающих данных */
	abstract function getData($data);

	/** форматирование данных в нужный вид для 1С */
	abstract function formatData($data): array;

	/** форматирование массива в json строку */
	function prepareData($data): string
	{
		return \Bitrix\Main\Web\Json::encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
	}

	public static function stopExchange(): bool
	{
		static::$exchangeIsActive = false;
		return true;
	}

	public static function startExchange(): bool
	{
		static::$exchangeIsActive = true;
		return true;
	}
}