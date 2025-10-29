<?php
declare(strict_types=1);

// Configuración CORS para permitir llamadas desde cualquier origen
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar peticiones OPTIONS para CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configuración
const DIAN_AUTH_BASE = 'https://catalogo-vpfe.dian.gov.co/User/AuthToken';
const DIAN_DOWNLOAD_BASE = 'https://catalogo-vpfe.dian.gov.co/Document/DownloadZipFiles?trackId=';
const COOKIE_DIR = __DIR__ . '/cookies';

// Crear directorio de cookies si no existe
if (!is_dir(COOKIE_DIR)) {
    @mkdir(COOKIE_DIR, 0755, true);
}

/**
 * Función para enviar respuesta JSON
 */
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Función para procesar archivos XML de un ZIP
 */
function processZipContent($zipContent) {
    $result = [
        'facturas' => [],
        'total_archivos' => 0,
        'tipos_archivos' => []
    ];

    // Crear archivo temporal para el ZIP
    $tempZip = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($tempZip, $zipContent);

    $zip = new ZipArchive();
    if ($zip->open($tempZip) === TRUE) {
        $result['total_archivos'] = $zip->numFiles;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $fileInfo = pathinfo($filename);
            $extension = strtolower($fileInfo['extension'] ?? '');

            // Contar tipos de archivos
            if (!isset($result['tipos_archivos'][$extension])) {
                $result['tipos_archivos'][$extension] = 0;
            }
            $result['tipos_archivos'][$extension]++;

            // Si es XML, procesarlo
            if ($extension === 'xml') {
                $content = $zip->getFromIndex($i);

                // Extraer información del XML
                $facturaInfo = [
                    'archivo' => $filename,
                    'tamaño' => strlen($content),
                    'datos' => extractXMLData($content),
                    'preview' => substr($content, 0, 500)
                ];

                $result['facturas'][] = $facturaInfo;
            }
        }

        $zip->close();
    }

    unlink($tempZip);
    return $result;
}

/**
 * Función para extraer datos del XML de factura DIAN
 */
function extractXMLData($xmlContent) {
    $datos = [];

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent);

    if ($xml === false) {
        return $datos;
    }

    // Registrar namespaces
    $xml->registerXPathNamespace('inv', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
    $xml->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $xml->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $xml->registerXPathNamespace('sts', 'urn:dian:gov:co:facturaelectronica:Structures-2-1');

    // Extraer datos principales
    $extractors = [
        'numero_factura' => '//cbc:ID',
        'fecha_emision' => '//cbc:IssueDate',
        'hora_emision' => '//cbc:IssueTime',
        'moneda' => '//cbc:DocumentCurrencyCode',
        'tipo_documento' => '//cbc:InvoiceTypeCode',
        'proveedor' => '//cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name',
        'nit_proveedor' => '//cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID',
        'cliente' => '//cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name',
        'nit_cliente' => '//cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID',
        'subtotal' => '//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount',
        'total_con_impuestos' => '//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount',
        'total_a_pagar' => '//cac:LegalMonetaryTotal/cbc:PayableAmount',
        'iva_total' => '//cac:TaxTotal/cbc:TaxAmount',
        'cufe' => '//cbc:UUID'
    ];

    foreach ($extractors as $key => $xpath) {
        $result = $xml->xpath($xpath);
        if (!empty($result)) {
            $datos[$key] = (string)$result[0];
        }
    }

    // Contar items
    $invoiceLines = $xml->xpath('//cac:InvoiceLine');
    if (!empty($invoiceLines)) {
        $datos['cantidad_items'] = count($invoiceLines);
    }

    return $datos;
}

/**
 * Función para autenticar con token DIAN
 */
function authenticateWithToken($tokenUrl) {
    $cookieFile = COOKIE_DIR . '/' . md5($tokenUrl) . '.txt';

    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'success' => ($response !== false && $httpCode == 200),
        'http_code' => $httpCode,
        'cookie_file' => $cookieFile
    ];
}

/**
 * Función para descargar factura con sesión autenticada
 */
function downloadInvoice($trackId, $cookieFile) {
    $url = DIAN_DOWNLOAD_BASE . rawurlencode($trackId);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_HTTPHEADER => [
            'Accept: application/zip, application/octet-stream, */*',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Referer: https://catalogo-vpfe.dian.gov.co/'
        ],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HEADER => true,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        return [
            'success' => false,
            'error' => 'Error de conexión: ' . curl_error($ch)
        ];
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    // Verificar si es un ZIP válido
    if ($httpCode !== 200) {
        return [
            'success' => false,
            'error' => "Error HTTP $httpCode"
        ];
    }

    // Verificar que es un ZIP
    if (substr($body, 0, 2) !== 'PK') {
        return [
            'success' => false,
            'error' => 'El archivo descargado no es un ZIP válido'
        ];
    }

    return [
        'success' => true,
        'content' => $body,
        'size' => strlen($body)
    ];
}

// ============================================
// MANEJO DE ENDPOINTS
// ============================================

// Obtener datos de la petición
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

