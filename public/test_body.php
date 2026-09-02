<?php
header('Content-Type: text/plain');
echo "method: " . ($_SERVER['REQUEST_METHOD'] ?? 'NONE') . PHP_EOL;
echo "content_type: " . ($_SERVER['CONTENT_TYPE'] ?? 'NONE') . PHP_EOL;
echo "content_length: " . ($_SERVER['CONTENT_LENGTH'] ?? 'NONE') . PHP_EOL;
echo "php://input: '" . file_get_contents('php://input') . "'" . PHP_EOL;
echo "php://input length: " . strlen(file_get_contents('php://input')) . PHP_EOL;