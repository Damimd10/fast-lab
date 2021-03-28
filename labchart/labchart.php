<?php
        header("Access-Control-Allow-Origin: *");
        header("Content-Type: application/json; charset=UTF-8");
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method == "GET"){
            $piso = $_GET['piso'];
        }
        
        $req_dump = print_r($_REQUEST, true);
        $fp = file_put_contents('request.log', $req_dump, FILE_APPEND);

        $debugging = true; # Cambia la fuente de datos. False: consulta en la DB del hospital. True: usa los datos de carpeta mock_data
        if ($debugging) {
            $fecha_actual = DateTime::createFromFormat('d/m/Y H:i', '07/12/2020 23:00');
            $fecha_menos_24h = DateTime::createFromFormat('d/m/Y H:i', '07/12/2020 23:00')->modify('-1 day');
            $fecha_menos_48h = DateTime::createFromFormat('d/m/Y H:i', '07/12/2020 23:00')->modify('-2 day');
        }
        else {
            $fecha_actual = date_create(date('d-m-Y H:i'));
            $fecha_menos_24h = date_create(date('d-m-Y H:i'))->modify('-1 day');
            $fecha_menos_48h = date_create(date('d-m-Y H:i'))->modify('-2 day');
            
        }
        
        $agrupar_estudios_array = array(
            'solicitud' => array(),
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
        
        $abreviar_nombres_estudios = array(
            "HTO" => "Hto",
            "HGB" => "Hb",
            "LEU" => "GB", 
            "PLLA" => "Plaq",
            "NAS" => "Na",
            "KAS" => "K",
            "MGS" => "Mg",
            "CAS" => "Ca",
            "CL" => "Cl",
            "FOS" => "P",
            "GLU" => "Glc",
            "URE" => "Ur",
            "CRE" => "Cr",
            "TGO" => "GOT",
            "TGP" => "GPT",
            "ALP" => "FAL",
            "BIT" => "BT",
            "BID" => "BD",
            "GGT" => "GGT",
            "QUICKA" => "TP",
            "QUICKR" => "RIN",
            "APTT" => "APTT",
            "PHT" => "PH",
            "PO2T" => "PO2",
            "PCO2T" => "PCO2",
            "CO3H" => "HCO3",
            "SO2" => "Sat O2",
            "FK" => "FK"
        );

        function pacientes_por_piso($piso) {	
            #Consulta al web-service función pacientes, organiza los datos en un array (HC, Nombre, Cama)
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
            /* Consulta al web-service función ordenestot, junta todas las ordenes de un paciente (identificado por HC) 
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
             *      "solicitud" => 01234567,
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
                $estudio_raw = json_decode(@ file_get_contents(".\\mock_data\\estudios\\estudio_".$orden.".json"), true);
                if (!$estudio_raw) {
                    return NULL;
                }
            } else {
                $estudio_raw = json_decode(file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=estudiostot&orden=".$orden), true);
            }
            
            $estudio_array['solicitud'] = $orden;
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
                    $estudio['estudiostot']['UNIDAD'] = ""; 
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
            
            # Ordena los resultados primero según el orden predefinido en agrupar_estudios_array: primero el orden de los grupos, luego orden de estudios.
            uksort($estudio_array, "ordenar_grupos_de_estudios");
            foreach ($estudio_array as $key => $value) {
                $grupo_estudios_actual = $key;
                if ($key == "solicitud" or $key == "timestamp") {
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
            global $piso;
            foreach($estudios_de_hoy as $key_estudio => $estudio_analizado) {
                foreach(array_slice($estudio_analizado, 5, -2) as $key_grupos => $grupo_de_estudios) {
                    foreach($grupo_de_estudios as $key_codigo => $array_resultado) {
                        $resultado_de_hoy = $array_resultado['resultado'];
                        #ANALISIS DE HEMOGRAMA
                        #Hematocrito
						if (!is_numeric($resultado_de_hoy)) {
							$estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "orange";
							continue;
						}
                        if ($key_codigo == "HTO") { 
                            #Puntos de corte:
                            if ($resultado_de_hoy < 40 and $resultado_de_hoy > 21) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "orange";
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Anemia. ";  
                            }
                            if ($resultado_de_hoy <= 21) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Anemia severa con probable requerimiento tranfusional. ";  
                            }
                            $estudios_comparativos = array_filter($todos_los_estudios, function($estudio_a_comparar) use($estudio_analizado) {return $estudio_a_comparar["timestamp"] < $estudio_analizado['timestamp'] && isset($estudio_a_comparar["Hemograma"]["HTO"]);});
                            foreach($estudios_comparativos as $estudio_a_comparar) {
                                $delta_resultado = $resultado_de_hoy - $estudio_a_comparar["Hemograma"]["HTO"]["resultado"];
                                $delta_tiempo = $estudio_analizado["timestamp"]->diff($estudio_a_comparar["timestamp"]);
                                $delta_horas = $delta_tiempo->h;
                                $delta_horas += $delta_tiempo->days*24;
                                if (($delta_resultado <= -7 and $delta_horas <=48) or $delta_resultado <= -10) {
                                    $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                                    $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Caida de " . $delta_resultado . " puntos en " . $delta_horas . " hs. ";  
                                }
                            } 

                        }
                        #Hemoglobina
                        if ($key_codigo == "HGB") {
                            if ($resultado_de_hoy <= 7) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Anemia con probable requerimiento tranfusional. ";  

                            }
                        }
                        #Plaquetas
                        if ($key_codigo == "PLLA") { 
                            if (10 < $resultado_de_hoy and $resultado_de_hoy <= 20) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "orange";
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Plaquetopenia severa. ";  
                            }
                            if ($resultado_de_hoy <= 10) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Plaquetopenia con requerimiento tranfusional. ";  
                            }
                        }
                        # FIN DE HEMOGRAMA
                        # INICIO DE FUNCION RENAL
                        # Creatinina
                        if ($key_codigo == "CRE") { 
                            #Puntos de corte:
                            if ($resultado_de_hoy > 1.2) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "orange";
                            }
                            if ($resultado_de_hoy > 2) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                            }
                            #Comparación con resultados de dias previos
                            $estudios_comparativos = array_filter($todos_los_estudios, function($estudio_a_comparar) use($estudio_analizado) {return $estudio_a_comparar["timestamp"] < $estudio_analizado['timestamp'] && isset($estudio_a_comparar["Funcion renal"]["CRE"]);});
                            foreach($estudios_comparativos as $estudio_a_comparar) {
                                $creatinina_previa = $estudio_a_comparar["Funcion renal"]["CRE"]["resultado"];
                                $delta_resultado = $resultado_de_hoy - $creatinina_previa;
                                $delta_tiempo = $estudio_analizado["timestamp"]->diff($estudio_a_comparar["timestamp"]);
                                $delta_horas = $delta_tiempo->h;
                                $delta_horas += $delta_tiempo->days*24;
                                if (($delta_resultado >= 0.3 and $delta_horas <=48 and $resultado_de_hoy < 3) or $resultado_de_hoy > ($creatinina_previa * 1.5)) {
                                    $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                                    $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] = "AKI. Aumento de " . $delta_resultado . "mg/dl en " . $delta_horas . " hs. ";  
                                }
                            } 

                        }
                        
                        # MEDIO INTERNO
                        # Sodio:
                        if ($key_codigo == "NAS") {
                            if ($resultado_de_hoy <= 130 and $resultado_de_hoy > 125) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "orange";
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Hiponatremia moderada. ";
                            }
                            if ($resultado_de_hoy <= 125) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Hiponatremia severa. ";
                            }
                            
                            $estudios_comparativos = array_filter($todos_los_estudios, function($estudio_a_comparar) use($estudio_analizado) {return $estudio_a_comparar["timestamp"] < $estudio_analizado['timestamp'] && isset($estudio_a_comparar["Medio interno"]["NAS"]);});
                            foreach($estudios_comparativos as $estudio_a_comparar) {
                                $sodio_previo = $estudio_a_comparar["Medio interno"]["NAS"]["resultado"];
                                $delta_resultado = $resultado_de_hoy - $sodio_previo;
                                $delta_tiempo = $estudio_analizado["timestamp"]->diff($estudio_a_comparar["timestamp"]);
                                $delta_horas = $delta_tiempo->h;
                                $delta_horas += $delta_tiempo->days*24;
                                if (abs($delta_resultado) >= 10 and $delta_horas <=36) {
                                    $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                                    $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Shift de sodio de " . $delta_resultado . "mEq/l en " . $delta_horas . " hs. ";  
                                }
                            } 

                        }
                        # Potasio:
                        if ($key_codigo == "KAS") {
                            if (($resultado_de_hoy < 4 and in_array($piso, array("3", "5", "6"))) or $resultado_de_hoy < 3.5) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "orange";
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Hipokalemia. ";
                            }
                            if ($resultado_de_hoy < 3) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "HIPOKALEMIA SEVERA. ";
                            }
                            if ($resultado_de_hoy > 5.5) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "orange";
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Hiperkalemia. ";
                            }
                            if ($resultado_de_hoy > 6) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "HIPERKALEMIA SEVERA. ";
                            }
                        }
                        
                        
                    }
                        
                }
                        
                
            }
        return $estudios_de_hoy;
        }  
        
        function textificar_array($estudios) {
            global $abreviar_nombres_estudios;
            foreach ($estudios as $key => $solicitud) {
                $textificado_largo = "";
                $textificado_corto = "";
                foreach(array_slice($solicitud, 5) as $key_grupos => $grupo_de_estudios) {
                    foreach($grupo_de_estudios as $grupo => $estudio) {
                        $textificado_largo .= $estudio["nombre_estudio"] . ": " . $estudio["resultado"] . $estudio["unidades"] . ", ";
                        if (isset($abreviar_nombres_estudios[$grupo])) { 
                            $textificado_corto .= $abreviar_nombres_estudios[$grupo] . ": " . $estudio["resultado"] . " ";
                        }
                    } 
                }
                $estudios[$key]["text_largo"] = $textificado_largo;
                $estudios[$key]["text_corto"] = $textificado_corto;

            }
            return $estudios;
        }
        
        function agrupar_por_pacientes($array_original, $pacientes) {
            # Esta funcion es una atrocidad para emparchar un error original en la estructura de datos. Pendiente: Refactoring para evitar esto
            $array_resultado = array();
            foreach ($pacientes as $paciente) {
                $array_resultado[$paciente['HC']] = $paciente;
                foreach ($array_original as $estudio) {
                    if ($estudio['HC'] == $paciente['HC']) {
                        $nuevo_array_estudios = array("Solicitud" => array_slice($estudio, 3, $length = 2) + array_slice($estudio, -2)) + array_slice($estudio, 5, -2);
                        $array_resultado[$estudio["HC"]][]= $nuevo_array_estudios;
                    }
                    
                }
            }
            return $array_resultado;
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
$estudios_de_hoy = textificar_array($estudios_de_hoy);

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
           "timestamp":"08\/12\/2020 06:00"   (nuevo)
           "solicitud":"222222222",
           "Hemograma":{
              "HTO":{
                 "nombre_estudio":"Hematocrito",
                 "resultado":"29",
                 "unidades": " "
                 "color":"red",    (nuevo)
  *              "info":"Anemia"   (nuevo)
              },
              "HGB":{
                 "nombre_estudio":"Hemoglobina",
                 "resultado":"12.5",
                 "unidades":"gr/dl",
  *              "color":"black",
  *              "info":""
              }
           }
           "Hepatograma":{
           ....
           }
  *        "text_largo": "Hematocrito 29%, Hemoglobina 12.5gr/dl",    (nuevo)
  *        "text_corto": "Hto 29, Hb 12.5"                            (nuevo)
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