switch ($action) {

    // ============================================
    // ENDPOINT: Autenticación con Token
    // ============================================
    case 'auth':
        $tokenUrl = $input['token_url'] ?? '';

        if (empty($tokenUrl)) {
            sendResponse([
                'success' => false,
                'error' => 'El token_url es requerido'
            ], 400);
        }

        // Validar URL
        if (!filter_var($tokenUrl, FILTER_VALIDATE_URL)) {
            sendResponse([
                'success' => false,
                'error' => 'URL de token inválida'
            ], 400);
        }

        // Parsear parámetros del URL
        $urlParts = parse_url($tokenUrl);
        parse_str($urlParts['query'] ?? '', $params);

        if (!isset($params['pk']) || !isset($params['token'])) {
            sendResponse([
                'success' => false,
                'error' => 'El URL debe contener los parámetros pk y token'
            ], 400);
        }

        // Autenticar
        $authResult = authenticateWithToken($tokenUrl);

        sendResponse([
            'success' => $authResult['success'],
            'http_code' => $authResult['http_code'],
            'session_id' => md5($tokenUrl),
            'message' => $authResult['success'] ? 'Autenticación exitosa' : 'Error en la autenticación'
        ]);
        break;

    // ============================================
    // ENDPOINT: Descargar Factura
    // ============================================
    case 'download':
        $trackId = $input['track_id'] ?? '';
        $sessionId = $input['session_id'] ?? '';

        if (empty($trackId)) {
            sendResponse([
                'success' => false,
                'error' => 'El track_id es requerido'
            ], 400);
        }

        if (empty($sessionId)) {
            sendResponse([
                'success' => false,
                'error' => 'El session_id es requerido. Debe autenticarse primero.'
            ], 401);
        }

        // Verificar que existe el archivo de cookies
        $cookieFile = COOKIE_DIR . '/' . $sessionId . '.txt';
        if (!file_exists($cookieFile)) {
            sendResponse([
                'success' => false,
                'error' => 'Sesión no encontrada o expirada. Por favor, autentíquese nuevamente.'
            ], 401);
        }

        // Descargar factura
        $downloadResult = downloadInvoice($trackId, $cookieFile);

        if (!$downloadResult['success']) {
            sendResponse([
                'success' => false,
                'error' => $downloadResult['error']
            ], 400);
        }

        // Procesar el ZIP y extraer información
        $zipContent = processZipContent($downloadResult['content']);

        sendResponse([
            'success' => true,
            'track_id' => $trackId,
            'timestamp' => date('Y-m-d H:i:s'),
            'size' => $downloadResult['size'],
            'facturas' => $zipContent['facturas'],
            'total_archivos' => $zipContent['total_archivos'],
            'tipos_archivos' => $zipContent['tipos_archivos']
        ]);
        break;

    // ============================================
    // ENDPOINT: Proceso Completo (Auth + Download)
    // ============================================
    case 'process':
        $tokenUrl = $input['token_url'] ?? '';
        $trackId = $input['track_id'] ?? '';

        if (empty($tokenUrl) || empty($trackId)) {
            sendResponse([
                'success' => false,
                'error' => 'token_url y track_id son requeridos'
            ], 400);
        }

        // Paso 1: Autenticar
        $authResult = authenticateWithToken($tokenUrl);
        if (!$authResult['success']) {
            sendResponse([
                'success' => false,
                'error' => 'Error en la autenticación',
                'http_code' => $authResult['http_code']
            ], 401);
        }

        // Paso 2: Descargar factura
        $downloadResult = downloadInvoice($trackId, $authResult['cookie_file']);
        if (!$downloadResult['success']) {
            sendResponse([
                'success' => false,
                'error' => $downloadResult['error']
            ], 400);
        }

        // Paso 3: Procesar y devolver datos
        $zipContent = processZipContent($downloadResult['content']);

        sendResponse([
            'success' => true,
            'track_id' => $trackId,
            'timestamp' => date('Y-m-d H:i:s'),
            'facturas' => $zipContent['facturas']
        ]);
        break;

    // ============================================
    // ENDPOINT: Información de la API
    // ============================================
    case 'info':
    case '':
        sendResponse([
            'api' => 'DIAN Invoice Downloader API',
            'version' => '1.0',
            'endpoints' => [
                [
                    'action' => 'auth',
                    'method' => 'POST',
                    'description' => 'Autenticar con token DIAN',
                    'params' => ['token_url'],
                    'returns' => ['success', 'session_id']
                ],
                [
                    'action' => 'download',
                    'method' => 'POST',
                    'description' => 'Descargar factura (requiere autenticación previa)',
                    'params' => ['track_id', 'session_id'],
                    'returns' => ['facturas', 'total_archivos', 'tipos_archivos']
                ],
                [
                    'action' => 'process',
                    'method' => 'POST',
                    'description' => 'Autenticar y descargar en un solo paso',
                    'params' => ['token_url', 'track_id'],
                    'returns' => ['facturas']
                ]
            ],
            'example' => [
                'url' => 'POST /api.php?action=process',
                'body' => [
                    'token_url' => 'https://catalogo-vpfe.dian.gov.co/User/AuthToken?pk=XXX&token=YYY',
                    'track_id' => 'abc123...'
                ]
            ]
        ]);
        break;

    default:
        sendResponse([
            'success' => false,
            'error' => 'Acción no válida. Use: auth, download, process o info'
        ], 400);
}
?>