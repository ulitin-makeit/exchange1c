<?php
/**
 * Скрипт для регистрации агента рассылки ошибок проводок.
 * 
 * Запустить один раз для регистрации агента.
 * Агент будет запускаться ежедневно в 02:00.
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Agent;

\Bitrix\Main\Loader::includeModule('brs.exchange1c');

// Проверяем, не зарегистрирован ли уже агент
$agentName = '\Brs\Exchange1c\Agent\ErrorNotification::run();';
$agent = \CAgent::GetList(
	[],
	[
		'NAME' => $agentName
	]
)->Fetch();

if (!$agent) {
	// Регистрируем агент для ежедневного запуска в 02:00
	// Вычисляем время следующего запуска (сегодня в 02:00 или завтра, если уже прошло)
	$nextExec = new \Bitrix\Main\Type\DateTime();
	$hour = (int)$nextExec->format('H');
	$minute = (int)$nextExec->format('i');
	
	// Если уже прошло 02:00, запускаем завтра в 02:00
	if ($hour >= 2) {
		$nextExec->add('1 day');
	}
	
	$nextExec->setTime(2, 0, 0);
	
	$result = \CAgent::AddAgent(
		$agentName,
		'',
		'N', // не критичный агент
		86400, // интервал в секундах (24 часа)
		'', // дата первой проверки (пусто = сразу)
		'Y', // активен
		'', // время запуска (не используется)
		$nextExec->format('d.m.Y H:i:s') // дата следующего запуска
	);

	if ($result) {
		echo "Агент успешно зарегистрирован! ID: " . $result . "\n";
		echo "Агент будет запускаться ежедневно в 02:00.\n";
	} else {
		echo "Ошибка при регистрации агента!\n";
	}
} else {
	echo "Агент уже зарегистрирован! ID: " . $agent['ID'] . "\n";
}
