<?php

	namespace Brs\Exchange1c\Exchange\Rest;

	use Bitrix\Main\Web\HttpClient;
	use Brs\Exchange1c\Settings\Exchange;

	/**
	 * Класс содержит логику для работы с 1С по REST.
	 */
	abstract class Rest {

		protected string $url1c = '';

		/**
		 * Метод отдаёт путь к REST методу в 1С.
		 * 
		 * @param string $method
		 * @return string or boolean
		 */
		public function getUrl1c(string $method){

			$methods = Exchange::getMethods();

			if(array_key_exists($method, $methods)){
				return $methods[$method]['URL1C'];
			} else {
				return false;
			}

		}

		/**
		 * Метод сохраняет путь к REST методу в 1С.
		 * 
		 * @param string $method
		 * @return boolean
		 */
		protected function setUrl1c(string $method){

			$this->url1c = $this->getUrl1c($method);

			if(!empty($this->url1c)){
				return true;
			} else {
				return false;
			}

		}

		/**
		 * Метод отправляет в 1С запрос ввиде JSON строки содержащий массив.
		 * 
		 * @param array $params - параметры, которые необходимо передать в метод
		 * @param boolean $viewResult - показать результат ответа от сервера 1с
		 */
		protected function sendPostJsonString(array $params, bool $viewResult = false){
			
			if(empty($this->url1c)){
				return false;
			}

			// создаём http подключение
			$http = new HttpClient(['version' => HttpClient::HTTP_1_1, 'charset' => 'utf-8']);

			$http->setHeader('Content-Type', 'application/json', true); // устанавливаем заголовок документа

			// отправляем запрос и получаем результат
			$response = $http->post($this->url1c, json_encode($params));

			if($viewResult == true){ // отдаём ответ напрямую

				if(\mb_substr($response, 0, 2) == 'ОК'){
					$status = true;
				} else {
					$status = false;
				}

				return [
					'url1C' => $this->url1c,
					'httpStatus' => $http->getStatus(),
					'response' => $response,
					'status' => $status
				];

			} else { // отдаём результат в формате bool. True в случае, если ответ успешный, else в случае, если ответ неуспешный. 
				if(\mb_substr($response, 0, 2) == 'ОК'){
					return true;
				} else {
					return false;
				}
			}

		}

	}

