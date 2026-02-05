<?php

	namespace Brs\Exchange1c\Exchange\Rest;

	/**
	 * Класс синхронизирует курс валют по REST с 1С.
	 */
	class AddCurrencyExchangeRate extends Rest implements RestInterface {

		/**
		 * Отправляем запрос в 1С.
		 * 
		 * @param array $params - параметры запроса
		 * @param boolean $viewResult - отдавать успешный результат в формате true/false или отдавать полный ответ
		 * 
		 * @return type
		 */
		public function run(array $params, bool $viewResult = false){

			$this->setUrl1c('AddCurrencyExchangeRate'); // устанавливаем название метода

			return $this->sendPostJsonString($params, $viewResult); // отправляем запрос

		}

	}