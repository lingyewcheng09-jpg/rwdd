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

        echo json_encode($controllers->getRewards());
        exit;

    case "POST":

        // redeem a reward: {"reward_id": 1}
        $input = json_decode(file_get_contents("php://input"), true);
        $rewardId = (int)($input["reward_id"] ?? 0);

        echo json_encode($controllers->redeemReward($userId, $rewardId));
        exit;

    default:

        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        exit;
}