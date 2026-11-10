<?php
/**
 * Passerelle TakePOS -> TPE en protocole Caisse-AP over IP (Concert V3)
 *
 * Appel : POST tpe/index.php
 *   amount   : montant en euros (ex : 12.50 ou 12,50)       [obligatoire]
 *   action   : debit (défaut) | refund                      [facultatif]
 *   currency : code ISO 4217 numérique, 978 = EUR (défaut)   [facultatif]
 *   pos      : numéro de caisse sur 2 chiffres, 01 (défaut)   [facultatif]
 *
 * Retour JSON : {"status":"OK"|"KO"|"ERROR", "message":"...", "amount":"12.50", "response":{tags TPE}}
 *
 * Configuration : ../tpe.ini (ligne 1 = IP, ligne 2 = port), saisie dans index.php?page=tpe
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(200);
	exit;
}
header('Content-Type: application/json; charset=utf-8');

// Délai d'attente de la réponse du TPE (le client doit insérer sa carte, saisir son code...)
define('TPE_CONNECT_TIMEOUT', 5);
define('TPE_RESPONSE_TIMEOUT', 180);

function tpe_reply($status, $message, $extra = array(), $httpcode = 200)
{
	http_response_code($httpcode);
	echo json_encode(array_merge(array('status' => $status, 'message' => $message), $extra));
	exit;
}

/**
 * Construit un message Caisse-AP : suite de TLV "TAG(2) + LONGUEUR(3 chiffres) + VALEUR"
 */
function tpe_build_message($tags)
{
	$msg = '';
	foreach ($tags as $tag => $value) {
		$msg .= $tag.str_pad(strlen($value), 3, '0', STR_PAD_LEFT).$value;
	}
	return $msg;
}

/**
 * Décode un message Caisse-AP. Retourne false si le message est incomplet ou mal formé.
 */
function tpe_parse_message($msg)
{
	$tags = array();
	$pos = 0;
	$len = strlen($msg);
	while ($pos < $len) {
		if ($pos + 5 > $len) return false;
		$tag = substr($msg, $pos, 2);
		$vlen = substr($msg, $pos + 2, 3);
		if (!ctype_digit($vlen)) return false;
		$vlen = (int) $vlen;
		if ($pos + 5 + $vlen > $len) return false;
		$tags[$tag] = substr($msg, $pos + 5, $vlen);
		$pos += 5 + $vlen;
	}
	return $tags;
}

// --- Lecture de la configuration ---
$conf = @file(__DIR__.'/../tpe.ini', FILE_IGNORE_NEW_LINES);
$tpe_ip = isset($conf[0]) ? trim($conf[0]) : '';
$tpe_port = isset($conf[1]) ? (int) trim($conf[1]) : 0;
if (filter_var($tpe_ip, FILTER_VALIDATE_IP) === false || $tpe_port < 1 || $tpe_port > 65535) {
	tpe_reply('ERROR', 'TPE non configuré : renseigner IP et port dans TakePOS Connector', array(), 500);
}

// --- Contrôle des paramètres ---
$amount = isset($_POST['amount']) ? str_replace(',', '.', trim($_POST['amount'])) : '';
if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
	tpe_reply('ERROR', 'Montant invalide', array(), 400);
}
$cents = (int) round(((float) $amount) * 100);

$action = isset($_POST['action']) ? $_POST['action'] : 'debit';
$actions = array('debit' => '0', 'refund' => '1');
if (!isset($actions[$action])) {
	tpe_reply('ERROR', 'Action invalide (debit ou refund)', array(), 400);
}

$currency = isset($_POST['currency']) ? $_POST['currency'] : '978';
if (!preg_match('/^\d{3}$/', $currency)) {
	tpe_reply('ERROR', 'Devise invalide (code ISO numérique sur 3 chiffres)', array(), 400);
}

$posnum = isset($_POST['pos']) ? $_POST['pos'] : '01';
if (!preg_match('/^\d{2}$/', $posnum)) {
	tpe_reply('ERROR', 'Numéro de caisse invalide (2 chiffres)', array(), 400);
}

// --- Verrou : une seule transaction à la fois (évite le double débit sur double clic) ---
$lock = fopen(__DIR__.'/lock.txt', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
	tpe_reply('ERROR', 'Une transaction est déjà en cours sur le TPE', array(), 429);
}

// --- Construction du message ---
$message = tpe_build_message(array(
	'CZ' => '0300',                                     // Version du protocole
	'CJ' => '012345678901',                             // Identifiant du protocole Concert
	'CA' => $posnum,                                    // Numéro de caisse
	'CD' => $actions[$action],                          // 0 = débit, 1 = crédit (remboursement)
	'CB' => str_pad($cents, 2, '0', STR_PAD_LEFT),      // Montant en centimes
	'CE' => $currency,                                  // Devise
	'BF' => '0',                                        // Paiement partiel non autorisé
));

// --- Échange avec le TPE ---
set_time_limit(TPE_RESPONSE_TIMEOUT + 30);
$sock = @fsockopen($tpe_ip, $tpe_port, $errno, $errstr, TPE_CONNECT_TIMEOUT);
if (!$sock) {
	flock($lock, LOCK_UN);
	tpe_reply('ERROR', "Connexion au TPE $tpe_ip:$tpe_port impossible : $errstr ($errno)", array(), 502);
}
stream_set_timeout($sock, TPE_RESPONSE_TIMEOUT);
fwrite($sock, $message);

// Lecture jusqu'à obtenir un message complet contenant le résultat (tag AE)
$buffer = '';
$response = false;
while (!feof($sock)) {
	$chunk = fread($sock, 1024);
	if ($chunk === false || $chunk === '') {
		$meta = stream_get_meta_data($sock);
		if ($meta['timed_out']) break;
		continue;
	}
	$buffer .= $chunk;
	$response = tpe_parse_message($buffer);
	if ($response !== false && isset($response['AE'])) break;
}
fclose($sock);
flock($lock, LOCK_UN);
fclose($lock);

if ($response === false || !isset($response['AE'])) {
	tpe_reply('ERROR', 'Pas de réponse exploitable du TPE (délai dépassé ou message incomplet)', array('raw' => $buffer), 504);
}

// AE = 10 : transaction acceptée ; toute autre valeur : refusée / abandonnée
if ($response['AE'] === '10') {
	tpe_reply('OK', 'Paiement accepté', array('amount' => number_format($cents / 100, 2, '.', ''), 'response' => $response));
}
tpe_reply('KO', 'Paiement refusé ou abandonné'.(isset($response['AF']) ? ' (code '.$response['AF'].')' : ''), array('amount' => number_format($cents / 100, 2, '.', ''), 'response' => $response));
