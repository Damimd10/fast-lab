<?php
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json; charset=UTF-8");
	

	session_start();
	// Autentifica que se haya iniciado sesión
	if (empty($_SESSION["usuario"])) {
		//echo "[{}]";
		//header("Location:http://192.168.15.168:8001/html/login.html");
		//die(0);

	}
	session_write_close();
	// Expira la sesión despues de 2 horas
	if (time() - $_SESSION["timeout"] > 60 * 60 * 2){   // Expira la sesión después de 2 horas.
		$_SESSION = array();
	}

	$piso = $_GET['piso'];
	$log_usos_file = fopen('trafico.csv', 'a+');
	$valores_csv = array($piso, time());
	fputcsv($log_usos_file, $valores_csv);
	fclose($log_usos_file);
	
	$cache_file = "cache_piso_" . $piso . ".json";
	$file = file_get_contents($cache_file);
	echo $file;
	exit;

?>