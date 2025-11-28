<?php
declare(strict_types=1);

// Cargar configuración
require_once __DIR__ . '/config.php';

// Configuración de errores basada en .env
error_reporting(E_ALL);
ini_set('display_errors', Config::isDebug() ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/' . config('LOG_PATH', 'logs/') . 'errors.log');

// Headers de seguridad
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Access-Control-Allow-Origin: ' . config('CORS_ALLOWED_ORIGINS', '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Manejar peticiones OPTIONS para CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class ValidadorDuplicados {
    private $db;
    private $tokenUrl;
    private $sessionId;
    private $stats = [
        'total_documentos' => 0,
        'cufes_duplicados' => 0,
        'documentos_corregidos' => 0,
        'errores' => 0
    ];

    /**
     * Constructor - Configura la conexión a la base de datos
     */
    public function __construct($config) {
        // Obtener configuración de base de datos desde .env
        $dbConfig = db_config();

        // Permitir override desde el formulario si se proporciona
        $host = !empty($config['db_host']) ? $config['db_host'] : $dbConfig['host'];
        $puerto = !empty($config['db_port']) ? $config['db_port'] : $dbConfig['port'];

        // Usar siempre las credenciales del .env por seguridad
        $usuario = $dbConfig['username'];
        $password = $dbConfig['password'];
        $baseDatos = $dbConfig['database'];

        try {
            $dsn = "mysql:host=$host;port=$puerto;dbname=$baseDatos;charset=utf8mb4";

            $this->db = new PDO(
                $dsn,
                $usuario,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 10
                ]
            );

            // Verificar conexión
            $this->db->query("SELECT 1");

        } catch (PDOException $e) {
            $errorMsg = "Error de conexión a base de datos MySQL. ";
            $errorMsg .= "Host: $host, Base de datos: $baseDatos. ";
            $errorMsg .= "Detalles: " . $e->getMessage();
            throw new Exception($errorMsg);
        }

        $this->tokenUrl = $config['token_url'] ?? '';
    }

    /**
     * Autenticar con DIAN
     */
    private function autenticarDian() {
        $data = [
            'action' => 'auth',
            'token_url' => $this->tokenUrl
        ];

        $response = $this->llamarAPI('api.php?action=auth', $data);

        if ($response && $response['success']) {
            $this->sessionId = $response['session_id'];
            return true;
        }

        throw new Exception('Error al autenticar con DIAN');
    }

    /**
     * Obtener información de factura desde DIAN
     */
    private function obtenerFacturaDian($trackId) {
        if (!$this->sessionId) {
            $this->autenticarDian();
        }

        $data = [
            'track_id' => $trackId,
            'session_id' => $this->sessionId
        ];

        $response = $this->llamarAPI('api.php?action=download', $data);

        if ($response && $response['success'] && isset($response['facturas'][0])) {
            return $response['facturas'][0]['datos'];
        }

        return null;
    }

    /**
     * Llamar API local
     */
    private function llamarAPI($endpoint, $data) {
        // Detectar la URL base dinámicamente
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        $baseUrl = $protocol . '://' . $host . $scriptDir;

        $url = rtrim($baseUrl, '/') . '/' . $endpoint;

        $options = [
            'http' => [
                'header'  => "Content-type: application/json\r\n",
                'method'  => 'POST',
                'content' => json_encode($data),
                'timeout' => 60
            ]
        ];

        $context  = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return null;
        }

        return json_decode($result, true);
    }

    /**
     * Buscar documentos duplicados
     */
    public function buscarDuplicados($identification_number, $fecha_desde, $fecha_hasta, $limit = null) {
        // Consulta optimizada con EXISTS - más eficiente que subquery con IN
        // EXISTS se detiene al encontrar la primera coincidencia, mientras que IN evalúa toda la subquery
        $sql = "SELECT
                    d.id,
                    d.identification_number,
                    d.state_document_id,
                    d.prefix,
                    d.number,
                    d.cufe,
                    d.subtotal,
                    d.total_tax,
                    d.total,
                    d.response_dian,
                    d.created_at
                FROM documents d
                WHERE d.identification_number = :identification1
                  AND d.state_document_id = 1
                  AND d.created_at BETWEEN :fecha_desde1 AND :fecha_hasta1
                  AND EXISTS (
                      SELECT 1
                      FROM documents x
                      WHERE x.cufe = d.cufe
                        AND x.identification_number = :identification2
                        AND x.state_document_id = 1
                        AND x.created_at BETWEEN :fecha_desde2 AND :fecha_hasta2
                        AND x.id <> d.id
                  )
                ORDER BY d.cufe, d.created_at";

        // Agregar LIMIT si está especificado (MODO PRUEBA)
        if ($limit !== null && $limit > 0) {
            $sql .= " LIMIT " . intval($limit);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':identification1' => $identification_number,
            ':identification2' => $identification_number,
            ':fecha_desde1' => $fecha_desde,
            ':fecha_desde2' => $fecha_desde,
            ':fecha_hasta1' => $fecha_hasta . ' 23:59:59',
            ':fecha_hasta2' => $fecha_hasta . ' 23:59:59'
        ]);

        $documentos = $stmt->fetchAll();
        $this->stats['total_documentos'] = count($documentos);

        // Agrupar por CUFE
        $gruposPorCufe = [];
        foreach ($documentos as $doc) {
            $gruposPorCufe[$doc['cufe']][] = $doc;
        }

        $this->stats['cufes_duplicados'] = count($gruposPorCufe);

        return $gruposPorCufe;
    }

    /**
     * Validar y corregir duplicados
     */
    public function validarYCorregir($gruposPorCufe) {
        $resultados = [];

        // Primero autenticarse con DIAN
        try {
            $this->autenticarDian();
        } catch (Exception $e) {
            throw new Exception("Error al autenticar con DIAN: " . $e->getMessage());
        }

        foreach ($gruposPorCufe as $cufe => $documentos) {
            $grupoResultado = [
                'cufe' => $cufe,
                'documentos' => [],
                'estado' => 'pendiente'
            ];

            // Intentar obtener datos de DIAN usando el CUFE como trackId
            $datosDian = null;
            $trackIdEncontrado = false;

            // Debug: agregar información sobre la búsqueda
            $grupoResultado['debug_dian'] = [
                'cufe_usado' => $cufe,
                'trackId_encontrado' => null,
                'error_dian' => null
            ];

            // El CUFE es el trackId que necesitamos enviar a la DIAN
            $trackId = $cufe;
            $grupoResultado['debug_dian']['trackId_encontrado'] = $trackId;

            if ($trackId) {
                try {
                    error_log("====================================");
                    error_log("Intentando obtener factura de DIAN");
                    error_log("CUFE/TrackId: $trackId");

                    $datosDian = $this->obtenerFacturaDian($trackId);
                    $trackIdEncontrado = true;

                    error_log("Datos DIAN obtenidos exitosamente");
                    error_log("Datos recibidos: " . print_r($datosDian, true));
                } catch (Exception $e) {
                    $grupoResultado['debug_dian']['error_dian'] = $e->getMessage();
                    error_log("Error al obtener datos de DIAN: " . $e->getMessage());
                }
            }

            if (!$datosDian) {
                error_log("No se pudieron obtener datos de DIAN para el CUFE: $cufe");
            }

            // Validar cada documento del grupo contra DIAN
            $documentoValido = null;
            foreach ($documentos as $doc) {
                $docResultado = [
                    'id' => $doc['id'],
                    'prefix' => $doc['prefix'],
                    'number' => $doc['number'],
                    'subtotal' => $doc['subtotal'],
                    'total' => $doc['total'],
                    'total_tax' => $doc['total_tax'],
                    'created_at' => $doc['created_at'],
                    'state_document_id' => $doc['state_document_id'],
                    'match_dian' => false,
                    'actualizado' => false,
                    'valores_dian' => null,
                    'valores_comparacion' => null
                ];

                if ($datosDian) {
                    // Guardar valores de DIAN para debug
                    $docResultado['valores_dian'] = [
                        'subtotal' => $datosDian['subtotal'] ?? 'N/A',
                        'total' => $datosDian['total_a_pagar'] ?? $datosDian['total_con_impuestos'] ?? 'N/A',
                        'cufe' => $datosDian['cufe'] ?? 'N/A'
                    ];

                    // Comparar valores con DIAN
                    $match = $this->compararConDian($doc, $datosDian);
                    $docResultado['match_dian'] = $match;

                    // Guardar detalles de comparación
                    $subtotalDoc = round(floatval(str_replace(',', '.', $doc['subtotal'])), 2);
                    $totalDoc = round(floatval(str_replace(',', '.', $doc['total'])), 2);
                    $subtotalDian = round(floatval($datosDian['subtotal'] ?? 0), 2);
                    $totalDian = round(floatval($datosDian['total_a_pagar'] ?? $datosDian['total_con_impuestos'] ?? 0), 2);

                    $docResultado['valores_comparacion'] = [
                        'subtotal_bd' => $subtotalDoc,
                        'subtotal_dian' => $subtotalDian,
                        'subtotal_diff' => abs($subtotalDoc - $subtotalDian),
                        'total_bd' => $totalDoc,
                        'total_dian' => $totalDian,
                        'total_diff' => abs($totalDoc - $totalDian)
                    ];

                    if ($match) {
                        $documentoValido = $doc['id'];
                    }
                }

                $grupoResultado['documentos'][] = $docResultado;
            }

            // Si encontramos el documento válido, actualizar los demás
            if ($documentoValido) {
                foreach ($grupoResultado['documentos'] as &$docRes) {
                    if ($docRes['id'] !== $documentoValido && !$docRes['match_dian']) {
                        try {
                            $this->actualizarEstadoDocumento($docRes['id'], 0);
                            $docRes['actualizado'] = true;
                            $docRes['state_document_id'] = 0;
                            $this->stats['documentos_corregidos']++;
                            $grupoResultado['estado'] = 'corregido';
                        } catch (Exception $e) {
                            $this->stats['errores']++;
                        }
                    }
                }
            } else {
                // Si no hay datos de DIAN o ninguno coincide, NO HACER CAMBIOS
                $grupoResultado['estado'] = 'sin_cambios';
                // No actualizar ningún documento si no hay coincidencia con DIAN
            }

            $resultados[] = $grupoResultado;
        }

        return $resultados;
    }

    /**
     * Buscar trackId recursivamente en un array
     */
    private function buscarTrackId($array) {
        if (!is_array($array)) {
            return null;
        }

        foreach ($array as $key => $value) {
            if (($key === 'trackId' || $key === 'track_id') && !empty($value)) {
                return $value;
            }
            if (is_array($value)) {
                $result = $this->buscarTrackId($value);
                if ($result) {
                    return $result;
                }
            }
        }
        return null;
    }

    /**
     * Comparar documento con datos de DIAN
     */
    private function compararConDian($documento, $datosDian) {
        // Limpiar y convertir valores de la base de datos (pueden venir con comas como separador decimal)
        $subtotalDoc = str_replace(',', '.', $documento['subtotal']);
        $totalDoc = str_replace(',', '.', $documento['total']);

        // Convertir a float y redondear
        $subtotalDoc = round(floatval($subtotalDoc), 2);
        $totalDoc = round(floatval($totalDoc), 2);

        // Obtener valores de DIAN (ya vienen con punto como separador)
        $subtotalDian = round(floatval($datosDian['subtotal'] ?? 0), 2);
        $totalDian = round(floatval($datosDian['total_a_pagar'] ?? $datosDian['total_con_impuestos'] ?? 0), 2);

        // Calcular IVA como diferencia (total - subtotal)
        $ivaDoc = round($totalDoc - $subtotalDoc, 2);
        $ivaDian = round($totalDian - $subtotalDian, 2);

        // Log para debug (puedes comentar estas líneas después)
        error_log("====================================");
        error_log("Comparando documento ID {$documento['id']} - {$documento['prefix']}{$documento['number']}:");
        error_log("  Valores originales BD: subtotal={$documento['subtotal']}, total={$documento['total']}");
        error_log("  Valores procesados BD:");
        error_log("    - subtotal: $subtotalDoc");
        error_log("    - total: $totalDoc");
        error_log("    - iva_calculado: $ivaDoc");
        error_log("  Valores de DIAN:");
        error_log("    - subtotal: $subtotalDian");
        error_log("    - total: $totalDian");
        error_log("    - iva_calculado: $ivaDian");

        // Comparar valores (tolerancia de 0.10 para manejar diferencias de redondeo)
        $tolerancia = 0.10;
        $subtotalMatch = abs($subtotalDoc - $subtotalDian) <= $tolerancia;
        $totalMatch = abs($totalDoc - $totalDian) <= $tolerancia;

        // Si coinciden subtotal y total, es el documento correcto
        $valoresMatch = $subtotalMatch && $totalMatch;

        error_log("  Comparación:");
        error_log("    - Subtotal match: " . ($subtotalMatch ? "SÍ" : "NO") . " (diferencia: " . abs($subtotalDoc - $subtotalDian) . ")");
        error_log("    - Total match: " . ($totalMatch ? "SÍ" : "NO") . " (diferencia: " . abs($totalDoc - $totalDian) . ")");
        error_log("  RESULTADO FINAL: " . ($valoresMatch ? "✓ COINCIDE - Mantener activo" : "✗ NO COINCIDE - Desactivar"));
        error_log("====================================");

        return $valoresMatch;
    }

    /**
     * Obtener el documento más reciente de un grupo
     */
    private function obtenerMasReciente($documentos) {
        $masReciente = null;
        $fechaMasReciente = null;

        foreach ($documentos as $doc) {
            $fecha = strtotime($doc['created_at']);
            if ($fechaMasReciente === null || $fecha > $fechaMasReciente) {
                $fechaMasReciente = $fecha;
                $masReciente = $doc['id'];
            }
        }

        return $masReciente;
    }

    /**
     * Actualizar estado de documento
     */
    private function actualizarEstadoDocumento($documentId, $nuevoEstado) {
        $sql = "UPDATE documents SET state_document_id = :estado WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':estado' => $nuevoEstado,
            ':id' => $documentId
        ]);
    }

    /**
     * Obtener estadísticas
     */
    public function obtenerEstadisticas() {
        return $this->stats;
    }
}

