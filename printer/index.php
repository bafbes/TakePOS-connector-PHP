<?php
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

//Fichier verrou
$f_lock = "lock.txt";

//On quitte si c'est verrouillé
if (file_exists($f_lock) && $content=file_get_contents($f_lock) === "1") {
    echo "locked by $content\n";
    http_response_code(429);
    exit;
}

//Verrouillage et impression
file_put_contents($f_lock, "1");

$nprinter = $_GET["printer"] ?? 1;
$filename = "invoice".$nprinter.".txt";
$data=$_POST["invoice"];
$data=base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));

file_put_contents($filename, $data);
$printer = file_get_contents('../printer'.$nprinter.'.ini', true);

exec('java -jar Print.jar "'.$printer.'" '.$nprinter, $output);
sleep(1);

//Déveerrouillage
file_put_contents($f_lock, "0");

//Logs
echo "filename=$filename\n";
echo "data=$data\n";
echo "printer=$printer\n";
print_r($output);