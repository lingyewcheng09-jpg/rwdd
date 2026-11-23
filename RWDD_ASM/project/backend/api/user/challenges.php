<?php

require_once __DIR__ . "/../../config/Database.php";
require_once __DIR__ . "/../../controllers/DashboardControllers.php";
require_once __DIR__ . "/../../helpers/common.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_POST["_method"] ?? $_SERVER["REQUEST_METHOD"];
$controllers = new DashboardController($conn);

header("Content-Type: application/json");

$userId = requireUser();

switch ($method) {

    case "GET":

        echo json_encode($controllers->getChallenges($userId));
        exit;

    case "POST":

        // join a challenge: {"challenge_id": 1}
        $input = json_decode(file_get_contents("php://input"), true);
        $challengeId = (int)($input["challenge_id"] ?? 0);

        echo json_encode($controllers->joinChallenge($userId, $challengeId));
        exit;

    default:

        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        exit;
}