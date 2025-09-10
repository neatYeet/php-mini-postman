<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$SAVED_REQUESTS_FILE = 'saved_requests.json';
$GLOBAL_VARIABLES_FILE = 'global_variables.json';

if (isset($_GET['action']) && $_GET['action'] === 'load_requests') {
    if (file_exists($SAVED_REQUESTS_FILE)) {
        echo file_get_contents($SAVED_REQUESTS_FILE);
    } else {
        echo json_encode([]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_requests') {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input && is_array($input)) {
        file_put_contents($SAVED_REQUESTS_FILE, json_encode($input, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'message' => 'Requests saved successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid data received.']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'load_variables') {
    if (file_exists($GLOBAL_VARIABLES_FILE)) {
        echo file_get_contents($GLOBAL_VARIABLES_FILE);
    } else {
        echo json_encode([]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_variables') {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input && is_array($input)) {
        if (file_put_contents($GLOBAL_VARIABLES_FILE, json_encode($input, JSON_PRETTY_PRINT)) !== false) {
            echo json_encode(['success' => true, 'message' => 'Variables saved successfully.']);
        } else {
            error_log('Failed to write to ' . $GLOBAL_VARIABLES_FILE . '. Check file permissions.');
            echo json_encode(['success' => false, 'message' => 'Failed to save variables. Check server logs for details.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid data received.']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'download_file' && isset($_GET['file'])) {
    $filePath = base64_decode($_GET['file']);
    if (file_exists($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        http_response_code(404);
        echo "File not found.";
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);

    if ($input) {
        $method = $input['method'];
        $url = $input['url'];
        $params = $input['params'] ?? [];
        $headers = $input['headers'] ?? [];
        $body = $input['body'] ?? '';

        $debug_info = [
            'proxy_input' => $input,
            'curl_settings' => []
        ];

        if ($method === 'GET' && !empty($params)) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($params);
        }

        $ch = curl_init();

        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'PHP CRUD Tester/1.0',
            CURLOPT_HEADER => true,
            CURLINFO_HEADER_OUT => true
        ];

        switch ($method) {
            case 'POST':
                $curlOptions[CURLOPT_POST] = true;
                if (!empty($body)) {
                    $curlOptions[CURLOPT_POSTFIELDS] = $body;
                } elseif (!empty($params)) {
                    $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($params);
                }
                break;

            case 'PUT':
                $curlOptions[CURLOPT_CUSTOMREQUEST] = 'PUT';
                if (!empty($body)) {
                    $curlOptions[CURLOPT_POSTFIELDS] = $body;
                } elseif (!empty($params)) {
                    $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($params);
                }
                break;

            case 'DELETE':
                $curlOptions[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                if (!empty($body)) {
                    $curlOptions[CURLOPT_POSTFIELDS] = $body;
                }
                break;
        }

        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }

        if (!empty($curlHeaders)) {
            $curlOptions[CURLOPT_HTTPHEADER] = $curlHeaders;
        }

        curl_setopt_array($ch, $curlOptions);

        $debug_info['curl_settings'] = [
            'URL' => $url,
            'Method' => $method,
            'Headers_Sent' => $curlHeaders,
            'Body_Sent' => ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') ? $body : null
        ];

        $start_time = microtime(true);
        $response_raw = curl_exec($ch);
        $end_time = microtime(true);
        $request_duration = round(($end_time - $start_time) * 1000); // in milliseconds

        $curl_info = curl_getinfo($ch);
        $error = curl_error($ch);
        curl_close($ch);

        $debug_info['curl_response_info'] = $curl_info;
        $debug_info['curl_error'] = $error;

        if ($error) {
            $result = [
                'success' => false,
                'status_code' => 'ERROR',
                'body' => 'cURL Error: ' . $error,
                'debug' => $debug_info
            ];
        } else {
            $header_size = $curl_info['header_size'];
            $response_headers = substr($response_raw, 0, $header_size);
            $response_body_content = substr($response_raw, $header_size);

            $content_type = $curl_info['content_type'];
            $is_file_download = false;
            $file_path = '';

            // Check if the response is a file (e.g., based on content-type or a specific header)
            // For simplicity, let's assume if content-type is not text/html or application/json, it's a file
            if (strpos($content_type, 'text/html') === false && strpos($content_type, 'application/json') === false && !empty($response_body_content)) {
                // Attempt to save the file temporarily
                $temp_dir = 'temp_downloads';
                if (!is_dir($temp_dir)) {
                    mkdir($temp_dir, 0777, true);
                }
                $file_extension = mime_content_type_to_extension($content_type);
                $filename = 'download_' . uniqid() . ($file_extension ? '.' . $file_extension : '');
                $file_path = $temp_dir . '/' . $filename;
                if (file_put_contents($file_path, $response_body_content) !== false) {
                    $is_file_download = true;
                } else {
                    error_log("Failed to save downloaded file to: " . $file_path);
                }
            }

            if ($is_file_download) {
                $result = [
                    'success' => true,
                    'status_code' => $curl_info['http_code'],
                    'is_file' => true,
                    'file_name' => basename($file_path),
                    'file_path_encoded' => base64_encode($file_path),
                    'debug' => [
                        'request_to_target_api' => $debug_info['curl_settings'],
                        'curl_error' => $debug_info['curl_error'],
                        'response_headers_received' => $response_headers,
                        'response_info' => $curl_info,
                        'request_duration_ms' => $request_duration
                    ]
                ];
            } else {
                $decodedResponse = json_decode($response_body_content, true);
                $formattedResponse = json_last_error() === JSON_ERROR_NONE
                    ? json_encode($decodedResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    : $response_body_content;

                $result = [
                    'success' => $curl_info['http_code'] >= 200 && $curl_info['http_code'] < 300,
                    'status_code' => $curl_info['http_code'],
                    'request_duration_ms' => $request_duration,
                    'body' => $formattedResponse,
                    'debug' => [
                        'request_to_target_api' => $debug_info['curl_settings'],
                        'curl_error' => $debug_info['curl_error'],
                        'response_headers_received' => $response_headers,
                        'response_info' => $curl_info,
                        'request_duration_ms' => $request_duration
                    ]
                ];
            }
        }

        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
}

// Helper function to get file extension from MIME type
function mime_content_type_to_extension($mime_type) {
    $mime_map = [
        'application/json' => 'json',
        'application/xml' => 'xml',
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'application/gzip' => 'gz',
        'application/x-tar' => 'tar',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'image/jpeg' => 'jpeg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'text/xml' => 'xml',
        'text/css' => 'css',
        'text/javascript' => 'js',
        'application/octet-stream' => 'bin',
        'model/step' => 'step', // Added for .step files
        'model/iges' => 'iges', // Added for .iges files
        'application/x-dxf' => 'dxf', // Added for .dxf files
        'application/vnd.collada+xml' => 'dae', // Added for .dae files
        'application/x-stl' => 'stl', // Added for .stl files
        'application/x-obj' => 'obj', // Added for .obj files
        'application/x-3ds' => '3ds', // Added for .3ds files
        'application/x-fbx' => 'fbx', // Added for .fbx files
        'application/x-gltf' => 'gltf', // Added for .gltf files
        'application/x-glb' => 'glb', // Added for .glb files
    ];
    return $mime_map[$mime_type] ?? null;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP API Tester</title>
    <style>
        :root {
            --bg-color: #1a1b26;
            --surface-color: #24283b;
            --text-color: #a9b1d6;
            --primary-color: #7aa2f7;
            --primary-color-dark: #5a7abc;
            --border-color: #414868;
            --success-color: #9ece6a;
            --error-color: #f7768e;
            --font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
            --mono-font-family: 'SF Mono', 'Fira Code', 'Menlo', monospace;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-family);
            background-color: var(--bg-color);
            color: var(--text-color);
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        .container {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 100%;
        }

        .header {
            background-color: var(--surface-color);
            padding: 12px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .header h1 {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .main-content {
            display: flex;
            flex-grow: 1;
            overflow: hidden;
        }

        .sidebar {
            width: 280px;
            flex-shrink: 0;
            border-right: 1px solid var(--border-color);
            padding: 15px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .sidebar h2 {
            font-size: 1rem;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid var(--border-color);
        }

        .variables-list .kv-row {
            font-size: 13px;
        }

        .request-pane {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--border-color);
        }

        .response-pane {
            width: 50%;
            display: flex;
            flex-direction: column;
            background-color: var(--bg-color);
        }

        .url-bar {
            display: flex;
            padding: 10px;
            gap: 10px;
            border-bottom: 1px solid var(--border-color);
        }

        select,
        input,
        textarea {
            background-color: #2e3452;
            color: var(--text-color);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 14px;
            font-family: inherit;
        }

        input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary-color);
            flex-shrink: 0;
        }

        select:focus,
        input:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(122, 162, 247, 0.3);
        }

        .method-select {
            width: 110px;
        }

        .url-input {
            flex-grow: 1;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.2s ease;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: #1a1b26;
        }

        .btn-primary:hover {
            background-color: var(--primary-color-dark);
        }

        .btn-secondary {
            background-color: #414868;
            color: var(--text-color);
        }

        .btn-secondary:hover {
            background-color: #565f89;
        }

        .btn-danger {
            background-color: transparent;
            color: var(--error-color);
            border: 1px solid var(--error-color);
            padding: 4px 8px;
            font-size: 12px;
        }

        .btn-danger:hover {
            background-color: rgba(247, 118, 142, 0.1);
        }

        .btn-add {
            background-color: transparent;
            color: var(--success-color);
            border: 1px solid var(--success-color);
            padding: 6px 12px;
            font-size: 12px;
            margin-top: 5px;
        }

        .btn-add:hover {
            background-color: rgba(158, 206, 106, 0.1);
        }

        .tabs {
            display: flex;
            padding: 0 10px;
            border-bottom: 1px solid var(--border-color);
            flex-shrink: 0;
        }

        .tab {
            padding: 10px 16px;
            cursor: pointer;
            background: none;
            border: none;
            color: var(--text-color);
            font-weight: 500;
            border-bottom: 2px solid transparent;
            transition: color 0.2s, border-color 0.2s;
        }

        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }

        .tab-content {
            display: none;
            flex-grow: 1;
            overflow-y: auto;
            padding: 10px;
        }

        .tab-content.active {
            display: flex;
            flex-direction: column;
        }

        .kv-row {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }

        .kv-row input {
            flex: 1;
            min-width: 0;
        }

        .kv-row .key-input {
            font-family: var(--mono-font-family);
        }

        .predefined-header .key-input {
            background-color: #292e42;
            color: #8a92b0;
        }

        .predefined-header.inactive .value-input {
            background-color: #292e42;
            color: #8a92b0;
            border-style: dashed;
        }

        #body-tab {
            padding: 0;
        }

        textarea#jsonBody {
            width: 100%;
            height: 100%;
            resize: none;
            font-family: var(--mono-font-family);
            padding: 10px;
            background-color: #1e202e;
            border: none;
            border-radius: 0;
        }

        .response-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border-color);
            background: var(--surface-color);
        }

        .status-code {
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 12px;
        }

        .status-success {
            background-color: rgba(158, 206, 106, 0.2);
            color: var(--success-color);
        }

        .status-error {
            background-color: rgba(247, 118, 142, 0.2);
            color: var(--error-color);
        }

        .response-body-wrapper {
            flex-grow: 1;
            overflow-y: auto;
        }

        .response-body {
            background: #1e202e;
            padding: 15px;
            white-space: pre-wrap;
            font-family: var(--mono-font-family);
            font-size: 13px;
            min-height: 100%;
        }

        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(36, 40, 59, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10;
        }

        .spinner {
            width: 30px;
            height: 30px;
            border: 3px solid #414868;
            border-top: 3px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .save-load-section {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .save-load-section select {
            flex-grow: 1;
        }

        details {
            padding-top: 15px;
        }

        summary {
            cursor: pointer;
            color: var(--primary-color);
        }
    </style>
</head>

<body>
    <div class="container">
        <header class="header">
            <h1>API Tester <span id="loadedRequestIndicator" style="font-size: 0.8em; opacity: 0.7;"></span></h1>
            <div class="save-load-section">
                <select id="savedRequestsDropdown"></select>
                <button type="button" class="btn btn-secondary" onclick="loadSelectedRequest()">Load</button>
                <button type="button" id="saveButton" class="btn btn-secondary" onclick="currentLoadedRequestId ? updateCurrentRequest() : saveCurrentRequest()">Save</button>
                <button type="button" id="saveAsButton" class="btn btn-secondary" onclick="saveAsNewRequest()" style="display: none;">Save As</button>
                <button type="button" id="saveNewButton" class="btn btn-secondary" onclick="saveCurrentRequest()" style="display: none;">Save New</button>
                <button type="button" class="btn btn-danger" onclick="deleteSelectedRequest()">Delete</button>
            </div>
        </header>

        <main class="main-content">
            <div class="sidebar">
                <div class="variables-section">
                    <h2>Variables</h2>
                    <div class="kv-row">
                        <input type="text" id="newVarKey" placeholder="Variable Name" class="key-input">
                        <input type="text" id="newVarValue" placeholder="Value" class="value-input">
                    </div>
                    <button class="btn-add" onclick="addVariable()">+ Add Variable</button>
                    <div class="variables-list" id="variables-list"></div>
                    <button type="button" class="btn btn-secondary" onclick="saveGlobalVariables()">Save Variables</button>
                </div>
            </div>
            <div class="request-pane">
                <form id="apiForm" style="display: flex; flex-direction: column; height: 100%;">
                    <div class="url-bar">
                        <select id="method" class="method-select"></select>
                        <input type="url" id="url" class="url-input" placeholder="Enter API URL, e.g. https://api.example.com/users/{{userId}}">
                        <button type="submit" class="btn btn-primary">Send</button>
                    </div>

                    <div class="tabs">
                        <button type="button" class="tab active" onclick="switchTab(event, 'params')">Params</button>
                        <button type="button" class="tab" onclick="switchTab(event, 'headers')">Headers</button>
                        <button type="button" class="tab" onclick="switchTab(event, 'body')">Body</button>
                    </div>

                    <div id="params-tab" class="tab-content active">
                        <div id="params-list" class="key-value-container"></div>
                        <button type="button" class="btn btn-add" onclick="addParam(); clearLoadedRequest()">+ Add Parameter</button>
                    </div>

                    <div id="headers-tab" class="tab-content">
                        <div id="headers-list" class="key-value-container"></div>
                        <button type="button" class="btn btn-add" onclick="addHeader(); clearLoadedRequest()">+ Add Custom Header</button>
                    </div>

                    <div id="body-tab" class="tab-content">
                        <textarea id="jsonBody" placeholder='{ "key": "{{variableName}}" }'></textarea>
                    </div>
                </form>
            </div>

            <div id="response-pane" class="response-pane" style="display: none;">
                <div id="response-content" style="display: flex; flex-direction: column; height: 100%;">
                    <div class="response-header">
                        <h3>Response</h3>
                        <span id="status-code" class="status-code"></span>
                        <button id="copy-button" class="btn btn-secondary" onclick="copyResponseToClipboard()">Copy Response</button>
                        <button id="download-button" class="btn btn-primary" style="display: none;">Download File</button>
                    </div>
                    <div class="response-body-wrapper">
                        <div id="response-body"></div>
                    </div>
                </div>
                <div class="loading-overlay" style="display: none;">
                    <div class="spinner"></div>
                </div>
            </div>
        </main>
    </div>

    <script>
        let variables = {};
        let savedRequests = [];
        let currentLoadedRequestId = null;

        const switchTab = (event, tabName) => {
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

            event.target.classList.add('active');
            document.getElementById(`${tabName}-tab`).classList.add('active');
        };

        const renderMethods = () => {
            const methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'];
            const select = document.getElementById('method');
            select.innerHTML = methods.map(m => `<option value="${m}">${m}</option>`).join('');
        };

        const addVariable = async () => {
            const keyInput = document.getElementById('newVarKey');
            const valueInput = document.getElementById('newVarValue');
            if (keyInput.value) {
                variables[keyInput.value] = valueInput.value;
                keyInput.value = '';
                valueInput.value = '';
                renderVariables();
                await persistGlobalVariables();
            }
        };

        const deleteVariable = async (button) => {
            const key = button.previousElementSibling.previousElementSibling.value;
            delete variables[key];
            renderVariables();
            await persistGlobalVariables();
        };

        const updateVariableKey = async (oldKey, newKey) => {
            if (newKey && newKey !== oldKey && newKey !== '' && !variables.hasOwnProperty(newKey)) {
                variables[newKey] = variables[oldKey];
                delete variables[oldKey];
                renderVariables();
                await persistGlobalVariables();
            }
        };

        const updateVariableValue = async (key, value) => {
            variables[key] = value;
            await persistGlobalVariables();
        };

        const updateSaveButtons = () => {
            const saveButton = document.getElementById('saveButton');
            const saveAsButton = document.getElementById('saveAsButton');
            const saveNewButton = document.getElementById('saveNewButton');
            const indicator = document.getElementById('loadedRequestIndicator');

            if (currentLoadedRequestId) {
                const req = savedRequests.find(r => r.id == currentLoadedRequestId);
                if (req) {
                    indicator.textContent = `| Loaded: ${req.name}`;
                } else {
                    indicator.textContent = '| Loaded: [Request not found]';
                }
                saveButton.textContent = 'Update Request';
                saveButton.style.display = 'inline-block';
                saveAsButton.style.display = 'inline-block';
                saveNewButton.style.display = 'none';
            } else {
                indicator.textContent = '';
                saveButton.textContent = 'Save Request';
                saveButton.style.display = 'inline-block';
                saveAsButton.style.display = 'none';
                saveNewButton.style.display = 'inline-block';
            }
        };

        const updateCurrentRequest = async () => {
            const url = document.getElementById('url').value;
            if (!url) return alert('URL is required to update.');

            if (!currentLoadedRequestId) {
                alert('No loaded request to update.');
                return;
            }

            const reqIndex = savedRequests.findIndex(r => r.id == currentLoadedRequestId);
            if (reqIndex === -1) {
                alert('Request not found.');
                currentLoadedRequestId = null;
                updateSaveButtons();
                return;
            }

            const headersState = {};
            document.querySelectorAll('#headers-list .predefined-header').forEach(row => {
                headersState[row.dataset.key] = {
                    active: row.querySelector('input[type="checkbox"]').checked,
                    value: row.querySelector('.value-input').value
                };
            });

            savedRequests[reqIndex] = {
                ...savedRequests[reqIndex],
                method: document.getElementById('method').value,
                url: url,
                params: Object.fromEntries([...document.querySelectorAll('input[name="param_key[]"]')].map((k, i) => [k.value, document.querySelectorAll('input[name="param_value[]"]')[i].value]).filter(([k]) => k)),
                headers: Object.fromEntries([...document.querySelectorAll('#headers-list .kv-row:not(.predefined-header) input[name="header_key[]"]')].map((k, i) => [k.value, document.querySelectorAll('#headers-list .kv-row:not(.predefined-header) input[name="header_value[]"]')[i].value]).filter(([k]) => k)),
                predefinedHeaders: headersState,
                body: document.getElementById('jsonBody').value,
                variables: {
                    ...variables
                }
            };

            await persistSavedRequests();
            populateRequestsDropdown();

            alert(`Request "${savedRequests[reqIndex].name}" updated successfully!`);
        };

        const saveAsNewRequest = async () => {
            currentLoadedRequestId = null;
            await saveCurrentRequest(true); // true flag to indicate it's a save as operation
        };

        const clearLoadedRequest = () => {
            currentLoadedRequestId = null;
            updateSaveButtons();
        };

        const renderVariables = () => {
            const list = document.getElementById('variables-list');
            list.innerHTML = Object.entries(variables).map(([key, value]) => `
                <div class="kv-row">
                    <input type="text" value="${key}" class="key-input" onchange="updateVariableKey('${key}', this.value)">
                    <input type="text" value="${value}" class="value-input" onchange="updateVariableValue('${key}', this.value)">
                    <button class="btn btn-danger" onclick="deleteVariable(this)">X</button>
                </div>
            `).join('');
        };

        const loadGlobalVariables = async () => {
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('action', 'load_variables');
                const response = await fetch(url.toString());
                variables = await response.json();
                renderVariables();
            } catch (error) {
                console.error('Error loading global variables:', error);
                variables = {};
            }
        };

        const persistGlobalVariables = () => {
            const url = new URL(window.location.href);
            url.searchParams.set('action', 'save_variables');
            return fetch(url.toString(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(variables)
            }).catch(err => console.error('Failed to save global variables:', err));
        };

        const saveGlobalVariables = async () => {
            await persistGlobalVariables();
            alert('Global variables saved!');
        };

        const replaceVariables = (str) => {
            if (typeof str !== 'string') return str;
            return str.replace(/\{\{(\w+)\}\}/g, (match, key) => variables[key] || match);
        }

        const addParam = (key = '', value = '') => createKvRow('param', key, value);
        const addHeader = (key = '', value = '') => createKvRow('header', key, value);

        const createKvRow = (type, key = '', value = '') => {
            const list = document.getElementById(`${type}s-list`);
            const row = document.createElement('div');
            row.className = 'kv-row';
            row.innerHTML = `
                <input type="text" placeholder="Key" name="${type}_key[]" value="${key}" class="key-input" oninput="clearLoadedRequest()">
                <input type="text" placeholder="Value" name="${type}_value[]" value="${value}" class="value-input" oninput="clearLoadedRequest()">
                <button type="button" class="btn btn-danger" onclick="this.parentElement.remove(); clearLoadedRequest()">Remove</button>
            `;
            list.appendChild(row);
        };

        const renderPredefinedHeaders = (headersState = {}) => {
            const list = document.getElementById('headers-list');
            const predefined = {
                'Authorization': `Bearer {{authToken}}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            };

            list.innerHTML = Object.entries(predefined).map(([key, value]) => {
                const state = headersState[key] || {
                    active: false,
                    value: value
                };
                const isChecked = state.active ? 'checked' : '';
                const isInactive = !state.active ? 'inactive' : '';
                return `
                <div class="kv-row predefined-header ${isInactive}" data-key="${key}">
                    <input type="checkbox" onchange="togglePredefinedHeader(this)" ${isChecked}>
                    <input type="text" value="${key}" class="key-input" readonly>
                    <input type="text" value="${state.value}" class="value-input" ${!state.active ? 'disabled' : ''}>
                </div>`;
            }).join('');
        };

        const togglePredefinedHeader = (checkbox) => {
            const row = checkbox.closest('.kv-row');
            const valueInput = row.querySelector('.value-input');
            const isActive = checkbox.checked;

            valueInput.disabled = !isActive;
            row.classList.toggle('inactive', !isActive);
            clearLoadedRequest();
        };

        document.getElementById('apiForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const headers = {};
            document.querySelectorAll('#headers-list .kv-row').forEach(row => {
                const key = row.dataset.key || row.querySelector('input[name="header_key[]"]')?.value;
                if (!key) return;

                const isPredefined = !!row.dataset.key;
                const isActive = isPredefined ? row.querySelector('input[type="checkbox"]').checked : true;

                if (isActive) {
                    const value = row.querySelector('.value-input, input[name="header_value[]"]').value;
                    headers[key] = replaceVariables(value);
                }
            });

            const params = {};
            document.querySelectorAll('input[name="param_key[]"]').forEach((keyInput, i) => {
                if (keyInput.value) {
                    const value = document.querySelectorAll('input[name="param_value[]"]')[i].value;
                    params[keyInput.value] = replaceVariables(value);
                }
            });

            const requestData = {
                method: document.getElementById('method').value,
                url: replaceVariables(document.getElementById('url').value),
                params,
                headers,
                body: replaceVariables(document.getElementById('jsonBody').value)
            };

            const responsePane = document.getElementById('response-pane');
            responsePane.style.display = 'flex';
            responsePane.querySelector('.loading-overlay').style.display = 'flex';
            document.getElementById('response-content').style.display = 'none';

            sendRequest(requestData);
        });

        const sendRequest = (requestData) => {
            fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(requestData)
                })
                .then(res => res.json())
                .then(displayResponse)
                .catch(err => displayResponse({
                    status_code: 'CLIENT ERROR',
                    body: `Request failed: ${err.message}`,
                    success: false
                }));
        };

        const displayResponse = (data) => {
            const responsePane = document.getElementById('response-pane');
            responsePane.querySelector('.loading-overlay').style.display = 'none';
            document.getElementById('response-content').style.display = 'flex';

            const statusCodeEl = document.getElementById('status-code');
            let statusText = data.status_code;
            if (data.request_duration_ms !== undefined) {
                statusText += ` (${data.request_duration_ms}ms)`;
            }
            statusCodeEl.textContent = statusText;
            statusCodeEl.className = `status-code ${data.success ? 'status-success' : 'status-error'}`;

            const responseBodyEl = document.getElementById('response-body');
            const downloadButton = document.getElementById('download-button');

            if (data.is_file) {
                responseBodyEl.innerHTML = `<p>File ready for download: <strong>${data.file_name}</strong></p>`;
                downloadButton.style.display = 'inline-block';
                downloadButton.onclick = () => {
                    const url = new URL(window.location.href);
                    url.searchParams.set('action', 'download_file');
                    url.searchParams.set('file', data.file_path_encoded);
                    window.location.href = url.toString();
                };
            } else {
                const debugInfo = data.debug ? `<details><summary>Debug Info</summary><pre>${JSON.stringify(data.debug, null, 2)}</pre></details>` : '';
                responseBodyEl.innerHTML = `<pre>${data.body}</pre>${debugInfo}`;
                downloadButton.style.display = 'none';
            }
        };

        const copyResponseToClipboard = () => {
            const responseBodyEl = document.getElementById('response-body');
            const preElement = responseBodyEl.querySelector('pre');
            const text = preElement ? preElement.textContent : (responseBodyEl.textContent || responseBodyEl.innerText);
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    alert('Response copied to clipboard!');
                }).catch(err => {
                    console.error('Failed to copy text: ', err);
                    fallbackCopyTextToClipboard(text);
                });
            } else {
                fallbackCopyTextToClipboard(text);
            }
        };

        const fallbackCopyTextToClipboard = (text) => {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.top = '0';
            textArea.style.left = '0';
            textArea.style.position = 'fixed';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                document.execCommand('copy');
                alert('Response copied to clipboard!');
            } catch (err) {
                console.error('Fallback: Oops, unable to copy', err);
            }
            document.body.removeChild(textArea);
        };

        const loadSavedRequests = async () => {
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('action', 'load_requests');
                const response = await fetch(url.toString());
                savedRequests = await response.json();

                // Backward compatibility: Add IDs to existing requests that don't have them
                let shouldSave = false;
                savedRequests.forEach((req, index) => {
                    if (!req.id) {
                        req.id = `legacy_${index}_${Date.now()}`;
                        shouldSave = true;
                    }
                });

                populateRequestsDropdown();

                // Save the requests with IDs if any were added
                if (shouldSave) {
                    await persistSavedRequests();
                }
            } catch (error) {
                console.error('Error loading saved requests:', error);
                savedRequests = [];
            }
        };

        const populateRequestsDropdown = () => {
            const dropdown = document.getElementById('savedRequestsDropdown');
            dropdown.innerHTML = '<option value="">-- Load Request --</option>';
            savedRequests.forEach((req, index) => {
                const option = document.createElement('option');
                option.value = req.id;
                option.textContent = req.name || `${req.method} ${req.url}`;
                dropdown.appendChild(option);
            });
        };

        const loadRequest = (req) => {
            currentLoadedRequestId = req.id;
            updateSaveButtons();

            document.getElementById('method').value = req.method;
            document.getElementById('url').value = req.url;
            document.getElementById('jsonBody').value = req.body || '';

            variables = {
                ...variables,
                ...(req.variables || {})
            };
            renderVariables();

            renderPredefinedHeaders(req.predefinedHeaders);

            document.getElementById('params-list').innerHTML = '';
            if (req.params) Object.entries(req.params).forEach(([k, v]) => addParam(k, v));

            document.querySelectorAll('#headers-list .kv-row:not(.predefined-header)').forEach(el => el.remove());
            if (req.headers) Object.entries(req.headers).forEach(([k, v]) => addHeader(k, v));

            alert(`Request "${req.name}" loaded.`);
        };

        const saveCurrentRequest = async (isSaveAsNew = false) => {
            const url = document.getElementById('url').value;
            if (!url) return alert('URL is required to save.');

            let defaultName = '';
            if (currentLoadedRequestId && !isSaveAsNew) {
                const existingReq = savedRequests.find(r => r.id == currentLoadedRequestId);
                defaultName = existingReq ? existingReq.name : '';
            } else {
                defaultName = `${document.getElementById('method').value} ${url.substring(0, 50)}`;
            }

            const name = prompt('Enter a name for this request:', defaultName);
            if (!name) return;

            const headersState = {};
            document.querySelectorAll('#headers-list .predefined-header').forEach(row => {
                headersState[row.dataset.key] = {
                    active: row.querySelector('input[type="checkbox"]').checked,
                    value: row.querySelector('.value-input').value
                };
            });

            const currentRequest = {
                id: Date.now().toString(), // Add unique ID
                name,
                method: document.getElementById('method').value,
                url: url,
                params: Object.fromEntries([...document.querySelectorAll('input[name="param_key[]"]')].map((k, i) => [k.value, document.querySelectorAll('input[name="param_value[]"]')[i].value]).filter(([k]) => k)),
                headers: Object.fromEntries([...document.querySelectorAll('#headers-list .kv-row:not(.predefined-header) input[name="header_key[]"]')].map((k, i) => [k.value, document.querySelectorAll('#headers-list .kv-row:not(.predefined-header) input[name="header_value[]"]')[i].value]).filter(([k]) => k)),
                predefinedHeaders: headersState,
                body: document.getElementById('jsonBody').value,
                variables: {
                    ...variables
                }
            };

            savedRequests.push(currentRequest);
            await persistSavedRequests();
            populateRequestsDropdown();

            // If this was a save as new operation, update the loaded request ID
            if (isSaveAsNew) {
                currentLoadedRequestId = currentRequest.id;
            }

            // Update browser URL with request ID for preservation on refresh
            if (window.history && window.history.replaceState) {
                const newUrl = new URL(window.location);
                newUrl.searchParams.set('request', currentRequest.id);
                history.replaceState(history.state, '', newUrl);
            }

            updateSaveButtons();
            alert(isSaveAsNew ? 'Request saved as new!' : 'Request saved!');
        };

        const persistSavedRequests = () => {
            const url = new URL(window.location.href);
            url.searchParams.set('action', 'save_requests');
            return fetch(url.toString(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(savedRequests)
            }).catch(err => console.error('Failed to save:', err));
        }

        const loadSelectedRequest = () => {
            const selectedId = document.getElementById('savedRequestsDropdown').value;
            if (selectedId === '') return;

            const req = savedRequests.find(r => r.id == selectedId);
            if (!req) return;

            currentLoadedRequestId = selectedId;
            loadRequest(req);

            // Update URL with request ID for persistence on refresh
            if (window.history && window.history.replaceState) {
                const newUrl = new URL(window.location);
                newUrl.searchParams.set('request', selectedId);
                history.replaceState(history.state, '', newUrl);
            }
        };

        const deleteSelectedRequest = async () => {
            const selectedId = document.getElementById('savedRequestsDropdown').value;
            if (selectedId === '') return;

            const reqIndex = savedRequests.findIndex(r => r.id == selectedId);
            if (reqIndex === -1) return;

            const req = savedRequests[reqIndex];
            if (confirm(`Delete "${req.name}"?`)) {
                savedRequests.splice(reqIndex, 1);
                await persistSavedRequests();
                populateRequestsDropdown();

                // Clear URL parameter if the deleted request was active
                if (window.history && window.history.replaceState) {
                    const currentUrl = new URL(window.location);
                    if (currentUrl.searchParams.get('request') === selectedId) {
                        currentUrl.searchParams.delete('request');
                        history.replaceState(history.state, '', currentUrl);
                    }
                }

                alert(`Request "${req.name}" deleted.`);
            }
        };

        window.addEventListener('load', async () => {
            renderMethods();
            renderPredefinedHeaders();
            await loadSavedRequests();
            await loadGlobalVariables();
            updateSaveButtons();

            document.getElementById('newVarKey').addEventListener('keydown', e => {
                if (e.key === 'Enter') addVariable();
            });
            document.getElementById('newVarValue').addEventListener('keydown', e => {
                if (e.key === 'Enter') addVariable();
            });

            // Load request from URL parameter if present
            // Use setTimeout to ensure DOM is fully ready
            setTimeout(() => {
                const urlParams = new URLSearchParams(window.location.search);
                const requestId = urlParams.get('request');
                if (requestId) {
                    const req = savedRequests.find(r => r.id == requestId);
                    if (req) {
                        currentLoadedRequestId = requestId;
                        loadRequest(req);
                        document.getElementById('savedRequestsDropdown').value = requestId;
                    }
                }
            }, 100);

            // Add event listeners to clear loaded request when form changes
            document.getElementById('method').addEventListener('change', clearLoadedRequest);
            document.getElementById('url').addEventListener('input', clearLoadedRequest);
            document.getElementById('jsonBody').addEventListener('input', clearLoadedRequest);
        });
    </script>
</body>

</html>