<?php
// =================================
// * Filename : redeem.php
// * Date : 12/11/2026
// * Author : (add your name)
// * Description : POST {"reward_id": 1}: the user spends points on a reward
// =================================

require_once __DIR__ . "/../../config/Database.php";
require_once __DIR__ . "/../../controllers/DashboardControllers.php";
require_once __DIR__ . "/../../helpers/api.php";

$userId = requireUserApi();
requireMethod("POST");

$rewardId = (int) (readJson()["reward_id"] ?? 0);

$controller = new DashboardController($conn);
jsonResponse($controller->redeemReward($userId, $rewardId));