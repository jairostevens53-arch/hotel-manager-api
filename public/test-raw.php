<?php
header("Content-Type: application/json");
echo json_encode([
    "HTTP_AUTHORIZATION" => $_SERVER["HTTP_AUTHORIZATION"] ?? "NO LLEGO",
    "todos_los_SERVER_con_AUTH" => array_filter($_SERVER, fn($k) => str_contains($k, "AUTH"), ARRAY_FILTER_USE_KEY),
]);
