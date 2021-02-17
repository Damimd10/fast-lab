<?php
        header("Access-Control-Allow-Origin: *");
        header("Content-Type: application/json; charset=UTF-8");
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method == "GET"){
            $piso = $_GET['piso'];
        }
        $debugging = true; # Cambia la fuente de datos. False: consulta en la DB del hospital. True: usa los datos de carpeta mock_data
        if ($debugging) {
            $fecha_actual = DateTime::createFromFormat('d/m/Y H:i', '08/12/2020 23:00');
            $fecha_menos_24h = DateTime::createFromFormat('d/m/Y H:i', '08/12/2020 23:00')->modify('-1 day');
            $fecha_menos_48h = DateTime::createFromFormat('d/m/Y H:i', '08/12/2020 23:00')->modify('-2 day');
        }
        else {
            $fecha_actual = date_create(date('d-m-Y H:i'));
            $fecha_menos_24h = date_create(date('d-m-Y H:i'))->modify('-1 day');
            $fecha_menos_48h = date_create(date('d-m-Y H:i'))->modify('-2 day');
            
        }
        
        $agrupar_estudios_array = array(
            'orden' => array(),
            'Hemograma' => array('HTO', 'HGB',  "CHCM", "HCM", "VCM", "RDW", 'LEU','NSEG', 'CAY',  'LIN', 'PLLA' ),
            'Medio interno' => array('NAS', 'KAS', "MGS", 'CAS', "CL", "FOS", "GLU"),
            'Funcion renal' => array('URE', 'CRE'),
            'Hepatograma' => array('TGO', 'TGP', 'ALP', 'BIT', 'BID', 'BILI', 'GGT'),
            'Coagulograma' => array('QUICKA', 'QUICKR', "APTT"),
            'Gasometria' => array("PHT", "PO2T", "PCO2T", "CO3H", "EB", "SO2", "HB"),
            'Dosajes' => array("FK"),
            'Excluir' => array('BAS', 'EOS', 'META', 'MI', 'MON', 'PRO', 'SB', 'SUMAL', "SR", "TM", "NE", "TEMP", "CTO2", "ERC", "QUICKT", "FIO2", "A/A", "RPLA"),
            'Otros' => array()
        );

        function pacientes_por_piso($piso) {	
            #Consulta al web-service funci�n pacientes, organiza los datos en un array (HC, Nombre, Cama)
            global $debugging;
            $pacientes_array = array();
            if ($debugging) {
                return json_decode(file_get_contents(".\\mock_data\\pacientes".$piso.".json"), true);
            } else {
                $pacientes_raw = json_decode(file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=pacientes&piso=".$piso), true);
            }
            foreach ($pacientes_raw['pacientes'] as $paciente) {
                $pacientes_array[] = array('HC' => $paciente['pacientes']['hist_clinica'], "Nombre" => $paciente['pacientes']['apellido1'].", ".$paciente['pacientes']['nombre'],"Cama" => $paciente['pacientes']['cama']);
            }
            return $pacientes_array;
	}

	function ordenes_de_paciente($HC) {	
            /* Consulta al web-service funci�n ordenestot, junta todas las ordenes de un paciente (identificado por HC) 
             * en un array (n_solicitud, timestamp)" */
            global $debugging;
            $ordenes_array = array();
            if ($debugging) {
                $ordenes_raw = json_decode(file_get_contents(".\\mock_data\\ordenes\\ordenes".$HC.".json"), true);
            } else {
                $ordenes_raw = json_decode(file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=ordenestot&HC=".$HC), true);
            }
                foreach ($ordenes_raw['ordenestot'] as $orden) {
                        $timestamp_labo = DateTime::createFromFormat('d/m/Y H:i', $orden['ordenestot']['RECEPCION']);
                        $ordenes_array[] = array("n_solicitud" => $orden['ordenestot']['NRO_SOLICITUD'], "timestamp" => $timestamp_labo);

                    }
                    return $ordenes_array;
            }

	function procesar_estudio($orden, $timestamp) {
            /* Busca los resultados de laboratorio de una orden, y los preprocesa para darles la siguiente estructura:
             * array(
             *      "orden" => 01234567,
             *      "timestamp" => "20/11/2020 06:00"
             *      "Hemograma" => array(
             *              "HTO" => array(
             *                      "nombre_estudio" => "Hematocrito",
             *                      "resultado" => "34",
             *                      "unidades" => "%"
             *                      )
             *              "LEU" => array(
             *                      nombre_estudio => Leucocitos
             *                      "resultado => 11.2"
             *                      )
             *              (...)
             *              )
             *      "Hepatograma" => array(
             *              (...)
             *              )    
             * )
             */
            global $debugging, $agrupar_estudios_array, $grupo_estudios_actual;
            $estudio_array = array();
            $alertas = array();
            if ($debugging) {
                $estudio_raw = json_decode(file_get_contents(".\\mock_data\\estudios\\estudio_".$orden.".json"), true);
                if (!$estudio_raw) {
                    return NULL;
                }
            } else {
                $estudio_raw = json_decode(file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=estudiostot&orden=".$orden), true);
            }
            
            $estudio_array['orden'] = $orden;
            $estudio_array['timestamp'] = $timestamp;
            #Agrupa cada resultado del laboratorio segun los grupos definidos en $agrupar_estudios_array (Hemograma, hepatograma, etc)
            foreach ($estudio_raw['estudiostot'] as $estudio) {	
                $codigo = $estudio['estudiostot']['CODANALISI']; 
                if (in_array($codigo, $agrupar_estudios_array['Excluir'])) {
                    continue;
                }
                if ($estudio['estudiostot']['NOMANALISIS'] == " ") { # Algunos "resultados" que en realidad no lo son (ej: orden de material descartable utilizado, interconsultas)
                    continue;
                }
                if (is_null($estudio['estudiostot']['UNIDAD'])) {
                    $estudio['estudiostot']['UNIDAD'] = " "; 
                }
                
                 # Itera en los distintos $grupos de $estudios predefinidos buscando a cual pertenece el $estudio. Cuando lo encuentra, break
                 # Si no lo encuentra: el grupo es "Otros".
                $categoria_encontrada = false;
                foreach ($agrupar_estudios_array as $grupo => $estudios) { 
                    if (in_array($codigo, $estudios)) {
                        $estudio_array[$grupo][$codigo] = array(
                            'nombre_estudio' => $estudio['estudiostot']['NOMANALISIS'], 
                            'resultado' => $estudio['estudiostot']['RESULTADO'],
                            'unidades' => $estudio['estudiostot']['UNIDAD'],
                            'color' => "black",
                            'info' => ""
                            );
                        $categoria_encontrada = true;
                        break; 
                    }
                }
                if (!$categoria_encontrada) { #Si no entra en ninguna categoria preestablecida, va a "otros"
                    $estudio_array['Otros'][$codigo] = array(
                    'nombre_estudio' => $estudio['estudiostot']['NOMANALISIS'], 
                    'resultado' => $estudio['estudiostot']['RESULTADO'],
                    'unidades' => $estudio['estudiostot']['UNIDAD'],
                    'color' => "black",
                    'info' => ""
                    );
                }
            }
            
            # Ordena los resultados primero seg�n el orden predefinido en agrupar_estudios_array: primero el orden de los grupos, luego orden de estudios.
            uksort($estudio_array, "ordenar_grupos_de_estudios");
            foreach ($estudio_array as $key => $value) {
                $grupo_estudios_actual = $key;
                if ($key == "orden" or $key == "timestamp") {
                    continue;
                }
                uksort($value, "ordenar_estudios");
                $estudio_array[$key] = $value;
                }
            return $estudio_array;
	}
        
        # Prox 2 funciones: usadas por uksort para emparejar el orden de los resultados con el preestablecido en $agrupar_estudios_array
        function ordenar_grupos_de_estudios ($a, $b) {
            global $agrupar_estudios_array;
            $a_pos = array_search($a, array_keys($agrupar_estudios_array)); 
            $b_pos = array_search($b, array_keys($agrupar_estudios_array));

            $resultado = $a_pos - $b_pos;
            if ($a_pos == NULL) {
                $resultado = -1;
            }   
            if ($b_pos == NULL) {
                $resultado = +1;
            }
            return $resultado;
        }
        
        function ordenar_estudios ($a, $b) {
            global $agrupar_estudios_array, $grupo_estudios_actual;
            $a_pos = array_search($a, array_values($agrupar_estudios_array[$grupo_estudios_actual])); 
            $b_pos = array_search($b, array_values($agrupar_estudios_array[$grupo_estudios_actual]));
            $resultado = $a_pos - $b_pos;
            return $resultado;
        }
                
        function formatear_fechas_visualizacion($estudio) {
            $estudio["timestamp"] = $estudio["timestamp"]->format("d/m/Y H:i");
            return $estudio;
        }
        
        function analisis_de_alertas($estudios_de_hoy, $todos_los_estudios) {
            foreach($estudios_de_hoy as $key_estudio => $estudio_analizado) {
                foreach(array_slice($estudio_analizado, 5) as $key_grupos => $grupo_de_estudios) {
                    foreach($grupo_de_estudios as $key_codigo => $array_resultado) {
                        $resultado = $array_resultado['resultado'];
                        #ANALISIS DE HEMOGRAMA
                        if ($key_codigo == "HTO") { #Hematocrito
                            #Puntos de corte:
                            if ($resultado < 21) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] = "Anemia severa con probable requerimiento tranfusional";  
                            }
                            $comparativos = array_filter($todos_los_estudios, function($estudio_a_comparar) use($estudio_analizado) {return $estudio_a_comparar["timestamp"] < $estudio_analizado['timestamp'] && isset($estudio_a_comparar["Hemograma"]["HTO"]);});
                            /*echo "estudio analizado = " . $estudio_analizado["orden"];
                            echo "\nEstudios a comparar:";
                            print_r($comparativos);*/
                            
                            }
                        
                    }
                        
                }
                        
                
            }
        return $estudios_de_hoy;
        }  
# MAIN LOOP
$todos_los_estudios = array();
$piso = filter_input(INPUT_GET, "piso", FILTER_SANITIZE_NUMBER_INT);
$pacientes = pacientes_por_piso($piso);
foreach ($pacientes as $paciente) {
	foreach(ordenes_de_paciente($paciente['HC']) as $orden) {
            $resultado = procesar_estudio($orden['n_solicitud'], $orden['timestamp']);
            if($resultado == NULL)continue;
            $todos_los_estudios[] = array_merge($paciente, $resultado);
	}
}

$estudios_de_hoy = array_filter($todos_los_estudios, function($estudio) use($fecha_menos_24h) { return $estudio["timestamp"] > $fecha_menos_24h;});
$estudios_de_hoy_analizados = analisis_de_alertas($estudios_de_hoy, $todos_los_estudios);
$estudios_analizados_formateados = array_map("formatear_fechas_visualizacion", $estudios_de_hoy_analizados);
$array_final = array_values($estudios_analizados_formateados);
echo json_encode($array_final);

 /* Estructura del JSON final:
  * [
        {
           "HC":11111,
           "Nombre":"PEREZ, JUAN",
           "Cama":"701A",
           "orden":"222222222",
           "Hemograma":{
              "HTO":{
                 "nombre_estudio":"Hematocrito",
                 "resultado":"39",
                 "unidades":null
              },
              "HGB":{
                 "nombre_estudio":"Hemoglobina",
                 "resultado":"12.5",
                 "unidades":null
              }
           }
           "Hepatograma:{
           ....
           }        
       },
       {
           "HC":11112,
           "Nombre": "Clooney, George"
           ....
       }
   ]
  */




/*
GENERADOR DE  DATOS DE PRUEBA
$fp = fopen('pacientes9.json', 'w');
fwrite($fp, json_encode($pacientes));
fclose($fp);

foreach ($pacientes as $paciente) {
	$HC = $paciente['HC'];
	$ordenes = file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=ordenestot&HC=".$HC);
	$fp = fopen('ordenes' . $HC . '.json', 'w');
	fwrite($fp, $ordenes);
	fclose($fp);

}


foreach ($pacientes as $paciente) {
	foreach(ordenes_de_paciente($paciente['HC']) as $orden) {
		echo $orden['n_solicitud'];
		$estudio = file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=estudiostot&orden=" . $orden['n_solicitud']);
		$fp = fopen('estudio_' . $orden['n_solicitud'] . '.json', 'w');
		fwrite($fp, $estudio);
		fclose($fp);	
	}
	
}

*/


?>