// ============================================
// PROCESAMIENTO PRINCIPAL
// ============================================

try {
    // Verificar que no haya salida previa
    if (ob_get_length()) {
        ob_clean();
    }

    // Obtener datos del POST
    $rawInput = file_get_contents('php://input');

    if (empty($rawInput)) {
        throw new Exception('No se recibieron datos. Verifique que el formulario esté enviando información.');
    }

    $input = json_decode($rawInput, true);

    if (!$input) {
        $jsonError = json_last_error_msg();
        throw new Exception('Error al decodificar JSON: ' . $jsonError);
    }

    // Función de sanitización
    function sanitizeInput($value, $type = 'string') {
        switch ($type) {
            case 'int':
                return filter_var($value, FILTER_SANITIZE_NUMBER_INT);
            case 'date':
                // Validar formato de fecha YYYY-MM-DD
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    throw new Exception("Formato de fecha inválido. Use YYYY-MM-DD");
                }
                return $value;
            case 'url':
                $url = filter_var($value, FILTER_SANITIZE_URL);
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    throw new Exception("URL inválida");
                }
                return $url;
            case 'host':
                // Validar host/IP
                $host = filter_var($value, FILTER_SANITIZE_STRING);
                if (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN)) {
                    // Si no es IP ni dominio válido, al menos sanitizar
                    $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $host);
                }
                return $host;
            default:
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
    }

    // Validar y sanitizar campos requeridos
    $camposRequeridos = [
        'identification' => 'int',
        'fecha_desde' => 'date',
        'fecha_hasta' => 'date',
        'token_url' => 'url'
    ];

    $sanitizedInput = [];
    foreach ($camposRequeridos as $campo => $tipo) {
        if (empty($input[$campo])) {
            throw new Exception("El campo '$campo' es requerido");
        }
        $sanitizedInput[$campo] = sanitizeInput($input[$campo], $tipo);
    }

    // Sanitizar campos opcionales
    if (!empty($input['db_host'])) {
        $sanitizedInput['db_host'] = sanitizeInput($input['db_host'], 'host');
    }
    if (!empty($input['db_port'])) {
        $sanitizedInput['db_port'] = intval($input['db_port']);
        if ($sanitizedInput['db_port'] < 1 || $sanitizedInput['db_port'] > 65535) {
            throw new Exception("Puerto inválido. Debe estar entre 1 y 65535");
        }
    }
    if (isset($input['limit_registros'])) {
        $sanitizedInput['limit_registros'] = intval($input['limit_registros']);
        if ($sanitizedInput['limit_registros'] < 0) {
            $sanitizedInput['limit_registros'] = null;
        }
    }

    // Usar input sanitizado
    $input = $sanitizedInput;

    // Crear instancia del validador
    $validador = new ValidadorDuplicados($input);

    // Obtener límite si está especificado (MODO PRUEBA)
    $limit = isset($input['limit_registros']) ? intval($input['limit_registros']) : null;

    // Buscar duplicados
    $gruposPorCufe = $validador->buscarDuplicados(
        $input['identification'],
        $input['fecha_desde'],
        $input['fecha_hasta'],
        $limit
    );

    // Si no hay duplicados, retornar mensaje
    if (empty($gruposPorCufe)) {
        echo json_encode([
            'success' => true,
            'message' => 'No se encontraron documentos duplicados',
            'total_documentos' => 0,
            'cufes_duplicados' => 0,
            'documentos_corregidos' => 0,
            'errores' => 0,
            'grupos_cufe' => []
        ]);
        exit;
    }

    // Validar y corregir duplicados
    $resultados = $validador->validarYCorregir($gruposPorCufe);
    $stats = $validador->obtenerEstadisticas();

    // Preparar respuesta con indicador de modo prueba
    $response = [
        'success' => true,
        'message' => 'Validación completada',
        'total_documentos' => $stats['total_documentos'],
        'cufes_duplicados' => $stats['cufes_duplicados'],
        'documentos_corregidos' => $stats['documentos_corregidos'],
        'errores' => $stats['errores'],
        'grupos_cufe' => $resultados
    ];

    // Agregar indicador de modo prueba si aplica
    if ($limit !== null && $limit > 0) {
        $response['modo_prueba'] = true;
        $response['limite_aplicado'] = $limit;
        $response['message'] .= " (MODO PRUEBA - Limitado a $limit registros)";
    }

    // Retornar resultados
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Asegurarse de que no haya salida HTML
    if (ob_get_length()) {
        ob_clean();
    }

    $errorResponse = [
        'success' => false,
        'error' => $e->getMessage()
    ];

    // Si es error de conexión, dar más detalles
    if (strpos($e->getMessage(), 'conexión') !== false) {
        $errorResponse['tipo_error'] = 'conexion_db';
        $errorResponse['sugerencia'] = 'Verifique que el host de la base de datos sea correcto. Use localhost si la BD está en el mismo servidor.';
    }

    http_response_code(500);
    echo json_encode($errorResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
}
?>