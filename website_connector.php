<?php

header("Content-Type: application/json; charset=utf-8");

$python = "C:\\Users\\po\\AppData\\Local\\Programs\\Python\\Python313\\python.exe";
$script = "C:\\xampp\\ai-tools\\website_connector.py";
$logFile = "C:\\xampp\\tmp\\connector_debug.txt";

function respond($status, $data) {
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function logMessage($message) {
    global $logFile;
    file_put_contents(
        $logFile,
        "[" . date("Y-m-d H:i:s") . "] " . $message . PHP_EOL,
        FILE_APPEND
    );
}

logMessage("REQUEST START");

$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

if (!is_array($input) || empty($input["url"])) {
    logMessage("ERROR: Missing URL");
    respond(400, [
        "success" => false,
        "error" => "Missing URL"
    ]);
}

$url = trim($input["url"]);

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    logMessage("ERROR: Invalid URL");
    respond(400, [
        "success" => false,
        "error" => "Invalid URL"
    ]);
}

$scheme = strtolower(parse_url($url, PHP_URL_SCHEME));

if (!in_array($scheme, ["http", "https"], true)) {
    logMessage("ERROR: Invalid URL scheme");
    respond(400, [
        "success" => false,
        "error" => "Only HTTP and HTTPS are allowed"
    ]);
}

if (!is_file($python)) {
    logMessage("ERROR: Python executable not found");
    respond(500, [
        "success" => false,
        "error" => "Python executable not found"
    ]);
}

if (!is_file($script)) {
    logMessage("ERROR: Python script not found");
    respond(500, [
        "success" => false,
        "error" => "Python script not found"
    ]);
}

logMessage("STARTING PYTHON");

$command = [
    $python,
    $script,
    $url
];

$pipes = [];

$process = proc_open(
    $command,
    [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ],
    $pipes,
    "C:\\xampp\\ai-tools"
);

if (!is_resource($process)) {
    logMessage("ERROR: proc_open failed");
    respond(500, [
        "success" => false,
        "error" => "Cannot start Python"
    ]);
}

fclose($pipes[0]);

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$output = "";
$error = "";
$startTime = time();
$timeout = 45;

while (true) {
    $output .= stream_get_contents($pipes[1]);
    $error .= stream_get_contents($pipes[2]);

    $status = proc_get_status($process);

    if (!$status["running"]) {
        break;
    }

    if (time() - $startTime > $timeout) {
        proc_terminate($process);
        logMessage("ERROR: Python timeout");
        break;
    }

    usleep(100000);
}

$output .= stream_get_contents($pipes[1]);
$error .= stream_get_contents($pipes[2]);

fclose($pipes[1]);
fclose($pipes[2]);

$exitCode = proc_close($process);

logMessage("PYTHON EXIT CODE: " . $exitCode);
logMessage("PYTHON STDOUT: " . $output);
logMessage("PYTHON STDERR: " . $error);

$result = json_decode($output, true);
logMessage("JSON ERROR CODE: " . json_last_error());
logMessage("JSON ERROR MESSAGE: " . json_last_error_msg());
logMessage("OUTPUT FIRST 20 BYTES: " . bin2hex(substr($output, 0, 20)));

if ($result === null || !is_array($result)) {
    logMessage("ERROR: Invalid Python JSON");
    respond(500, [
        "success" => false,
        "error" => "Python returned invalid JSON",
        "exit_code" => $exitCode,
        "output" => $output,
        "details" => $error
    ]);
}

if ($exitCode !== 0) {
    logMessage("ERROR: Python execution failed");
    respond(500, [
        "success" => false,
        "error" => "Python execution failed",
        "exit_code" => $exitCode,
        "result" => $result,
        "details" => $error
    ]);
}

logMessage("REQUEST SUCCESS");

respond(200, $result);

?>

