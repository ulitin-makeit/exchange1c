<?php

	namespace Brs\Exchange1c\Agent;

	use Brs\Exchange1c\Exchange\Sync;
	use Brs\Exchange1C\Models\ExchangeRestMethodTable;

	/**
	 * Агент обрабатывает очередь запросов к REST методам 1С.
	 */
	class ExchangeRestMethod {

		/**
		 * Инициализируем агент.
		 * 
		 * @return string
		 */
		public static function init(): string {

			self::handlerQueueMethodList(); // обрабатываем очередь запросов в 1С

			return __METHOD__ . '();';

		}

		/**
		 * Метод обрабатывает очередь и отправляет данные в 1С.
		 */
		public static function handlerQueueMethodList(){

			// получаем список позиций в очереди
			$exchangeRestMethodCollection = ExchangeRestMethodTable::getList([
				'order' => ['ID' => 'ASC'],
				'filter' => [
					'STATUS' => ExchangeRestMethodTable::STATUS_WAIT
				]
			]);

			if($exchangeRestMethodCollection->getSelectedRowscount() == 0){
				return true;
			}

			// получаем коллекцию позиций в очереди
			$exchangeRestMethodCollection = $exchangeRestMethodCollection->fetchCollection();
			
			foreach($exchangeRestMethodCollection as $exchangeRestMethod){

				// осуществляем запрос в 1С
				$response = Sync::method($exchangeRestMethod->getMethod(), json_decode($exchangeRestMethod->getData(), true), true);

				$exchangeRestMethod->setHttpStatus($response['httpStatus']);
				$exchangeRestMethod->setResponse($response['response']);

				$exchangeRestMethod->setAttempt($exchangeRestMethod->getAttempt() + 1);

				// если статус с ошибкой, то осуществляем попытку
				if($response['status'] == false){

					if($exchangeRestMethod->getAttempt() < 5){
						$exchangeRestMethod->setStatus(ExchangeRestMethodTable::STATUS_WAIT);
					} else { // если попытки были безуспешные, то выводим критическую ошибку
						$exchangeRestMethod->setStatus(ExchangeRestMethodTable::STATUS_CRITICAL);
					}

				} else {
					$exchangeRestMethod->setStatus(ExchangeRestMethodTable::STATUS_SUCCESS);
				}

				$exchangeRestMethod->setDateUpdate((new \DateTime())->format('d.m.Y H:i:s'));

				$exchangeRestMethod->save();

			}

		}
		
	}
