<?php

	namespace Brs\Exchange1c\Exchange\Rest;

	interface RestInterface {

		/**
		 * Запускает логику обработки метода.
		 * 
		 * @param array $params
		 * @param boolean $viewResult
		 */
		public function run(array $params, bool $viewResult = false);

	}

