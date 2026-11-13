<?php
/**
 * Lecture / écriture de la configuration du TPE (tpe.ini à la racine du connecteur)
 *
 * Format écrit (fins de ligne CRLF : lisible dans le Bloc-notes Windows comme sous Linux) :
 *   [tpe]
 *   ip = 192.168.1.50
 *   port = 8888
 *
 * L'ancien format (ligne 1 = IP, ligne 2 = port) reste accepté en lecture.
 */

/**
 * Chemin du fichier de configuration
 */
function tpe_conf_file()
{
	return dirname(__DIR__).DIRECTORY_SEPARATOR.'tpe.ini';
}

/**
 * Retourne array('ip' => ..., 'port' => ...), valeurs vides si absentes
 */
function tpe_conf_read()
{
	$conf = array('ip' => '', 'port' => '');
	$content = @file_get_contents(tpe_conf_file());
	if ($content === false) {
		return $conf;
	}
	// BOM UTF-8 éventuellement ajouté par un éditeur Windows
	$content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

	$ini = @parse_ini_string($content, false, INI_SCANNER_RAW);
	if (is_array($ini) && isset($ini['ip'])) {
		$conf['ip'] = trim($ini['ip'], " \t\"'");
		$conf['port'] = isset($ini['port']) ? trim($ini['port'], " \t\"'") : '';
	} else {
		// Ancien format : IP sur la ligne 1, port sur la ligne 2
		$lines = preg_split('/\r\n|\r|\n/', trim($content));
		$conf['ip'] = isset($lines[0]) ? trim($lines[0]) : '';
		$conf['port'] = isset($lines[1]) ? trim($lines[1]) : '';
	}
	return $conf;
}

/**
 * Enregistre la configuration. Retourne false en cas d'échec d'écriture.
 */
function tpe_conf_write($ip, $port)
{
	$content = "; Configuration du TPE (Caisse-AP over IP)\r\n"
		."[tpe]\r\n"
		."ip = ".$ip."\r\n"
		."port = ".$port."\r\n";
	return file_put_contents(tpe_conf_file(), $content) !== false;
}
