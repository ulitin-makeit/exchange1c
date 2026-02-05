<?php

	namespace Brs\Exchange1c\Exchange;

	use Brs\Exchange1C\Models\ExchangeRestMethodTable;

	/**
	 * Класс обёртка реализует общую логику для работы с обработчиками методов 1С.
	 */
	class Sync {

		/**
		 * Запускаем обработчик метода, отправляем данные в 1С, получаем ответ из обработчика.
		 * 
		 * @param string $method
		 * @param array $params
		 * 
		 * @return type
		 */
		static public function method(string $method, array $params, bool $viewResult = false){

			$namespaceClass = 'Brs\\Exchange1c\\Exchange\\Rest\\'.$method;

			$handler = new $namespaceClass;

			return $handler->run($params, $viewResult);
			
		}
		
		/**
		 * Метод добавляет информацию в очередь. Агент по итогу обработает этот метод в очереди и запустит его.
		 * 
		 * @param string $method
		 * @param array $params
		 * 
		 * @return boolean
		 */
		static public function addQueueMethod(string $method, array $params){

			$namespaceClass = 'Brs\\Exchange1c\\Exchange\\Rest\\'.$method;

			// получаем путь к методу в 1С
			$handler = new $namespaceClass;

			$requestUrl = $handler->getUrl1c($method);

			$restMethod = ExchangeRestMethodTable::getList([
				'filter' => [
					'METHOD' => $method,
					'DATA' => json_encode($params)
 				]
			]);

			if($restMethod->getSelectedRowsCount() > 0){
				return false;
			}

			// создаём очередь
			$restMethod = ExchangeRestMethodTable::createObject();

			$restMethod->setStatus(ExchangeRestMethodTable::STATUS_WAIT);
			$restMethod->setMethod($method);
			$restMethod->setHttpStatus(0);
			$restMethod->setRequestUrl($requestUrl);
			$restMethod->setData(json_encode($params));
			$restMethod->setAttempt(0);

			$restMethod->save();

			return true;

		}

	}
