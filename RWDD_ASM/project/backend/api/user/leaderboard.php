<?php
// =================================
// * Filename : leaderboard.php
// * Date : 12/11/2026
// * Author : (add your name)
// * Description : GET: the top 20 users by XP and the logged-in user's own position
// =================================

require_once __DIR__ . "/../../config/Database.php";
require_once __DIR__ . "/../../controllers/DashboardControllers.php";
require_once __DIR__ . "/../../helpers/api.php";

$userId = requireUserApi();
requireMethod("GET");

$controller = new DashboardController($conn);
jsonResponse($controller->getLeaderboard($userId, 20));