<?php
// =================================
// * Filename : join_challenge.php
// * Date : 12/11/2026
// * Author : (add your name)
// * Description : POST {"challenge_id": 1}: the user joins a challenge and the time limit starts
// =================================

require_once __DIR__ . "/../../config/Database.php";
require_once __DIR__ . "/../../controllers/DashboardControllers.php";
require_once __DIR__ . "/../../helpers/api.php";

$userId = requireUserApi();
requireMethod("POST");

$challengeId = (int) (readJson()["challenge_id"] ?? 0);

$controller = new DashboardController($conn);
jsonResponse($controller->joinChallenge($userId, $challengeId));